<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Services\Catalog;
use App\Services\Navigation;
use App\Support\Paginator;
use App\Support\SchemaBuilder;
use App\Support\Seo;

/** Shop, category and collection listings (port of the old Storefront\ShopController). */
final class ShopController extends Controller
{
    public function index(Request $request): Response
    {
        $slug = $request->query['category'] ?? null;
        $category = is_string($slug) && $slug !== '' ? Catalog::category($slug) : null;   // ?category[]=x is not a slug
        if ($category && !(int) $category['is_active']) {
            $category = null;
        }
        // /shop?category=x is the same page as /category/x: send people (and search engines) to the one clean URL.
        if ($category) {
            $rest = $request->query;
            unset($rest['category']);
            return Response::redirect(route('category', ['slug' => $category['slug']] + $rest), 301);
        }
        return $this->render($request, null, null);
    }

    public function category(Request $request): Response
    {
        $category = Catalog::category((string) ($request->params['slug'] ?? ''));
        if (!$category || !(int) $category['is_active']) {
            throw new HttpException(404);
        }
        return $this->render($request, $category, null);
    }

    public function collection(Request $request): Response
    {
        $collection = Catalog::collection((string) ($request->params['slug'] ?? ''));
        if (!$collection || !(int) $collection['is_active']) {
            throw new HttpException(404);
        }
        return $this->render($request, null, $collection);
    }

    private function render(Request $request, ?array $category, ?array $collection): Response
    {
        $input = $this->validate($request->query, [
            'q' => 'nullable|string|max:100',
            'min' => 'nullable|integer|min:0',
            'max' => 'nullable|integer|min:0',
            'availability' => 'nullable|in:in_stock',
            'color' => 'nullable|array',
            'size' => 'nullable|array',
            'sale' => 'nullable|boolean',
            'sort' => 'nullable|in:' . implode(',', array_keys(Catalog::SORTS)),
        ]);
        foreach (['color', 'size'] as $k) {                         // 'color.*' => string|max:40
            foreach ((array) ($input[$k] ?? []) as $i => $v) {
                if (!is_string($v)) {
                    throw ValidationException::one("{$k}.{$i}", "The {$k}.{$i} field must be a string.");
                }
                if (mb_strlen($v) > 40) {
                    throw ValidationException::one("{$k}.{$i}", "The {$k}.{$i} field must not be greater than 40 characters.");
                }
            }
        }

        $page = Paginator::currentPage($request->query);
        $products = Catalog::listing($input, $category, $collection, $page)->withQueryString($request->query);
        $q = $input['q'] ?? null;
        $title = $collection['name'] ?? $category['name'] ?? ($q ? 'Search results' : 'Shop');

        $activeCount = 0;
        foreach (['min', 'max', 'availability', 'color', 'size', 'sale'] as $k) {
            $raw = $request->query[$k] ?? null;
            if (($input[$k] ?? null) !== null && (!empty($raw) || $raw === '0')) {
                $activeCount++;
            }
        }
        $filled = $request->query['category'] ?? null;
        $activeCount += $category && $filled !== null && $filled !== '' ? 1 : 0;

        // Breadcrumbs, social image and the ItemList; filtered views are noindex,follow (Seo decides from the query).
        $crumbs = [['Home', route('home')]];
        if ($collection) {
            $crumbs[] = ['Shop', route('shop')];
            $crumbs[] = [$collection['name'], ''];
        } elseif ($category) {
            $crumbs = array_merge($crumbs, SchemaBuilder::categoryTrail($category));
            $crumbs[array_key_last($crumbs)][1] = '';
        } else {
            $crumbs[] = [$title, ''];
        }
        $jsonld = [];
        if ($products->count()) {
            $items = [];
            foreach (array_values($products->items) as $i => $p) {
                $items[] = ['@type' => 'ListItem', 'position' => ($products->currentPage - 1) * $products->perPage + $i + 1, 'url' => route('product', $p)];
            }
            $jsonld[] = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $title, 'url' => Seo::canonicalUrl($request), 'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $items]];
        }

        return $this->view('store/shop', [
            'products' => $products,
            'category' => $category,
            'collection' => $collection,
            'title' => $title,
            'q' => $q,
            'sorts' => Catalog::SORTS,
            'filters' => $input,
            'categories' => Navigation::categories(),
            'colors' => Catalog::filterOptions('color'),
            'sizes' => array_column(Catalog::filterOptions('size'), 'value'),
            'priceCeiling' => Catalog::priceCeiling(),
            'activeCount' => $activeCount,
        ], [
            'title' => $title,
            'description' => ($category['meta_description'] ?? null) ?: (($category['description'] ?? null) ?: (($collection['description'] ?? null) ?: 'Browse the ' . cfg('abiha.name') . '® collection.')),
            'breadcrumbs' => $crumbs,
            'image' => ($category['image'] ?? null) ?: ($collection['image'] ?? null),
            'jsonld' => $jsonld,
        ]);
    }
}
