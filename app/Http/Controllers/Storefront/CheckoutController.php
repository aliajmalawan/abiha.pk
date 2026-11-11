<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Services\Cart\CartException;
use App\Services\Cart\CartService;
use App\Services\OrderService;
use App\Services\Payments\BankTransferGateway;
use App\Services\Payments\OnlinePaymentGateway;
use App\Services\Payments\PaymentManager;
use App\Services\Payments\PaymentService;
use App\Services\ShippingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly ShippingService $shipping,
        private readonly PaymentManager $payments,
        private readonly OrderService $orders,
    ) {
    }

    public function show(Request $request): View|RedirectResponse
    {
        $summary = $this->cart->summary($this->shipping->find($request->query('shipping')));
        if ($summary->isEmpty()) {
            return redirect()->route('cart')->with('status', 'Your bag is empty.');
        }
        if ($summary->notices()) {
            return redirect()->route('cart')->withErrors(['cart' => 'Please review your bag before checking out.']);
        }

        $user = $request->user();
        $address = $user?->addresses()->first();

        return view('storefront.checkout', [
            'summary' => $summary,
            'shippingMethods' => $this->shipping->methods(),
            'gateways' => $this->payments->enabled(),
            'prefill' => [
                'name' => old('name', $address?->name ?? $user?->name),
                'phone' => old('phone', $address?->phone ?? $user?->phone),
                'email' => old('email', $user?->email),
                'province' => old('province', $address?->province),
                'city' => old('city', $address?->city),
                'address' => old('address', $address?->address),
                'postal_code' => old('postal_code', $address?->postal_code),
            ],
        ]);
    }

    public function store(CheckoutRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $shipping = $this->shipping->find($data['shipping_method']);
        $gateway = $this->payments->find($data['payment_method']);

        if (! $shipping || ! $gateway) {
            return back()->withInput()->withErrors(['payment_method' => 'Please choose a valid shipping and payment method.']);
        }

        try {
            $order = $this->orders->place($data, $shipping, $gateway->code(), $request->user());
        } catch (CartException $e) {
            return redirect()->route('cart')->withInput()->withErrors(['cart' => $e->getMessage()]);
        }

        \App\Services\StaffNotifier::newOrder($order);
        \App\Services\OrderMailer::placed($order);              // the confirmation email (a mail failure never touches the order)

        if ($request->user() && $request->boolean('save_address') && ! $request->user()->addresses()->where('address', $data['address'])->exists()) {
            $request->user()->addresses()->create(['label' => 'Checkout', ...collect($data)->only(['name', 'phone', 'province', 'city', 'address', 'postal_code'])->all()]);
        }

        // Real online providers redirect to the provider's hosted checkout; the order stays unpaid until the verified webhook arrives.
        if ($gateway instanceof OnlinePaymentGateway) {
            try {
                return redirect()->away($gateway->initiate($order));
            } catch (\Throwable $e) {
                // The order is saved; only the hand-off failed. The customer can retry from the confirmation page.
                report($e);

                return redirect()->route('order.confirmation', ['order' => $order, 'token' => $order->access_token])->withErrors(['order' => 'We could not reach the payment provider. Your order is saved. Please try paying again.']);
            }
        }

        return redirect()->route('order.confirmation', ['order' => $order, 'token' => $order->access_token]);
    }

    /** Customer reports a bank transfer with a reference, a receipt photo/PDF, or both. Staff still confirm the money. */
    public function paymentReference(Request $request, Order $order, PaymentService $payments): RedirectResponse
    {
        $this->authorizeOrderView($request, $order);
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'min:3', 'max:120', 'required_without:proof'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096', 'required_without:reference'],
        ], [
            'reference.required_without' => 'Enter your bank reference or upload a photo of the receipt.',
            'proof.required_without' => 'Enter your bank reference or upload a photo of the receipt.',
            'proof.mimes' => 'The receipt must be a JPG, PNG or PDF file.',
            'proof.max' => 'The receipt must be 4 MB or smaller.',
            'proof.uploaded' => 'The receipt could not be uploaded. Please try a smaller file.',
        ]);

        try {
            $payments->submitTransferReference($order, $data['reference'] ?? null, $request->file('proof'));
            \App\Services\StaffNotifier::transferReported($order);
        } catch (CartException $e) {
            return back()->withErrors(['reference' => $e->getMessage()]);
        }

        return back()->with('status', 'Thanks. We will verify your transfer and update your order.');
    }

    /** Try a card payment again (it failed, or the customer closed the provider page). Only for unpaid online orders. */
    public function retryPayment(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrderView($request, $order);
        $gateway = $this->payments->online($order->payment_method);
        abort_unless($gateway && $gateway->isAvailable(), 404);

        if (! in_array($order->payment_status, ['unpaid', 'pending', 'failed'], true) || in_array($order->status, ['cancelled', 'returned'], true)) {
            return back()->withErrors(['order' => 'This order cannot be paid online any more.']);
        }

        return redirect()->away($gateway->initiate($order));
    }
    private function authorizeOrderView(Request $request, Order $order): void
    {
        $owner = $request->user() && $request->user()->id === $order->user_id;
        $given = $request->query('token') ?? $request->input('token');
        $token = is_string($given) && hash_equals($order->access_token, $given);   // an array/odd value is simply "no token"
        abort_unless($owner || $token, 404);
    }

    public function confirmation(Request $request, Order $order): View
    {
        $this->authorizeOrderView($request, $order);

        $order->load('items');

        return view('storefront.order-confirmation', [
            'order' => $order,
            'bankInstructions' => $order->payment_method === 'bank_transfer' ? app(BankTransferGateway::class)->instructions() : null,
        ]);
    }
}
