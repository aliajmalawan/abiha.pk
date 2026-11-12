<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;
use App\Services\Admin\ProductService;
use App\Services\Admin\Rules;
use App\Support\CatalogCache;

/**
 * Sale pricing in bulk. A sale = price lowered, original price kept in compare_price (shown struck through).
 * Applying never compounds: products already on sale are skipped. Ending a sale restores the original price.
 */
final class DiscountController extends AdminController
{
    public function index(Request $request): Response
    {
        $db = Database::get();
        return $this->adminView('admin/discounts', [
            'categories' => $db->all('SELECT id, name FROM categories WHERE deleted_at IS NULL ORDER BY name'),
            'onSale' => (int) $db->value('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND compare_price > price'),
        ], 'Discounts');
    }

    public function apply(Request $request): Response
    {
        $data = $this->validated($request);
        $percent = (int) $data['percent'];
        [$changed, $skipped] = Database::get()->transaction(function (Database $db) use ($data, $percent): array {
            $changed = $skipped = 0;
            foreach ($this->scope($db, $data, '') as $p) {
                if ($p['compare_price'] !== null && (int) $p['compare_price'] > (int) $p['price']) {
                    $skipped++;                                           // already on sale: never compound
                    continue;
                }
                $db->exec('UPDATE products SET compare_price = ?, price = ?, updated_at = ? WHERE id = ?', [(int) $p['price'], self::reduce((int) $p['price'], $percent), now(), (int) $p['id']]);
                // only variants with their own price; others follow the product
                foreach ($db->all('SELECT * FROM product_variants WHERE product_id = ? AND price IS NOT NULL FOR UPDATE', [(int) $p['id']]) as $v) {
                    if ($v['compare_price'] === null || (int) $v['compare_price'] <= (int) $v['price']) {
                        $db->exec('UPDATE product_variants SET compare_price = ?, price = ?, updated_at = ? WHERE id = ?', [(int) $v['price'], self::reduce((int) $v['price'], $percent), now(), (int) $v['id']]);
                    }
                }
                $changed++;
            }
            return [$changed, $skipped];
        });
        CatalogCache::bump();
        ActivityLogger::log('discount.applied', null, null, ['percent' => $percent, 'scope' => $data['scope'], 'products' => $changed, 'skipped' => $skipped]);
        return $this->back(route('admin.discounts.index'), "{$percent}% sale applied to {$changed} products" . ($skipped ? " ({$skipped} already on sale were left alone)" : '') . '.');
    }

    public function clear(Request $request): Response
    {
        $data = $this->validated($request, false);
        $count = Database::get()->transaction(function (Database $db) use ($data): int {
            $n = 0;
            foreach ($this->scope($db, $data, ' AND compare_price > price') as $p) {
                $db->exec('UPDATE products SET price = ?, compare_price = NULL, updated_at = ? WHERE id = ?', [(int) $p['compare_price'], now(), (int) $p['id']]);
                foreach ($db->all('SELECT * FROM product_variants WHERE product_id = ? AND price IS NOT NULL AND compare_price IS NOT NULL FOR UPDATE', [(int) $p['id']]) as $v) {
                    if ((int) $v['compare_price'] > (int) $v['price']) {
                        $db->exec('UPDATE product_variants SET price = ?, compare_price = NULL, updated_at = ? WHERE id = ?', [(int) $v['compare_price'], now(), (int) $v['id']]);
                    }
                }
                $n++;
            }
            return $n;
        });
        CatalogCache::bump();
        ActivityLogger::log('discount.cleared', null, null, ['scope' => $data['scope'], 'products' => $count]);
        return $this->back(route('admin.discounts.index'), "Sale ended on {$count} products. Original prices restored.");
    }

    private function validated(Request $request, bool $needsPercent = true): array
    {
        $in = $request->post;
        $errors = Rules::run($in, [
            'scope' => 'required|in:all,category,featured',
            'category_id' => 'nullable|integer|exists:categories,id',
            'percent' => ($needsPercent ? 'required' : 'nullable') . '|integer|between:1,90',
        ]);
        $cat = $in['category_id'] ?? null;
        if (($in['scope'] ?? null) === 'category' && ($cat === null || (is_string($cat) && trim($cat) === ''))) {
            $errors['category_id'] = 'The category id field is required when scope is category.';
        }
        Rules::throwIf($errors);
        return \App\Core\Validator::make($in, ['scope' => 'required', 'category_id' => 'nullable|integer', 'percent' => 'nullable|integer'])->validated();
    }

    /** @return list<array> the products in scope, locked for update */
    private function scope(Database $db, array $data, string $extra): array
    {
        $where = 'deleted_at IS NULL' . $extra;
        $params = [];
        if ($data['scope'] === 'category') {
            $ids = ProductService::descendantIds((int) $data['category_id']);
            $where .= ' AND category_id IN ' . Database::in($ids);
            $params = $ids;
        } elseif ($data['scope'] === 'featured') {
            $where .= ' AND is_featured = 1';
        }
        return $db->all("SELECT * FROM products WHERE {$where} ORDER BY id FOR UPDATE", $params);
    }

    /** Discounted whole-PKR price, never below 1. */
    private static function reduce(int $price, int $percent): int
    {
        return max(1, (int) round($price * (100 - $percent) / 100));
    }
}
