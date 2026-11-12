<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Support\CatalogCache;
use App\Support\Paginator;

/**
 * The storefront catalogue (port of the old Product / ProductVariant / Category / Collection models, their scopes and
 * accessors, and the listing query of ShopController). Every product handed to a view is an array "card":
 *
 *   the products row (ints cast) + 'images' (list of product_images rows, sort_order, id), 'variants' (ACTIVE variants,
 *   sort_order, id, each with 'options', 'size', 'color', 'color_hex', 'effective_price', 'effective_compare_price',
 *   'label'), 'category' ([id, name, slug] or null) and the old accessors precomputed: 'display_price',
 *   'display_compare_price', 'price_varies', 'is_on_sale', 'discount_percent', 'total_stock', 'in_stock',
 *   'badge_label', 'sizes', 'colors'.
 *
 * Public API (other slices may call it):
 *   Catalog::hydrate($rows)                      rows of `products` -> cards (one query per relation, no N+1)
 *   Catalog::cards($sqlWhere, $params, $order, $limit)   active, non-deleted products as cards
 *   Catalog::product($slug, $activeOnly)         a card by slug (+ decoded 'specifications'), or null
 *   Catalog::productById($id)                    a card by id (active or not, deleted excluded), or null
 *   Catalog::listing($filters, $category, $collection, $page, $perPage)   Paginator of cards (shop / category / collection)
 *   Catalog::category($slug) / collection($slug) the row (soft-deleted excluded), or null
 *   Catalog::descendantIds($categoryId)          the category and every descendant (old Category::descendantIds)
 *   Catalog::filterOptions('color'|'size')       attribute values used by an active variant, admin order (cached)
 *   Catalog::priceCeiling()                      highest active product price (cached)
 *   Catalog::reviewStats($productId)             ['count', 'average', 'stars' => [rating => n]] of approved reviews
 *   Catalog::variantPayload($card)               data for the Alpine productForm component
 *   Catalog::search($term)                       [sql, params] of the old Product::scopeSearch
 */
final class Catalog
{
    /** SQL condition shared by every storefront product query (Product::active() + SoftDeletes). */
    public const ACTIVE = 'p.is_active = 1 AND p.deleted_at IS NULL';

    public const SORTS = [
        'featured' => 'Featured',
        'newest' => 'Latest',
        'popular' => 'Popularity',
        'price_asc' => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'name' => 'Name A–Z',
    ];

    /* ------------------------------------------------------------------ loading */

    /**
     * Active, non-deleted products as cards.
     *
     * @param string $where extra SQL condition on alias p (e.g. 'p.is_new = 1'), '' for none
     */
    public static function cards(string $where = '', array $params = [], string $order = 'p.id DESC', ?int $limit = null, int $offset = 0): array
    {
        $sql = 'SELECT p.* FROM products p WHERE ' . self::ACTIVE . ($where !== '' ? ' AND (' . $where . ')' : '')
            . ' ORDER BY ' . $order . ($limit !== null ? ' LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset) : '');
        return self::hydrate(Database::get()->all($sql, $params));
    }

    /** A product by slug (soft-deleted excluded); $activeOnly = false is the old route-model binding without the active check. */
    public static function product(string $slug, bool $activeOnly = true): ?array
    {
        $row = Database::get()->one('SELECT p.* FROM products p WHERE p.slug = ? AND p.deleted_at IS NULL' . ($activeOnly ? ' AND p.is_active = 1' : '') . ' LIMIT 1', [$slug]);
        return $row ? self::hydrate([$row])[0] : null;
    }

    public static function productById(int $id): ?array
    {
        $row = Database::get()->one('SELECT p.* FROM products p WHERE p.id = ? AND p.deleted_at IS NULL LIMIT 1', [$id]);
        return $row ? self::hydrate([$row])[0] : null;
    }

    /**
     * Attaches images, active variants (with their attribute values) and the category, and computes the old accessors.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function hydrate(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $db = Database::get();
        $ids = array_values(array_unique(array_map(static fn ($r) => (int) $r['id'], $rows)));
        $in = Database::in($ids);

        $images = [];
        foreach ($db->all("SELECT id, product_id, path, alt, sort_order FROM product_images WHERE product_id IN {$in} ORDER BY sort_order, id", $ids) as $i) {
            $images[(int) $i['product_id']][] = ['id' => (int) $i['id'], 'path' => (string) $i['path'], 'alt' => $i['alt'], 'sort_order' => (int) $i['sort_order']];
        }

        $variants = [];
        $vrows = $db->all("SELECT * FROM product_variants WHERE is_active = 1 AND product_id IN {$in} ORDER BY sort_order, id", $ids);
        if ($vrows) {
            $vids = array_map(static fn ($v) => (int) $v['id'], $vrows);
            $opts = [];
            foreach ($db->all(
                'SELECT j.product_variant_id AS vid, av.id, av.value, av.color_hex, a.slug AS attribute, a.name AS attribute_name, a.sort_order AS attribute_sort
                 FROM attribute_value_product_variant j JOIN attribute_values av ON av.id = j.attribute_value_id
                 LEFT JOIN attributes a ON a.id = av.attribute_id WHERE j.product_variant_id IN ' . Database::in($vids) . ' ORDER BY av.id',
                $vids
            ) as $o) {
                $opts[(int) $o['vid']][] = ['id' => (int) $o['id'], 'value' => (string) $o['value'], 'color_hex' => $o['color_hex'], 'attribute' => $o['attribute'], 'attribute_name' => $o['attribute_name'], 'attribute_sort' => (int) $o['attribute_sort']];
            }
            foreach ($vrows as $v) {
                $v['id'] = (int) $v['id'];
                $v['product_id'] = (int) $v['product_id'];
                $v['price'] = $v['price'] === null ? null : (int) $v['price'];
                $v['compare_price'] = $v['compare_price'] === null ? null : (int) $v['compare_price'];
                $v['stock'] = (int) $v['stock'];
                $v['is_active'] = (bool) $v['is_active'];
                $v['options'] = $opts[$v['id']] ?? [];
                $variants[$v['product_id']][] = $v;
            }
        }

        $catIds = array_values(array_unique(array_filter(array_map(static fn ($r) => (int) ($r['category_id'] ?? 0), $rows))));
        $cats = [];
        if ($catIds) {
            foreach ($db->all('SELECT id, name, slug, parent_id FROM categories WHERE deleted_at IS NULL AND id IN ' . Database::in($catIds), $catIds) as $c) {
                $cats[(int) $c['id']] = ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'slug' => (string) $c['slug'], 'parent_id' => $c['parent_id'] === null ? null : (int) $c['parent_id']];
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $p = self::castProduct($r);
            $p['images'] = $images[$p['id']] ?? [];
            $p['category'] = $p['category_id'] !== null ? ($cats[$p['category_id']] ?? null) : null;
            $p['variants'] = array_map(static fn ($v) => self::variantFacts($v, $p), $variants[$p['id']] ?? []);
            $out[] = self::computed($p);
        }
        return $out;
    }

    private static function castProduct(array $r): array
    {
        foreach (['id', 'price', 'stock', 'low_stock_threshold', 'sold_count'] as $k) {
            if (array_key_exists($k, $r)) {
                $r[$k] = (int) $r[$k];
            }
        }
        foreach (['has_variants', 'is_active', 'is_featured', 'is_new', 'is_bestseller', 'is_trending', 'is_sample'] as $k) {
            if (array_key_exists($k, $r)) {
                $r[$k] = (bool) $r[$k];
            }
        }
        $r['compare_price'] = isset($r['compare_price']) ? (int) $r['compare_price'] : null;
        $r['category_id'] = isset($r['category_id']) ? (int) $r['category_id'] : null;
        $spec = $r['specifications'] ?? null;
        $r['specifications'] = is_string($spec) && $spec !== '' ? (json_decode($spec, true) ?: null) : (is_array($spec) ? $spec : null);
        return $r;
    }

    /** ProductVariant accessors: option values, effective prices, "Size: M / Colour: Black". */
    private static function variantFacts(array $v, array $p): array
    {
        $by = static function (string $slug) use ($v): ?array {
            foreach ($v['options'] as $o) {
                if ($o['attribute'] === $slug) {
                    return $o;
                }
            }
            return null;
        };
        $v['size'] = $by('size')['value'] ?? null;
        $color = $by('color');
        $v['color'] = $color['value'] ?? null;
        $v['color_hex'] = $color['color_hex'] ?? null;
        $v['effective_price'] = $v['price'] ?? $p['price'];
        $compare = $v['compare_price'] ?? ($v['price'] === null ? $p['compare_price'] : null);
        $v['effective_compare_price'] = $compare && $compare > $v['effective_price'] ? $compare : null;
        $opts = $v['options'];
        usort($opts, static fn ($a, $b) => $a['attribute_sort'] <=> $b['attribute_sort']);
        $v['label'] = implode(' / ', array_map(static fn ($o) => ($o['attribute_name'] ?? 'Option') . ': ' . $o['value'], $opts));
        return $v;
    }

    /** The old Product accessors (display price, sale, stock, badge, sizes, colours). */
    private static function computed(array $p): array
    {
        $vs = $p['variants'];
        $useVariants = $p['has_variants'] && $vs;
        if ($useVariants) {
            $cheapest = $vs[0];
            foreach ($vs as $v) {
                if ($v['effective_price'] < $cheapest['effective_price']) {
                    $cheapest = $v;
                }
            }
            $p['display_price'] = (int) $cheapest['effective_price'];
            $p['display_compare_price'] = $cheapest['effective_compare_price'];
        } else {
            $p['display_price'] = $p['price'];
            $p['display_compare_price'] = $p['compare_price'] && $p['compare_price'] > $p['price'] ? $p['compare_price'] : null;
        }
        $p['price_varies'] = $p['has_variants'] && count(array_unique(array_map(static fn ($v) => $v['effective_price'], $vs))) > 1;
        $p['is_on_sale'] = $p['display_compare_price'] !== null && $p['display_compare_price'] > $p['display_price'];
        $p['discount_percent'] = $p['is_on_sale'] ? (int) round((1 - $p['display_price'] / $p['display_compare_price']) * 100) : 0;
        $p['total_stock'] = $p['has_variants'] ? (int) array_sum(array_map(static fn ($v) => $v['stock'], $vs)) : max(0, $p['stock']);
        $p['in_stock'] = $p['total_stock'] > 0;
        $p['badge_label'] = $p['badge'] ?? null;
        if (!$p['badge_label']) {
            $p['badge_label'] = !$p['in_stock'] ? 'Sold out' : ($p['is_on_sale'] ? 'Sale' : (!empty($p['is_new']) ? 'New' : null));
        }
        $sizes = [];
        $colors = [];
        foreach ($vs as $v) {
            if ($v['size'] !== null && $v['size'] !== '' && !in_array($v['size'], $sizes, true)) {
                $sizes[] = $v['size'];
            }
            if ($v['color'] && !isset($colors[$v['color']])) {
                $colors[$v['color']] = ['name' => $v['color'], 'hex' => $v['color_hex']];
            }
        }
        $p['sizes'] = $sizes;
        $p['colors'] = array_values($colors);
        return $p;
    }

    /** Storage path of the Nth image, or null (old Product::imagePath). */
    public static function imagePath(array $p, int $index = 0): ?string
    {
        return $p['images'][$index]['path'] ?? null;
    }

    /** Public URL of the Nth image (old Product::imageUrl / image_url): the original file, without version. */
    public static function imageUrl(array $p, int $index = 0): ?string
    {
        $path = self::imagePath($p, $index);
        return $path ? \App\Support\Img::publicUrl($path) : null;
    }

    /** Compact structure consumed by the Alpine product form (old Product::variantPayload). */
    public static function variantPayload(array $p): array
    {
        return [
            'hasVariants' => (bool) $p['has_variants'],
            'base' => ['sku' => $p['sku'], 'price' => $p['price'], 'compare' => $p['display_compare_price'], 'stock' => $p['total_stock']],
            'variants' => array_map(static fn ($v) => [
                'id' => $v['id'], 'sku' => $v['sku'], 'size' => $v['size'], 'color' => $v['color'], 'hex' => $v['color_hex'],
                'price' => $v['effective_price'], 'compare' => $v['effective_compare_price'], 'stock' => max(0, $v['stock']),
            ], $p['variants']),
        ];
    }

    /** Approved-review totals from one grouped query (old Product::reviewStats). */
    public static function reviewStats(int $productId): array
    {
        $stars = [];
        foreach (Database::get()->all('SELECT rating, COUNT(*) AS total FROM reviews WHERE product_id = ? AND is_approved = 1 GROUP BY rating', [$productId]) as $r) {
            $stars[(int) $r['rating']] = (int) $r['total'];
        }
        $count = array_sum($stars);
        $sum = 0;
        foreach ($stars as $rating => $n) {
            $sum += $rating * $n;
        }
        return ['count' => $count, 'average' => $count ? round($sum / $count, 1) : 0.0, 'stars' => $stars];
    }

    /* ------------------------------------------------------------------ categories & collections */

    public static function category(string $slug): ?array
    {
        return Database::get()->one('SELECT * FROM categories WHERE slug = ? AND deleted_at IS NULL LIMIT 1', [$slug]);
    }

    public static function collection(string $slug): ?array
    {
        return Database::get()->one('SELECT * FROM collections WHERE slug = ? AND deleted_at IS NULL LIMIT 1', [$slug]);
    }

    /** @return list<int> the category and all of its descendants (soft-deleted ones excluded). */
    public static function descendantIds(int $categoryId): array
    {
        $ids = [$categoryId];
        $frontier = [$categoryId];
        $guard = 0;
        while ($frontier && $guard++ < 20) {
            $frontier = array_map('intval', Database::get()->column('SELECT id FROM categories WHERE deleted_at IS NULL AND parent_id IN ' . Database::in($frontier), $frontier));
            $frontier = array_values(array_diff($frontier, $ids));
            $ids = array_merge($ids, $frontier);
        }
        return $ids;
    }

    /** Parent row of a category (soft-deleted excluded), or null. */
    public static function parentOf(array $category): ?array
    {
        return !empty($category['parent_id']) ? Database::get()->one('SELECT * FROM categories WHERE id = ? AND deleted_at IS NULL', [(int) $category['parent_id']]) : null;
    }

    /** Attribute values used by an active variant, in the admin's order (old ShopController filter options). */
    public static function filterOptions(string $attributeSlug): array
    {
        return CatalogCache::remember('filter.options.' . $attributeSlug, 600, static fn () => array_map(
            static fn ($r) => ['id' => (int) $r['id'], 'value' => (string) $r['value'], 'color_hex' => $r['color_hex']],
            Database::get()->all(
                'SELECT av.id, av.value, av.color_hex FROM attribute_values av JOIN attributes a ON a.id = av.attribute_id
                 WHERE a.slug = ? AND EXISTS (SELECT 1 FROM attribute_value_product_variant j JOIN product_variants v ON v.id = j.product_variant_id
                 WHERE j.attribute_value_id = av.id AND v.is_active = 1) ORDER BY av.sort_order, av.id',
                [$attributeSlug]
            )
        ));
    }

    public static function priceCeiling(): int
    {
        return (int) CatalogCache::remember('price.ceiling', 600, static fn () => (int) Database::get()->value('SELECT MAX(price) FROM products p WHERE ' . self::ACTIVE));
    }

    /* ------------------------------------------------------------------ search & listing */

    /** The old Product::scopeSearch: name, SKU or short description contain the term; % and _ are literal. @return array{0:string,1:list<string>} */
    public static function search(?string $term): array
    {
        $term = trim((string) $term);
        if ($term === '') {
            return ['', []];
        }
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
        return ["p.name LIKE ? ESCAPE '!' OR p.sku LIKE ? ESCAPE '!' OR p.short_description LIKE ? ESCAPE '!'", [$like, $like, $like]];
    }

    /**
     * The shop / category / collection listing with the old filters (validated input of ShopController::render):
     * q, min, max (product price or any active variant price in range), availability=in_stock, color[], size[], sale, sort.
     */
    public static function listing(array $f, ?array $category, ?array $collection, int $page, ?int $perPage = null): Paginator
    {
        $perPage ??= (int) Config::get('abiha.pagination.products', 12);
        $where = [self::ACTIVE];
        $params = [];
        [$s, $sp] = self::search($f['q'] ?? null);
        if ($s !== '') {
            $where[] = '(' . $s . ')';
            array_push($params, ...$sp);
        }
        if ($category) {
            $ids = self::descendantIds((int) $category['id']);
            $where[] = 'p.category_id IN ' . Database::in($ids);
            array_push($params, ...$ids);
        }
        if ($collection) {
            $where[] = 'EXISTS (SELECT 1 FROM collection_product cp WHERE cp.product_id = p.id AND cp.collection_id = ?)';
            $params[] = (int) $collection['id'];
        }
        $min = $f['min'] ?? null;
        $max = $f['max'] ?? null;
        if ($min !== null || $max !== null) {
            $range = static function (string $col) use ($min, $max, &$params): string {
                $c = [];
                if ($min !== null) {
                    $c[] = "{$col} >= ?";
                    $params[] = (int) $min;
                }
                if ($max !== null) {
                    $c[] = "{$col} <= ?";
                    $params[] = (int) $max;
                }
                return implode(' AND ', $c);
            };
            $base = $range('p.price');
            $var = $range('v.price');
            $where[] = "(({$base}) OR EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1 AND v.price IS NOT NULL AND ({$var})))";
        }
        if (($f['availability'] ?? null) === 'in_stock') {
            $where[] = '((p.has_variants = 0 AND p.stock > 0) OR (p.has_variants = 1 AND EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1 AND v.stock > 0)))';
        }
        foreach (['color', 'size'] as $attr) {
            $values = array_values(array_filter((array) ($f[$attr] ?? []), 'is_string'));
            if ($values) {
                $where[] = 'EXISTS (SELECT 1 FROM product_variants v JOIN attribute_value_product_variant j ON j.product_variant_id = v.id
                    JOIN attribute_values av ON av.id = j.attribute_value_id JOIN attributes a ON a.id = av.attribute_id
                    WHERE v.product_id = p.id AND v.is_active = 1 AND a.slug = ? AND av.value IN ' . Database::in($values) . ')';
                $params[] = $attr;
                array_push($params, ...$values);
            }
        }
        if (!empty($f['sale'])) {
            $where[] = '(p.compare_price > p.price OR EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1 AND v.compare_price > v.price))';
        }
        $order = match ($f['sort'] ?? 'featured') {
            'newest' => 'p.id DESC',
            'popular' => 'p.sold_count DESC',
            'price_asc' => 'p.price ASC, p.id ASC',
            'price_desc' => 'p.price DESC, p.id ASC',
            'name' => 'p.name ASC',
            default => 'p.is_featured DESC, p.id DESC',
        };
        $sqlWhere = implode(' AND ', $where);
        $db = Database::get();
        $total = (int) $db->value('SELECT COUNT(*) FROM products p WHERE ' . $sqlWhere, $params);
        $rows = $db->all('SELECT p.* FROM products p WHERE ' . $sqlWhere . ' ORDER BY ' . $order . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
        return new Paginator(self::hydrate($rows), $total, $perPage, $page);
    }

    /** "From" + price + struck-through compare price (the old <x-price> component). */
    public static function priceHtml(array $p, string $class = ''): string
    {
        $sym = e(Config::get('abiha.currency.symbol'));
        return '<p class="' . e(trim('price !mb-0 ' . $class)) . '">' . "\n    "
            . ($p['price_varies'] ? '<span class="muted font-normal text-sm">From</span> ' : '')
            . "\n    " . $sym . ' ' . number_format((int) $p['display_price']) . "\n    "
            . ($p['is_on_sale'] ? '<s class="text-sm">' . $sym . ' ' . number_format((int) $p['display_compare_price']) . '</s>' : '')
            . "\n</p>";
    }
}
