<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\User;
use App\Services\Cart\CartException;
use Illuminate\Support\Collection;

class CouponService
{
    /** @throws CartException with a customer-safe message */
    public function validate(string $code, int $subtotal, ?User $user = null, ?string $email = null, ?Collection $lines = null): Coupon
    {
        $coupon = Coupon::query()->where('code', strtoupper(trim($code)))->first();
        if (! $coupon) {
            throw new CartException('This coupon code is not valid.');
        }

        return $this->check($coupon, $subtotal, $user, $email, $lines);
    }

    /** @throws CartException */
    public function check(Coupon $coupon, int $subtotal, ?User $user = null, ?string $email = null, ?Collection $lines = null): Coupon
    {
        if (! $coupon->is_active) {
            throw new CartException('This coupon code is not valid.');
        }
        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            throw new CartException('This coupon is not active yet.');
        }
        if ($coupon->expires_at && $coupon->expires_at->isPast()) {
            throw new CartException('This coupon has expired.');
        }
        if ($subtotal < $coupon->min_subtotal) {
            throw new CartException('Spend at least '.config('abiha.currency.symbol').' '.number_format($coupon->min_subtotal).' to use this coupon.');
        }
        if ($coupon->isRestricted() && ($lines === null || $this->eligibleSubtotal($coupon, $lines) <= 0)) {
            throw new CartException('This coupon does not apply to the items in your bag.');     // fails closed: no lines, no discount
        }
        if ($coupon->usage_limit !== null && $coupon->usages()->count() >= $coupon->usage_limit) {
            throw new CartException('This coupon has reached its usage limit.');
        }
        if ($coupon->per_customer_limit !== null && $this->customerUses($coupon, $user, $email) >= $coupon->per_customer_limit) {
            throw new CartException('You have already used this coupon the maximum number of times.');
        }

        return $coupon;
    }

    /**
     * The part of the bag this coupon may discount: the whole subtotal for an unrestricted coupon, otherwise only the lines whose product
     * is listed or whose category is listed (a listed category includes its sub-categories).
     *
     * @param  Collection<int, \App\Services\Cart\CartLine>  $lines
     */
    public function eligibleSubtotal(Coupon $coupon, Collection $lines): int
    {
        $productIds = $coupon->products()->pluck('products.id')->all();
        $categoryIds = $coupon->categories()->pluck('categories.id')->all();
        if ($productIds === [] && $categoryIds === []) {
            return (int) $lines->sum(fn ($l) => $l->total());
        }
        $allCategories = collect($categoryIds)->flatMap(fn ($id) => Category::find($id)?->descendantIds() ?? [$id])->unique()->all();

        return (int) $lines->filter(fn ($l) => in_array($l->product->id, $productIds, true) || ($l->product->category_id && in_array($l->product->category_id, $allCategories, true)))->sum(fn ($l) => $l->total());
    }

    /** How many times this customer (account or checkout email) redeemed the coupon. */
    public function customerUses(Coupon $coupon, ?User $user, ?string $email): int
    {
        if (! $user && ! $email) {
            return 0;
        }

        return CouponUsage::query()->where('coupon_id', $coupon->id)
            ->where(function ($q) use ($user, $email) {
                if ($user) {
                    $q->where('user_id', $user->id);
                }
                if ($email) {
                    $q->orWhereRaw('LOWER(email) = ?', [mb_strtolower($email)]);
                }
            })->count();
    }
}
