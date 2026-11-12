<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Cart;
use App\Services\Cart\CartException;

/** The bag page, the drawer fragment and the JSON/HTML cart actions (port of Storefront\CartController). */
final class CartController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('store/cart', ['summary' => Cart::summary()], ['title' => 'Your bag', 'noindex' => true]);
    }

    /** Server-rendered fragment shown inside the cart drawer (same totals logic as the cart page). */
    public function drawer(Request $request): Response
    {
        return $this->partial('store/partials/cart-drawer', ['summary' => Cart::summary()]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        try {
            Cart::add((int) $data['product_id'], $data['variant_id'] ?? null, (int) ($data['quantity'] ?? 1));
        } catch (CartException $e) {
            return $this->fail($request, $e->getMessage());
        }
        return $this->ok($request, 'Added to your bag.', $request->bool('buy_now') ? route('checkout') : null);
    }

    public function update(Request $request): Response
    {
        $data = $this->validate($request, ['quantity' => ['required', 'integer', 'min:0', 'max:20']]);
        try {
            Cart::update((string) ($request->params['key'] ?? ''), (int) $data['quantity']);
        } catch (CartException $e) {
            return $this->fail($request, $e->getMessage());
        }
        return $this->ok($request, null);
    }

    public function destroy(Request $request): Response
    {
        Cart::remove((string) ($request->params['key'] ?? ''));
        return $this->ok($request, 'Item removed.');
    }

    public function applyCoupon(Request $request): Response
    {
        if ($this->fromCheckout($request)) {
            Session::flash('old', $this->checkoutInput($request));
            $code = $request->input('code');
            if (!is_string($code) || trim($code) === '' || mb_strlen($code) > 40) {
                Session::flash('errors', ['coupon' => 'Enter a coupon code.']);
                return $this->redirect(route('checkout'));
            }
            try {
                Cart::applyCoupon($code);
            } catch (CartException $e) {
                Session::flash('errors', ['coupon' => $e->getMessage()]);
                return $this->redirect(route('checkout'));
            }
            return $this->redirect(route('checkout'), 'Coupon applied.');
        }

        $data = $this->validate($request, ['code' => ['required', 'string', 'max:40']]);
        try {
            Cart::applyCoupon((string) $data['code']);
        } catch (CartException $e) {
            return $this->fail($request, $e->getMessage(), 'coupon');
        }
        return $this->ok($request, 'Coupon applied.');
    }

    public function removeCoupon(Request $request): Response
    {
        Cart::removeCoupon();
        if ($this->fromCheckout($request)) {
            Session::flash('old', $this->checkoutInput($request));
            return $this->redirect(route('checkout'), 'Coupon removed.');
        }
        return $this->ok($request, 'Coupon removed.');
    }

    /** True when the coupon button was pressed on the checkout page, which posts the whole form so nothing typed is lost. */
    private function fromCheckout(Request $request): bool
    {
        return $request->input('from') === 'checkout' && !$this->expectsJson($request);
    }

    private function checkoutInput(Request $request): array
    {
        $in = $request->post;
        unset($in['_token'], $in['_method'], $in['code'], $in['from'], $in['password']);
        return array_filter($in, static fn ($v) => is_scalar($v) || $v === null);
    }

    private function expectsJson(Request $request): bool
    {
        return $request->wantsJson() || $request->ajax();
    }

    private function ok(Request $request, ?string $message, ?string $redirect = null): Response
    {
        if ($this->expectsJson($request)) {
            return $this->json(['ok' => true, 'message' => $message, 'count' => Cart::count(), 'redirect' => $redirect]);
        }
        return $redirect ? $this->redirect($redirect, $message) : $this->back(route('cart'), $message);
    }

    private function fail(Request $request, string $message, string $field = 'cart'): Response
    {
        if ($this->expectsJson($request)) {
            return $this->json(['ok' => false, 'message' => $message, 'count' => Cart::count()], 422);
        }
        Session::flash('errors', [$field => $message]);
        return $this->back(route('cart'));
    }
}
