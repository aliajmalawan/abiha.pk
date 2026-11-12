<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\CatalogCache;

/**
 * The admin-managed homepage (port of the old App\Services\HomePageService + HomeSection model).
 * sections() = list of ['section' => home_sections row (settings decoded), 'data' => array]; sections with nothing to
 * show are skipped. Cached in CatalogCache ("home.sections") until the catalogue changes (CatalogCache::bump()).
 */
final class HomePage
{
    public const TYPES = ['hero', 'campaign', 'categories', 'products', 'collections', 'promo', 'brand', 'testimonials', 'account', 'newsletter', 'social'];

    public static function sections(): array
    {
        return CatalogCache::remember('home.sections', 600, static function (): array {
            $out = [];
            foreach (Database::get()->all('SELECT * FROM home_sections WHERE is_active = 1 ORDER BY sort_order, id') as $s) {
                $s['id'] = (int) $s['id'];
                $decoded = is_string($s['settings']) ? json_decode($s['settings'], true) : null;
                $s['settings'] = is_array($decoded) ? $decoded : [];
                $data = self::dataFor($s);
                if ($data !== null) {
                    $out[] = ['section' => $s, 'data' => $data];
                }
            }
            return $out;
        });
    }

    /** HomeSection::setting(): dot-notation read of the settings JSON. */
    public static function setting(array $section, string $key, mixed $default = null): mixed
    {
        $v = $section['settings'] ?? [];
        foreach (explode('.', $key) as $seg) {
            if (!is_array($v) || !array_key_exists($seg, $v)) {
                return $default;
            }
            $v = $v[$seg];
        }
        return $v ?? $default;
    }

    public static function dataFor(array $s): ?array
    {
        $limit = (int) self::setting($s, 'limit', 4);
        $db = Database::get();
        switch ($s['type']) {
            case 'hero':
                $b = self::banners('hero');
                return $b ? ['banners' => $b, 'showcase' => self::showcase()] : null;
            case 'campaign':
                $b = self::banners('campaign', 1);
                return $b ? ['banner' => $b[0]] : null;
            case 'promo':
                $b = self::banners('promo', 1);
                return $b ? ['banner' => $b[0], 'deal' => self::bestDeal()] : null;
            case 'categories':
                $c = $db->all('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1 AND p.deleted_at IS NULL) AS active_products_count
                    FROM categories c WHERE c.is_active = 1 AND c.is_featured = 1 AND c.parent_id IS NULL AND c.deleted_at IS NULL ORDER BY c.sort_order LIMIT ' . max(0, $limit));
                return $c ? ['categories' => $c] : null;
            case 'collections':
                $c = $db->all('SELECT c.*, (SELECT COUNT(*) FROM collection_product cp JOIN products p ON p.id = cp.product_id WHERE cp.collection_id = c.id AND p.is_active = 1 AND p.deleted_at IS NULL) AS active_products_count
                    FROM collections c WHERE c.is_active = 1 AND c.is_trending = 1 AND c.deleted_at IS NULL ORDER BY c.sort_order LIMIT ' . max(0, $limit));
                return $c ? ['collections' => $c] : null;
            case 'products':
                return self::products($s, $limit);
            case 'testimonials':
                $t = $db->all('SELECT * FROM testimonials WHERE is_active = 1 ORDER BY sort_order LIMIT ' . max(0, $limit));
                return $t ? ['testimonials' => $t] : null;
            case 'brand':
                // no text yet: the block stays hidden instead of showing an empty story
                return trim((string) self::setting($s, 'body')) !== '' ? ['stats' => self::stats(), 'showcase' => self::showcase()] : null;
            case 'newsletter':
            case 'social':
            case 'account':
                return [];
            default:
                return null;
        }
    }

    /** Banner::placement(): active banners of a placement ordered by sort_order. */
    public static function banners(string $placement, ?int $limit = null): array
    {
        $rows = Database::get()->all('SELECT * FROM banners WHERE placement = ? AND is_active = 1 ORDER BY sort_order' . ($limit ? ' LIMIT ' . $limit : ''), [$placement]);
        foreach ($rows as &$r) {
            $r['show_text'] = (bool) $r['show_text'];
            $r['is_sample'] = (bool) $r['is_sample'];
        }
        return $rows;
    }

    /** The products shown in the hero and story pictures: featured ones first, then the newest. Real products only. */
    private static function showcase(): array
    {
        return Catalog::cards('', [], 'p.is_featured DESC, p.created_at DESC', 3);
    }

    /** The on-sale product with the biggest percentage off, or null when nothing is discounted. */
    private static function bestDeal(): ?array
    {
        return Catalog::cards('p.compare_price IS NOT NULL AND p.compare_price > p.price', [], '(p.compare_price - p.price) * 1.0 / p.compare_price DESC', 1)[0] ?? null;
    }

    /** Figures counted from the database; a zero figure is left out by the view. */
    private static function stats(): array
    {
        $db = Database::get();
        return [
            'Products' => (int) $db->value('SELECT COUNT(*) FROM products p WHERE ' . Catalog::ACTIVE),
            'Categories' => (int) $db->value('SELECT COUNT(*) FROM categories WHERE is_active = 1 AND deleted_at IS NULL'),
            'Colours' => (int) $db->value("SELECT COUNT(DISTINCT v.id) FROM attribute_value_product_variant j JOIN attribute_values v ON v.id = j.attribute_value_id
                JOIN attributes a ON a.id = v.attribute_id JOIN product_variants pv ON pv.id = j.product_variant_id JOIN products p ON p.id = pv.product_id
                WHERE a.slug = 'color' AND p.is_active = 1 AND p.deleted_at IS NULL"),
        ];
    }

    private static function products(array $s, int $limit): ?array
    {
        $source = self::setting($s, 'source');
        $params = [];
        [$where, $order] = match ($source) {
            'bestsellers' => ['p.is_bestseller = 1', 'p.sold_count DESC'],
            'new' => ['p.is_new = 1', 'p.created_at DESC'],
            'featured' => ['p.is_featured = 1', 'p.created_at DESC'],
            'trending' => ['p.is_trending = 1', 'p.created_at DESC'],
            'collection' => ['EXISTS (SELECT 1 FROM collection_product cp JOIN collections c ON c.id = cp.collection_id WHERE cp.product_id = p.id AND c.slug = ? AND c.deleted_at IS NULL)', 'p.created_at DESC'],
            default => ['', 'p.created_at DESC'],
        };
        if ($source === 'collection') {
            $params[] = (string) self::setting($s, 'collection');
        }
        $products = Catalog::cards($where, $params, $order, $limit);
        if (!$products) {
            return null;
        }

        // Optional tabs: "All" plus one tab for each active collection that has some of these products.
        $tabs = [];
        if (self::setting($s, 'tabs') && $source !== 'collection') {
            foreach (Database::get()->all('SELECT id, name, slug FROM collections WHERE is_active = 1 AND deleted_at IS NULL ORDER BY sort_order LIMIT 6') as $col) {
                $w = ($where !== '' ? '(' . $where . ') AND ' : '') . 'EXISTS (SELECT 1 FROM collection_product cp2 WHERE cp2.product_id = p.id AND cp2.collection_id = ?)';
                $rows = Catalog::cards($w, [...$params, (int) $col['id']], $order, $limit);
                if ($rows) {
                    $tabs[] = ['label' => $col['name'], 'products' => $rows, 'link' => route('collection', ['slug' => $col['slug']])];
                }
            }
            array_unshift($tabs, ['label' => 'All', 'products' => $products, 'link' => self::setting($s, 'link')]);
            if (count($tabs) < 2) {
                $tabs = [];
            }
        }
        return ['products' => $products, 'tabs' => $tabs];
    }
}
