<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Services\ActivityLogger;
use App\Services\ImageStore;
use App\Support\CatalogCache;
use App\Support\HtmlSanitizer;
use App\Support\Img;

/**
 * Admin product writes (port of the old App\Services\ProductService): create or update a product with its images,
 * specifications and variants in ONE transaction. Stock levels go through the stock ledger (StockWriter), never by
 * direct column updates. Delete = soft delete (order history keeps its own snapshot).
 */
final class ProductService
{
    private const FLAGS = ['is_active', 'is_featured', 'is_new', 'is_bestseller', 'is_trending', 'has_variants'];
    private const FIELDS = ['name', 'slug', 'sku', 'category_id', 'short_description', 'description', 'price', 'compare_price', 'low_stock_threshold', 'badge', 'meta_title', 'meta_description'];

    /**
     * @param array<string,mixed> $data validated product fields (+ specifications, image_alts)
     * @param list<array<string,mixed>> $variants validated variant rows (id?, sku, price, compare_price, stock, is_active, options[])
     * @param list<array> $newImages $_FILES-style entries (already validated)
     * @param list<int> $removeImageIds
     * @param list<int> $imageOrder existing image ids, first = main photo
     * @return array<string,mixed> the saved product row
     */
    public static function save(?array $product, array $data, array $variants = [], array $newImages = [], array $removeImageIds = [], ?int $by = null, array $imageOrder = []): array
    {
        $db = Database::get();
        $saved = $db->transaction(static function (Database $db) use ($product, $data, $variants, $newImages, $removeImageIds, $by, $imageOrder): array {
            $creating = $product === null;
            $hasVariants = (bool) ($data['has_variants'] ?? false);
            $wantedStock = $hasVariants ? 0 : (int) ($data['stock'] ?? 0);

            $fill = [];
            foreach (self::FIELDS as $f) {
                if (array_key_exists($f, $data)) {
                    $fill[$f] = $data[$f];
                }
            }
            foreach (self::FLAGS as $flag) {
                $fill[$flag] = !empty($data[$flag]) ? 1 : 0;
            }
            $fill['description'] = isset($fill['description']) ? HtmlSanitizer::clean((string) $fill['description']) : null;
            $specs = self::cleanSpecifications($data['specifications'] ?? []);
            $fill['specifications'] = $specs === null ? null : json_encode($specs, JSON_UNESCAPED_UNICODE);
            $fill['slug'] = self::uniqueSlug($fill['slug'] ?? null, (string) $fill['name'], $product['id'] ?? 0);
            $fill['low_stock_threshold'] = $fill['low_stock_threshold'] ?? 3;
            $fill['is_sample'] = 0;                                   // edited by an admin = real content
            $fill['updated_at'] = now();

            if ($creating) {
                $fill['stock'] = 0;                                   // opening stock goes through the ledger below
                $fill['created_at'] = now();
                $cols = array_keys($fill);
                $id = $db->insert('INSERT INTO products (' . implode(', ', array_map(static fn ($c) => "`{$c}`", $cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($fill));
            } else {
                $id = (int) $product['id'];
                $sets = implode(', ', array_map(static fn ($c) => "`{$c}` = ?", array_keys($fill)));
                $db->exec("UPDATE products SET {$sets} WHERE id = ?", [...array_values($fill), $id]);
            }

            // Simple product stock -> ledger
            if (!$hasVariants) {
                StockWriter::setLevel('product', $id, $wantedStock, $by, $creating ? 'Opening stock' : 'Edited in product form', $creating ? 'initial' : 'adjustment');
            } elseif ((int) $db->value('SELECT stock FROM products WHERE id = ?', [$id]) !== 0) {
                StockWriter::setLevel('product', $id, 0, $by, 'Product now uses variants (stock is tracked per variant)');
            }

            self::syncImages($id, (string) $fill['name'], $newImages, $removeImageIds, $imageOrder);
            foreach ((array) ($data['image_alts'] ?? []) as $imageId => $alt) {
                // Plain text only; an empty box falls back to the product name so no image is left without alt text.
                $clean = trim(strip_tags((string) $alt));
                $db->exec('UPDATE product_images SET alt = ?, updated_at = ? WHERE id = ? AND product_id = ?', [$clean !== '' ? $clean : $fill['name'], now(), (int) $imageId, $id]);
            }
            $hasVariants ? self::syncVariants($id, $variants, $by) : self::dropVariants($id);

            $after = $db->one('SELECT * FROM products WHERE id = ?', [$id]);
            ActivityLogger::log($creating ? 'product.created' : 'product.updated', 'Product', $id, $creating ? ['sku' => $after['sku']] : ActivityLogger::diff($product, $after));
            return $after;
        });
        CatalogCache::bump();
        return $saved;
    }

    /** Soft-delete (keeps order history intact). */
    public static function delete(array $product): void
    {
        Database::get()->exec('UPDATE products SET is_active = 0, deleted_at = ?, updated_at = ? WHERE id = ?', [now(), now(), (int) $product['id']]);
        ActivityLogger::log('product.deleted', 'Product', (int) $product['id'], ['sku' => $product['sku']]);
        CatalogCache::bump();
    }

    /** Label/value rows as plain text: blank or half-filled rows are dropped, markup is stripped, order is kept. */
    private static function cleanSpecifications(mixed $rows): ?array
    {
        $clean = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $r = is_array($r) ? $r : [];
            $row = ['label' => trim(strip_tags(is_scalar($r['label'] ?? null) ? (string) $r['label'] : '')), 'value' => trim(strip_tags(is_scalar($r['value'] ?? null) ? (string) $r['value'] : ''))];
            if ($row['label'] !== '' && $row['value'] !== '') {
                $clean[] = $row;
            }
        }
        $clean = array_slice($clean, 0, 25);
        return $clean ?: null;
    }

    private static function syncImages(int $productId, string $name, array $newImages, array $removeIds, array $imageOrder): void
    {
        $db = Database::get();
        if ($removeIds) {
            $ids = array_map('intval', $removeIds);
            foreach ($db->all('SELECT id, path FROM product_images WHERE product_id = ? AND id IN ' . Database::in($ids), [$productId, ...$ids]) as $img) {
                Img::delete((string) $img['path']);
                $db->exec('DELETE FROM product_images WHERE id = ?', [(int) $img['id']]);
            }
        }

        // Re-number what is left: the order chosen in the form first (ids that are not this product's are ignored),
        // anything not mentioned keeps its place after them. Image 1 is the main photo.
        $remaining = [];
        foreach ($db->all('SELECT id, sort_order FROM product_images WHERE product_id = ? ORDER BY sort_order, id', [$productId]) as $r) {
            $remaining[(int) $r['id']] = (int) $r['sort_order'];
        }
        $wanted = array_values(array_unique(array_filter(array_map('intval', $imageOrder), static fn ($id) => isset($remaining[$id]))));
        $sequence = array_merge($wanted, array_values(array_diff(array_keys($remaining), $wanted)));
        foreach ($sequence as $i => $id) {
            if ($remaining[$id] !== $i + 1) {
                $db->exec('UPDATE product_images SET sort_order = ?, updated_at = ? WHERE id = ?', [$i + 1, now(), $id]);
            }
        }

        $order = count($sequence);
        foreach ($newImages as $file) {
            $path = ImageStore::store($file, 'products', 'images');      // re-encoded, metadata stripped, random name
            $db->insert('INSERT INTO product_images (product_id, path, alt, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$productId, $path, $name, ++$order, now(), now()]);
        }
    }

    private static function syncVariants(int $productId, array $rows, ?int $by): void
    {
        $db = Database::get();
        $keep = [];
        foreach (array_values($rows) as $i => $row) {
            $variant = !empty($row['id']) ? $db->one('SELECT id FROM product_variants WHERE id = ? AND product_id = ?', [(int) $row['id'], $productId]) : null;
            $isNew = $variant === null;
            $price = ($row['price'] ?? '') === '' || $row['price'] === null ? null : (int) $row['price'];
            $compare = ($row['compare_price'] ?? '') === '' || $row['compare_price'] === null ? null : (int) $row['compare_price'];
            $active = in_array($row['is_active'] ?? null, [true, 1, '1', 'true', 'on', 'yes'], true) ? 1 : 0;
            if ($isNew) {
                $vid = $db->insert(
                    'INSERT INTO product_variants (product_id, sku, price, compare_price, stock, is_active, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)',
                    [$productId, trim((string) $row['sku']), $price, $compare, $active, $i, now(), now()]
                );
            } else {
                $vid = (int) $variant['id'];
                $db->exec('UPDATE product_variants SET sku = ?, price = ?, compare_price = ?, is_active = ?, sort_order = ?, updated_at = ? WHERE id = ?', [trim((string) $row['sku']), $price, $compare, $active, $i, now(), $vid]);
            }
            $db->exec('DELETE FROM attribute_value_product_variant WHERE product_variant_id = ?', [$vid]);
            foreach (array_unique(array_map('intval', (array) ($row['options'] ?? []))) as $valueId) {
                $db->exec('INSERT INTO attribute_value_product_variant (product_variant_id, attribute_value_id) VALUES (?, ?)', [$vid, $valueId]);
            }
            StockWriter::setLevel('variant', $vid, (int) $row['stock'], $by, $isNew ? 'Opening stock' : 'Edited in product form', $isNew ? 'initial' : 'adjustment');
            $keep[] = $vid;
        }
        // Variants removed from the form are deleted (order lines keep their own snapshot).
        $db->exec('DELETE FROM product_variants WHERE product_id = ?' . ($keep ? ' AND id NOT IN ' . Database::in($keep) : ''), [$productId, ...$keep]);
    }

    private static function dropVariants(int $productId): void
    {
        Database::get()->exec('DELETE FROM product_variants WHERE product_id = ?', [$productId]);
    }

    private static function uniqueSlug(?string $wanted, string $name, int $ignoreId): string
    {
        $base = slugify($wanted ?: $name) ?: 'product';
        $slug = $base;
        for ($i = 2; (int) Database::get()->value('SELECT COUNT(*) FROM products WHERE slug = ? AND id <> ?', [$slug, $ignoreId]) > 0; $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }

    /* ---------------- reads for the admin pages ---------------- */

    /** "Size: M / Colour: Black", ordered by attribute sort order (ProductVariant::label). */
    public static function variantLabels(array $variantIds): array
    {
        if (!$variantIds) {
            return [];
        }
        $rows = Database::get()->all(
            'SELECT p.product_variant_id AS vid, a.name, v.value FROM attribute_value_product_variant p
             JOIN attribute_values v ON v.id = p.attribute_value_id JOIN attributes a ON a.id = v.attribute_id
             WHERE p.product_variant_id IN ' . Database::in($variantIds) . ' ORDER BY a.sort_order, a.id',
            array_values($variantIds)
        );
        $out = array_fill_keys($variantIds, '');
        foreach ($rows as $r) {
            $out[(int) $r['vid']] = ($out[(int) $r['vid']] === '' ? '' : $out[(int) $r['vid']] . ' / ') . $r['name'] . ': ' . $r['value'];
        }
        return $out;
    }

    /** IDs of a category and all of its descendants (Category::descendantIds, soft-deleted ones skipped). */
    public static function descendantIds(int $categoryId): array
    {
        $db = Database::get();
        $ids = [$categoryId];
        $frontier = [$categoryId];
        while ($frontier) {
            $frontier = array_map('intval', $db->column('SELECT id FROM categories WHERE deleted_at IS NULL AND parent_id IN ' . Database::in($frontier), $frontier));
            $frontier = array_values(array_diff($frontier, $ids));
            $ids = array_merge($ids, $frontier);
        }
        return $ids;
    }

    /** SQL condition + params of the Product search scope (name, SKU, short description). */
    public static function searchSql(?string $term, string $alias = 'p'): array
    {
        $term = trim((string) $term);
        if ($term === '') {
            return ['1 = 1', []];
        }
        $like = Pager::like($term);
        return ["({$alias}.name LIKE ? ESCAPE '!' OR {$alias}.sku LIKE ? ESCAPE '!' OR {$alias}.short_description LIKE ? ESCAPE '!')", [$like, $like, $like]];
    }

    public const LOW_STOCK_SQL = "((p.has_variants = 0 AND p.stock > 0 AND p.stock <= p.low_stock_threshold) OR (p.has_variants = 1 AND EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.stock > 0 AND v.stock <= p.low_stock_threshold)))";
    public const OUT_OF_STOCK_SQL = "((p.has_variants = 0 AND p.stock <= 0) OR (p.has_variants = 1 AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.stock > 0)))";
}
