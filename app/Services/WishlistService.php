<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

/**
 * Guests keep a wishlist in the session; signed-in customers have a persistent one
 * (wishlists + wishlist_items). On login the guest list is merged into the account.
 */
class WishlistService
{
    private const KEY = 'wishlist.ids';

    public function __construct(private readonly Session $session, private readonly Guard $auth)
    {
    }

    /** @return array<int> */
    public function ids(): array
    {
        if ($user = $this->auth->user()) {
            $wishlist = $user->wishlist()->first();       // fresh query, not a cached relation

            return $wishlist ? $wishlist->items()->pluck('product_id')->map(fn ($i) => (int) $i)->all() : [];
        }

        return array_map('intval', $this->session->get(self::KEY, []));
    }

    public function has(int $productId): bool
    {
        return in_array($productId, $this->ids(), true);
    }

    /** @return bool true when added, false when removed */
    public function toggle(Product $product): bool
    {
        if ($user = $this->auth->user()) {
            $wishlist = $user->ensureWishlist();
            $existing = $wishlist->items()->where('product_id', $product->id)->first();
            if ($existing) {
                $existing->delete();

                return false;
            }
            $wishlist->items()->create(['product_id' => $product->id]);

            return true;
        }

        $ids = $this->ids();
        if (in_array($product->id, $ids, true)) {
            $this->session->put(self::KEY, array_values(array_diff($ids, [$product->id])));

            return false;
        }
        $this->session->put(self::KEY, [...$ids, $product->id]);

        return true;
    }

    public function remove(Product $product): void
    {
        if ($this->has($product->id)) {
            $this->toggle($product);
        }
    }

    /** @return Collection<int, Product> */
    public function products(): Collection
    {
        return Product::query()->active()->forCard()->whereIn('id', $this->ids())->get();
    }

    public function mergeIntoUser(User $user): void
    {
        $guestIds = array_map('intval', $this->session->pull(self::KEY, []));
        if (! $guestIds) {
            return;
        }
        $wishlist = $user->ensureWishlist();
        $have = $wishlist->items()->pluck('product_id')->all();
        foreach (Product::query()->active()->whereIn('id', $guestIds)->pluck('id') as $id) {
            if (! in_array($id, $have, true)) {
                $wishlist->items()->create(['product_id' => $id]);
            }
        }
    }
}
