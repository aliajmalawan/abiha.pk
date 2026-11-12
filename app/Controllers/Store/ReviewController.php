<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Auth\Auth;
use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Catalog;
use App\Support\CatalogCache;

/** Customer reviews (port of the old Storefront\ReviewController + ReviewRequest). Route: auth + throttle:review. */
final class ReviewController extends Controller
{
    public function store(Request $request): Response
    {
        $product = Catalog::product((string) ($request->params['slug'] ?? ''), false);   // old route binding: no active check
        if (!$product) {
            throw new HttpException(404);
        }
        $data = $this->validate($request->post, [
            'rating' => 'required|integer|between:1,5',
            'title' => 'nullable|string|max:120',
            'body' => 'required|string|min:10|max:2000',
        ]);
        $user = Auth::user();
        $db = Database::get();

        $purchased = $db->value(
            "SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.product_id = ? AND o.user_id = ? AND o.status <> 'cancelled' LIMIT 1",
            [$product['id'], $user['id']]
        ) !== null;

        // One review per customer per product; goes live only after moderation.
        $existing = $db->value('SELECT id FROM reviews WHERE product_id = ? AND user_id = ?', [$product['id'], $user['id']]);
        $values = [(int) $data['rating'], $data['title'] ?? null, (string) $data['body'], (string) $user['name'], $purchased ? 1 : 0];
        if ($existing !== null) {
            $db->exec('UPDATE reviews SET rating = ?, title = ?, body = ?, name = ?, is_verified_purchase = ?, is_approved = 0, updated_at = ? WHERE id = ?', [...$values, now(), (int) $existing]);
        } else {
            $db->insert(
                'INSERT INTO reviews (rating, title, body, name, is_verified_purchase, is_approved, product_id, user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)',
                [...$values, $product['id'], $user['id'], now(), now()]
            );
        }
        CatalogCache::bump();

        return $this->back(route('product', $product), 'Thank you. Your review will appear once it has been approved.');
    }
}
