<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Auth\Auth;
use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Catalog;
use App\Support\Paginator;
use App\Support\SchemaBuilder;
use App\Support\Seo;

/** Product page, quick view and live search suggestions (port of the old Storefront\ProductController). */
final class ProductController extends Controller
{
    private const RECENT_KEY = 'recently_viewed';

    public function show(Request $request): Response
    {
        $product = $this->activeProduct($request);
        $db = Database::get();
        $pid = (int) $product['id'];

        $related = $product['category_id']
            ? Catalog::cards('p.id <> ? AND p.category_id = ?', [$pid, $product['category_id']], 'RAND()', 4)
            : Catalog::cards('p.id <> ?', [$pid], 'RAND()', 4);

        // Recently viewed (session, most recent first, excluding the current product)
        $ids = array_values(array_filter(array_map('intval', (array) Session::get(self::RECENT_KEY, [])), static fn ($id) => $id !== $pid));
        array_unshift($ids, $pid);
        $ids = array_slice($ids, 0, 9);
        Session::put(self::RECENT_KEY, $ids);
        $others = array_slice($ids, 1);
        $recent = [];
        if ($others) {
            $recent = Catalog::cards('p.id IN ' . Database::in($others), $others);
            usort($recent, static fn ($a, $b) => array_search($a['id'], $ids, true) <=> array_search($b['id'], $ids, true));
        }

        $stats = Catalog::reviewStats($pid);
        $product['review_stats'] = $stats;
        $page = Paginator::currentPage($request->query, 'reviews_page');
        $rows = $db->all('SELECT * FROM reviews WHERE product_id = ? AND is_approved = 1 ORDER BY created_at DESC LIMIT 10 OFFSET ' . (($page - 1) * 10), [$pid]);
        $reviews = (new Paginator($rows, $stats['count'], 10, $page))->pageName('reviews_page')->fragment('reviews')->path(route('product', $product));
        $uid = Auth::id();
        $canReview = $uid !== null && $db->value('SELECT 1 FROM reviews WHERE product_id = ? AND user_id = ? LIMIT 1', [$pid, $uid]) === null;

        $trail = array_merge([['Home', route('home')]], SchemaBuilder::categoryTrail($product['category'] ? Catalog::category($product['category']['slug']) : null));
        $trail[] = [$product['name'], ''];

        return $this->view('store/product', [
            'product' => $product,
            'related' => $related,
            'recent' => $recent,
            'reviews' => $reviews,
            'ratingCounts' => $stats['stars'],
            'canReview' => $canReview,
            'deliveryInfo' => setting('delivery.info'),
            'returnPolicy' => setting('returns.policy'),
        ], [
            'title' => $product['meta_title'] ?: $product['name'],
            'description' => $product['meta_description'] ?: str_limit(strip_tags((string) ($product['short_description'] ?: $product['description'])), 155),
            'type' => 'product',
            'breadcrumbs' => $trail,
            'image' => Catalog::imagePath($product, 0),
            'jsonld' => [SchemaBuilder::product($product)],
            'noindex' => $product['is_sample'],   // demo (sample) products are never indexed
        ]);
    }

    public function quickView(Request $request): Response
    {
        return $this->partial('store/partials/quick-view', ['product' => $this->activeProduct($request)]);
    }

    /** Live search suggestions for the header search overlay. */
    public function suggest(Request $request): Response
    {
        $raw = $request->query['q'] ?? '';
        $q = trim(is_string($raw) ? $raw : '');
        if (mb_strlen($q) < 2) {
            return $this->json(['results' => []]);
        }
        [$where, $params] = Catalog::search($q);
        $results = array_map(static fn (array $p) => [
            'name' => $p['name'],
            'url' => route('product', $p),
            'image' => Catalog::imageUrl($p),
            'price' => cfg('abiha.currency.symbol') . ' ' . number_format($p['display_price']),
            'category' => $p['category']['name'] ?? null,
        ], Catalog::cards($where, $params, 'p.id ASC', 6));
        return $this->json(['results' => $results]);
    }

    private function activeProduct(Request $request): array
    {
        $product = Catalog::product((string) ($request->params['slug'] ?? ''));
        if (!$product) {
            throw new HttpException(404);
        }
        return $product;
    }
}
