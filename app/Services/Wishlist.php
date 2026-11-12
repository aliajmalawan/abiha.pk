<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Session;

/**
 * The wishlist (port of the old App\Services\WishlistService; tables wishlists / wishlist_items).
 *  - a signed-in user has ONE wishlists row (wishlists.user_id unique), items in wishlist_items;
 *  - a guest's wishlist is a list of product ids in the session under "wishlist_ids" (no database rows);
 *  - on login the guest ids are merged into the user's wishlist (active products only) and removed from the session
 *    (Wishlist::mergeGuest($userId), called by the login / register code).
 *
 *   Wishlist::ids() / has($id) / toggle($productId): bool (true = added) / remove($productId) / products() (cards)
 */
final class Wishlist
{
    /** Session key of the guest wishlist (Laravel used the nested key "wishlist.ids"). */
    public const SESSION_KEY = 'wishlist_ids';

    /** @return list<int> product ids on the current visitor's wishlist */
    public static function ids(): array
    {
        if ($uid = Auth::id()) {
            try {
                return array_map('intval', Database::get()->column(
                    'SELECT wi.product_id FROM wishlist_items wi JOIN wishlists w ON w.id = wi.wishlist_id WHERE w.user_id = ? ORDER BY wi.id',
                    [$uid]
                ));
            } catch (\Throwable) {
                return [];
            }
        }
        $ids = Session::get(self::SESSION_KEY, []);
        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    public static function has(int $productId): bool
    {
        return in_array($productId, self::ids(), true);
    }

    /** @return bool true when added, false when removed */
    public static function toggle(int $productId): bool
    {
        $db = Database::get();
        if ($uid = Auth::id()) {
            $wid = self::ensureFor($uid);
            $existing = $db->value('SELECT id FROM wishlist_items WHERE wishlist_id = ? AND product_id = ?', [$wid, $productId]);
            if ($existing !== null) {
                $db->exec('DELETE FROM wishlist_items WHERE id = ?', [(int) $existing]);
                return false;
            }
            $db->insert('INSERT INTO wishlist_items (wishlist_id, product_id, created_at, updated_at) VALUES (?, ?, ?, ?)', [$wid, $productId, now(), now()]);
            return true;
        }
        $ids = self::ids();
        if (in_array($productId, $ids, true)) {
            Session::put(self::SESSION_KEY, array_values(array_diff($ids, [$productId])));
            return false;
        }
        Session::put(self::SESSION_KEY, [...$ids, $productId]);
        return true;
    }

    public static function remove(int $productId): void
    {
        if (self::has($productId)) {
            self::toggle($productId);
        }
    }

    /** The saved products that are still for sale, as Catalog cards (newest product first, like the old query). */
    public static function products(): array
    {
        $ids = self::ids();
        if (!$ids) {
            return [];
        }
        return Catalog::cards('p.id IN ' . Database::in($ids), $ids, 'p.id ASC');
    }

    /** Moves the guest (session) wishlist into the user's wishlist; only active products are kept. */
    public static function mergeGuest(int $userId): void
    {
        $guest = Session::pull(self::SESSION_KEY, []);
        $guest = is_array($guest) ? array_values(array_unique(array_map('intval', $guest))) : [];
        if (!$guest) {
            return;
        }
        $db = Database::get();
        $wid = self::ensureFor($userId);
        $have = array_map('intval', $db->column('SELECT product_id FROM wishlist_items WHERE wishlist_id = ?', [$wid]));
        $active = array_map('intval', $db->column('SELECT id FROM products WHERE is_active = 1 AND deleted_at IS NULL AND id IN ' . Database::in($guest), $guest));
        foreach ($active as $id) {
            if (!in_array($id, $have, true)) {
                $db->insert('INSERT INTO wishlist_items (wishlist_id, product_id, created_at, updated_at) VALUES (?, ?, ?, ?)', [$wid, $id, now(), now()]);
            }
        }
    }

    /** The user's wishlists row id, created on first use (old User::ensureWishlist). */
    private static function ensureFor(int $userId): int
    {
        $db = Database::get();
        $id = $db->value('SELECT id FROM wishlists WHERE user_id = ?', [$userId]);
        if ($id !== null) {
            return (int) $id;
        }
        $db->exec('INSERT IGNORE INTO wishlists (user_id, created_at, updated_at) VALUES (?, ?, ?)', [$userId, now(), now()]);
        return (int) $db->value('SELECT id FROM wishlists WHERE user_id = ?', [$userId]);
    }
}
