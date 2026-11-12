<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Auth\Auth;
use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Uploads;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Services\Cart;
use App\Services\Cart\CartException;
use App\Services\OrderMailer;
use App\Services\OrderService;
use App\Services\Payments\BankTransferGateway;
use App\Services\Payments\OnlinePaymentGateway;
use App\Services\Payments\PaymentManager;
use App\Services\Payments\PaymentService;
use App\Services\ShippingService;
use App\Services\StaffNotifier;

/** Checkout, order confirmation, bank-transfer reference and card retry (port of Storefront\CheckoutController). */
final class CheckoutController extends Controller
{
    public function show(Request $request): Response
    {
        $q = $request->query['shipping'] ?? null;
        $summary = Cart::summary(ShippingService::find(is_string($q) ? $q : null));
        if ($summary['empty']) {
            return $this->redirect(route('cart'), 'Your bag is empty.');
        }
        if ($summary['notices']) {
            Session::flash('errors', ['cart' => 'Please review your bag before checking out.']);
            return $this->redirect(route('cart'));
        }
        $user = Auth::user();
        $address = $user ? Database::get()->one('SELECT * FROM addresses WHERE user_id = ? ORDER BY id LIMIT 1', [$user['id']]) : null;
        $prefill = [
            'name' => old('name', $address['name'] ?? $user['name'] ?? null),
            'phone' => old('phone', $address['phone'] ?? $user['phone'] ?? null),
            'email' => old('email', $user['email'] ?? null),
            'province' => old('province', $address['province'] ?? null),
            'city' => old('city', $address['city'] ?? null),
            'address' => old('address', $address['address'] ?? null),
            'postal_code' => old('postal_code', $address['postal_code'] ?? null),
        ];
        return $this->view('store/checkout', [
            'summary' => $summary,
            'shippingMethods' => ShippingService::methods(),
            'gateways' => PaymentManager::enabled(),
            'prefill' => array_map(static fn ($v) => is_scalar($v) ? (string) $v : null, $prefill),
        ], ['title' => 'Checkout', 'noindex' => true]);
    }

    public function store(Request $request): Response
    {
        $input = $request->post;
        // A valid Pakistani number is stored in one shape (03XXXXXXXXX); anything else is left as typed so the rule rejects it.
        if (Validator::pakistaniPhone($input['phone'] ?? null)) {
            $input['phone'] = Validator::normalizePhone($input['phone']);
        }
        $data = $this->validate($input, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'pk_phone'],
            'email' => ['required', 'email', 'max:190'],
            'province' => ['required', 'in:' . implode(',', (array) cfg('abiha.provinces'))],
            'city' => ['required', 'string', 'min:2', 'max:80'],
            'address' => ['required', 'string', 'min:10', 'max:500'],
            'postal_code' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9\- ]+$/'],
            'notes' => ['nullable', 'string', 'max:500'],
            'shipping_method' => ['required', 'string', 'exists:shipping_methods,code'],
            'payment_method' => ['required', 'string'],
            'save_address' => ['nullable', 'boolean'],
        ], ['address.min' => 'Please enter your complete address (house, street, area).']);

        $shipping = ShippingService::find((string) $data['shipping_method']);
        $gateway = PaymentManager::find((string) $data['payment_method']);
        if (!$shipping || !$gateway) {
            throw ValidationException::one('payment_method', 'Please choose a valid shipping and payment method.');
        }

        $user = Auth::user();
        try {
            $order = OrderService::place($data, $shipping, $gateway->code(), $user, $request->ip());
        } catch (CartException $e) {
            Session::flash('errors', ['cart' => $e->getMessage()]);
            Session::flash('old', array_filter($request->post, static fn ($v, $k) => (is_scalar($v) || $v === null) && !in_array($k, ['_token', '_method', 'password'], true), ARRAY_FILTER_USE_BOTH));
            return $this->redirect(route('cart'));
        }

        StaffNotifier::newOrder($order);
        OrderMailer::placed($order);              // the confirmation email (a mail failure never touches the order)

        if ($user && !empty($data['save_address'])
            && !Database::get()->value('SELECT 1 FROM addresses WHERE user_id = ? AND address = ? LIMIT 1', [$user['id'], $data['address']])) {
            Database::get()->insert('INSERT INTO addresses (user_id, label, name, phone, province, city, address, postal_code, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$user['id'], 'Checkout', $data['name'], $data['phone'], $data['province'], $data['city'], $data['address'], $data['postal_code'] ?: null, now(), now()]);
        }

        $confirmation = route('order.confirmation', ['number' => $order['number'], 'token' => $order['access_token']]);
        // Real online providers redirect to the provider's hosted checkout; the order stays unpaid until the verified webhook arrives.
        if ($gateway instanceof OnlinePaymentGateway) {
            try {
                return Response::redirect($gateway->initiate($order));
            } catch (\Throwable $e) {
                // The order is saved; only the hand-off failed. The customer can retry from the confirmation page.
                Logger::error('Payment hand-off failed', ['order' => $order['number'], 'error' => $e->getMessage()]);
                Session::flash('errors', ['order' => 'We could not reach the payment provider. Your order is saved. Please try paying again.']);
                return $this->redirect($confirmation);
            }
        }
        return $this->redirect($confirmation);
    }

    public function confirmation(Request $request): Response
    {
        $order = $this->authorizedOrder($request);
        return $this->view('store/order-confirmation', [
            'order' => $order,
            'token' => is_string($request->input('token')) ? $request->input('token') : null,
            'bankInstructions' => $order['payment_method'] === 'bank_transfer' ? (new BankTransferGateway())->instructions() : null,
        ], ['title' => 'Order confirmed', 'noindex' => true]);
    }

    /** Customer reports a bank transfer with a reference, a receipt photo/PDF, or both. Staff still confirm the money. */
    public function paymentReference(Request $request): Response
    {
        $order = $this->authorizedOrder($request);
        $back = route('order.confirmation', ['number' => $order['number'], 'token' => is_string($request->input('token')) ? $request->input('token') : null]);
        $proof = $request->file('proof');
        $required = 'Enter your bank reference or upload a photo of the receipt.';
        $fail = static fn (string $field, string $msg) => ValidationException::one($field, $msg)->redirectTo($back);

        $ref = $request->input('reference');
        $ref = is_string($ref) && trim($ref) !== '' ? trim($ref) : null;
        if ($ref === null && !$proof) {
            throw ValidationException::withMessages(['reference' => $required, 'proof' => $required])->redirectTo($back);
        }
        if ($ref !== null && (mb_strlen($ref) < 3 || mb_strlen($ref) > 120)) {
            throw $fail('reference', mb_strlen($ref) < 3 ? 'The reference field must be at least 3 characters.' : 'The reference field must not be greater than 120 characters.');
        }
        if ($proof) {
            if ((int) ($proof['error'] ?? 0) !== UPLOAD_ERR_OK) {
                throw $fail('proof', in_array((int) $proof['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The receipt must be 4 MB or smaller.' : 'The receipt could not be uploaded. Please try a smaller file.');
            }
            $tmp = (string) ($proof['tmp_name'] ?? '');
            if ($tmp === '' || !is_file($tmp)) {
                throw $fail('proof', 'The receipt could not be uploaded. Please try a smaller file.');
            }
            if ((filesize($tmp) ?: 0) > PaymentService::PROOF_MAX_BYTES) {
                throw $fail('proof', 'The receipt must be 4 MB or smaller.');
            }
            $ext = strtolower(pathinfo((string) ($proof['name'] ?? ''), PATHINFO_EXTENSION));
            if (!isset(PaymentService::PROOF_TYPES[Uploads::mime($tmp)]) || !in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true)) {
                throw $fail('proof', 'The receipt must be a JPG, PNG or PDF file.');
            }
        }

        try {
            PaymentService::submitTransferReference($order, $ref, $proof);
            StaffNotifier::transferReported($order);
        } catch (CartException $e) {
            throw $fail('reference', $e->getMessage());
        }
        return $this->redirect($back, 'Thanks. We will verify your transfer and update your order.');
    }

    /** Try a card payment again (it failed, or the customer closed the provider page). Only for unpaid online orders. */
    public function retryPayment(Request $request): Response
    {
        $order = $this->authorizedOrder($request);
        $gateway = PaymentManager::online((string) $order['payment_method']);
        if (!$gateway || !$gateway->isAvailable()) {
            throw new HttpException(404);
        }
        if (!in_array($order['payment_status'], ['unpaid', 'pending', 'failed'], true) || in_array($order['status'], ['cancelled', 'returned'], true)) {
            Session::flash('errors', ['order' => 'This order cannot be paid online any more.']);
            return $this->back(route('order.confirmation', ['number' => $order['number'], 'token' => $request->input('token')]));
        }
        return Response::redirect($gateway->initiate($order));
    }

    /** The order of the {number} route, visible to its signed-in owner or with its private access token; else 404. */
    private function authorizedOrder(Request $request): array
    {
        $order = OrderService::findByNumber((string) ($request->params['number'] ?? ''));
        if (!$order) {
            throw new HttpException(404);
        }
        $owner = Auth::id() !== null && $order['user_id'] !== null && Auth::id() === (int) $order['user_id'];
        $given = $request->query['token'] ?? $request->post['token'] ?? null;
        $token = is_string($given) && hash_equals((string) $order['access_token'], $given);   // an array/odd value is simply "no token"
        if (!$owner && !$token) {
            throw new HttpException(404);
        }
        return $order;
    }
}
