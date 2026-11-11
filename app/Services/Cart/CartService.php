<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\CouponService;
use App\Services\ShippingService;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Database-backed cart. Signed-in customers have one persistent cart (follows them across devices);
 * guests get a cart identified by an opaque token in their session, merged into the account on login.
 *
 * cart_items store ONLY {product, variant, quantity}. Prices, stock and availability are re-read from the
 * catalogue on every call, so the browser can never influence a price or total.
 */
class CartService
{
    private const TOKEN_KEY = 'cart.token';
    private const MAX_PER_LINE = 20;

    /** @var array{0: string, 1: ?Cart}|null identity => cart cache for this request */
    private ?array $cache = null;

    public function __construct(
        private readonly Session $session,
        private readonly Guard $auth,
        private readonly CouponService $coupons,
        private readonly ShippingService $shipping,
    ) {
    }

    /* ---------------- mutations ---------------- */

    public function add(Product $product, ?int $variantId, int $qty): void
    {
        $qty = max(1, $qty);
        [$product, $variant] = $this->resolve($product->id, $variantId);
        $available = $this->availableStock($product, $variant);

        if ($available <= 0) {
            throw new CartException('Sorry, this item is out of stock.');
        }

        $cart = $this->cart(true);
        $item = $this->findItem($cart, $product->id, $variant?->id);
        $current = $item?->quantity ?? 0;

        if ($current + $qty > $available) {
            throw new CartException($available > $current
                ? "Only {$available} available. You already have {$current} in your bag."
                : "You already have all {$available} available in your bag.");
        }

        $quantity = min(self::MAX_PER_LINE, $current + $qty);
        $item ? $item->update(['quantity' => $quantity]) : $cart->items()->create(['product_id' => $product->id, 'variant_id' => $variant?->id, 'quantity' => $quantity]);
        $cart->touch();
    }

    public function update(string $key, int $qty): void
    {
        $item = $this->itemByKey($key);
        if (! $item) {
            return;
        }
        if ($qty <= 0) {
            $item->delete();

            return;
        }

        [$product, $variant] = $this->resolve($item->product_id, $item->variant_id);
        $available = $this->availableStock($product, $variant);
        if ($qty > $available) {
            throw new CartException("Only {$available} available.");
        }
        $item->update(['quantity' => min($qty, self::MAX_PER_LINE)]);
    }

    public function remove(string $key): void
    {
        $this->itemByKey($key)?->delete();
    }

    public function clear(): void
    {
        if ($cart = $this->cart()) {
            $cart->items()->delete();
            $cart->update(['coupon_id' => null]);
        }
    }

    public function applyCoupon(string $code): void
    {
        $cart = $this->cart(true);
        $lines = $this->lines();
        $coupon = $this->coupons->validate($code, $this->subtotal($lines), $this->auth->user(), null, $lines);
        $cart->update(['coupon_id' => $coupon->id]);
        $cart->unsetRelation('coupon');
    }

    public function removeCoupon(): void
    {
        $this->cart()?->update(['coupon_id' => null]);
    }

    /** Fold the guest cart into the customer's cart on login/registration (quantities capped to stock). */
    public function mergeGuestInto(User $user): void
    {
        $token = $this->session->pull(self::TOKEN_KEY);
        $this->cache = null;
        $guest = $token ? Cart::query()->where('token', $token)->whereNull('user_id')->with('items')->first() : null;
        if (! $guest) {
            return;
        }

        $mine = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        foreach ($guest->items as $row) {
            try {
                [$product, $variant] = $this->resolve($row->product_id, $row->variant_id);
            } catch (CartException) {
                continue;
            }
            $stock = $this->availableStock($product, $variant);
            $existing = $this->findItem($mine, $row->product_id, $row->variant_id);
            $qty = min(self::MAX_PER_LINE, $stock, ($existing?->quantity ?? 0) + $row->quantity);
            if ($qty < 1) {
                continue;
            }
            $existing ? $existing->update(['quantity' => $qty]) : $mine->items()->create(['product_id' => $row->product_id, 'variant_id' => $row->variant_id, 'quantity' => $qty]);
        }
        if ($guest->coupon_id && ! $mine->coupon_id) {
            $mine->update(['coupon_id' => $guest->coupon_id]);
        }
        $guest->delete();
    }

    /* ---------------- reads ---------------- */

    /** Cheap count for the header badge. */
    public function count(): int
    {
        $cart = $this->cart();

        return $cart ? (int) $cart->items()->sum('quantity') : 0;
    }

    /** @return Collection<int, CartLine> */
    public function lines(): Collection
    {
        $cart = $this->cart();
        if (! $cart) {
            return collect();
        }

        $items = $cart->items()->get();
        if ($items->isEmpty()) {
            return collect();
        }

        $products = Product::query()->active()->with(['images', 'variants'])->whereIn('id', $items->pluck('product_id')->unique())->get()->keyBy('id');

        $lines = collect();
        foreach ($items as $item) {
            $product = $products->get($item->product_id);
            $variant = $item->variant_id ? $product?->variants->firstWhere('id', $item->variant_id) : null;

            // Product removed/deactivated, or variant gone → drop from the bag
            if (! $product || ($item->variant_id && ! $variant) || ($product->has_variants && ! $variant)) {
                $item->delete();

                continue;
            }
            $variant?->setRelation('product', $product);

            $stock = $this->availableStock($product, $variant);
            $qty = (int) $item->quantity;
            $notice = null;
            if ($stock <= 0) {
                $notice = 'No longer in stock. Remove it to continue.';
            } elseif ($qty > $stock) {
                $qty = $stock;
                $notice = "Quantity reduced to {$stock} (all that is in stock).";
                $item->update(['quantity' => $qty]);
            }

            $lines->push(new CartLine(
                $item->key_string, $product, $variant, $qty,
                $variant ? $variant->effective_price : $product->price,
                $variant ? $variant->effective_compare_price : ($product->compare_price > $product->price ? $product->compare_price : null),
                $stock, $notice,
            ));
        }

        return $lines;
    }

    /** Authoritative totals. $email lets per-customer coupon limits apply to guests at checkout. */
    /** $destination = ['province' => …, 'city' => …] enables city-wise shipping rules (cart pages use the default rate). */
    public function summary(?ShippingMethod $method = null, ?string $email = null, ?array $destination = null): CartSummary
    {
        $lines = $this->lines();
        $subtotal = $this->subtotal($lines);
        $cart = $this->cart();

        $coupon = null;
        $couponError = null;
        $discount = 0;
        if ($cart?->coupon_id && ($candidate = $cart->coupon)) {
            try {
                $coupon = $this->coupons->check($candidate, $subtotal, $this->auth->user(), $email, $lines);
                $discount = $coupon->discountFor($this->coupons->eligibleSubtotal($coupon, $lines));
            } catch (CartException $e) {
                $couponError = $e->getMessage();       // e.g. subtotal dropped below the minimum, limit reached
                $this->removeCoupon();
            }
        }

        $method ??= $this->shipping->default();
        $afterDiscount = $subtotal - $discount;
        $quote = ($lines->isNotEmpty() && $method) ? $this->shipping->quote($method, $afterDiscount, $destination['province'] ?? null, $destination['city'] ?? null) : 0;
        $available = $quote !== null;
        $shipping = $quote ?? 0;

        return new CartSummary($lines, $subtotal, $discount, $shipping, $afterDiscount + $shipping, $coupon, $method, $couponError, $available);
    }

    /* ---------------- helpers ---------------- */

    private function cart(bool $create = false): ?Cart
    {
        $user = $this->auth->user();
        $token = $this->session->get(self::TOKEN_KEY);
        $identity = $user ? 'u'.$user->id : 't'.($token ?? '');

        if ($this->cache && $this->cache[0] === $identity && ($this->cache[1] || ! $create)) {
            return $this->cache[1];
        }

        if ($user) {
            $cart = $create ? Cart::query()->firstOrCreate(['user_id' => $user->id]) : Cart::query()->where('user_id', $user->id)->first();
        } else {
            $cart = $token ? Cart::query()->where('token', $token)->whereNull('user_id')->first() : null;
            if (! $cart && $create) {
                $token = Str::random(40);
                $this->session->put(self::TOKEN_KEY, $token);
                $cart = Cart::query()->create(['token' => $token]);
                $identity = 't'.$token;
            }
        }

        $this->cache = [$identity, $cart];

        return $cart;
    }

    private function findItem(Cart $cart, int $productId, ?int $variantId): ?CartItem
    {
        return $cart->items()->where('product_id', $productId)->where('variant_id', $variantId)->first();
    }

    private function itemByKey(string $key): ?CartItem
    {
        [$productId, $variantId] = array_pad(explode('-', $key, 2), 2, 0);
        $cart = $this->cart();

        return $cart ? $this->findItem($cart, (int) $productId, ((int) $variantId) ?: null) : null;
    }

    private function subtotal(Collection $lines): int
    {
        return (int) $lines->sum(fn (CartLine $l) => $l->stock > 0 ? $l->total() : 0);
    }

    private function availableStock(Product $product, ?ProductVariant $variant): int
    {
        return max(0, (int) ($variant ? $variant->stock : $product->stock));
    }

    /** @return array{0: Product, 1: ?ProductVariant} */
    private function resolve(int $productId, ?int $variantId): array
    {
        $product = Product::query()->active()->with('variants')->find($productId);
        if (! $product) {
            throw new CartException('This product is no longer available.');
        }

        if ($product->has_variants) {
            $variant = $variantId ? $product->variants->firstWhere('id', $variantId) : null;
            if (! $variant) {
                throw new CartException('Please choose a size and colour.');
            }
            $variant->setRelation('product', $product);

            return [$product, $variant];
        }

        return [$product, null];
    }
}
