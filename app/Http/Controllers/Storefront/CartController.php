<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Cart\CartException;
use App\Services\Cart\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cart)
    {
    }

    public function index(): View
    {
        return view('storefront.cart', ['summary' => $this->cart->summary()]);
    }

    /** Server-rendered fragment shown inside the cart drawer (same totals logic as the cart page). */
    public function drawer(): View
    {
        return view('storefront.partials.cart-drawer', ['summary' => $this->cart->summary()]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        try {
            $this->cart->add(Product::findOrFail($data['product_id']), $data['variant_id'] ?? null, $data['quantity'] ?? 1);
        } catch (CartException $e) {
            return $this->fail($request, $e->getMessage());
        }

        return $this->ok($request, 'Added to your bag.', $request->boolean('buy_now') ? route('checkout') : null);
    }

    public function update(Request $request, string $key): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:20']]);
        try {
            $this->cart->update($key, $data['quantity']);
        } catch (CartException $e) {
            return $this->fail($request, $e->getMessage());
        }

        return $this->ok($request, null);
    }

    public function destroy(Request $request, string $key): JsonResponse|RedirectResponse
    {
        $this->cart->remove($key);

        return $this->ok($request, 'Item removed.');
    }

    public function applyCoupon(Request $request): JsonResponse|RedirectResponse
    {
        if ($this->fromCheckout($request)) {
            $back = redirect()->route('checkout')->withInput($this->checkoutInput($request));
            $code = $request->input('code');
            if (! is_string($code) || trim($code) === '' || mb_strlen($code) > 40) {
                return $back->withErrors(['coupon' => 'Enter a coupon code.']);
            }
            try {
                $this->cart->applyCoupon($code);
            } catch (CartException $e) {
                return $back->withErrors(['coupon' => $e->getMessage()]);
            }

            return $back->with('status', 'Coupon applied.');
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        try {
            $this->cart->applyCoupon($data['code']);
        } catch (CartException $e) {
            return $this->fail($request, $e->getMessage(), 'coupon');
        }

        return $this->ok($request, 'Coupon applied.');
    }

    public function removeCoupon(Request $request): JsonResponse|RedirectResponse
    {
        $this->cart->removeCoupon();

        if ($this->fromCheckout($request)) {
            return redirect()->route('checkout')->withInput($this->checkoutInput($request))->with('status', 'Coupon removed.');
        }

        return $this->ok($request, 'Coupon removed.');
    }

    /** True when the coupon button was pressed on the checkout page, which posts the whole form so nothing typed is lost. */
    private function fromCheckout(Request $request): bool
    {
        return $request->input('from') === 'checkout' && ! $request->expectsJson();
    }

    private function checkoutInput(Request $request): array
    {
        return $request->except(['_token', '_method', 'code', 'from', 'password']);
    }

    private function ok(Request $request, ?string $message, ?string $redirect = null): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message, 'count' => $this->cart->count(), 'redirect' => $redirect]);
        }

        return ($redirect ? redirect($redirect) : back())->with('status', $message);
    }

    private function fail(Request $request, string $message, string $field = 'cart'): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'message' => $message, 'count' => $this->cart->count()], 422);
        }

        return back()->withErrors([$field => $message]);
    }
}
