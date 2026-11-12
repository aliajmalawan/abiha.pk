<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;
use App\Services\Admin\Pager;
use App\Services\Admin\ProductService;
use App\Services\Admin\Rules;
use App\Support\CatalogCache;

/** Bulk toggle of the merchandising flags that feed homepage rows. */
final class FeaturedController extends AdminController
{
    public const FLAGS = ['is_featured' => 'Featured', 'is_new' => 'New arrival', 'is_bestseller' => 'Best seller', 'is_trending' => 'Trending'];

    public function index(Request $request): Response
    {
        $q = trim(is_string($request->query['q'] ?? null) ? $request->query['q'] : '');
        [$search, $params] = ProductService::searchSql($q);
        $products = Pager::query($request,
            "SELECT p.*, (SELECT i.path FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS image_path
             FROM products p WHERE p.deleted_at IS NULL AND {$search} ORDER BY p.is_featured DESC, p.name ASC, p.id ASC", $params, 30);
        return $this->adminView('admin/featured', ['products' => $products, 'flags' => self::FLAGS, 'q' => $q], 'Featured products');
    }

    /** Saves the flags for the products on the submitted page only (ids[] lists which rows were shown). */
    public function update(Request $request): Response
    {
        $in = $request->post;
        Rules::throwIf(Rules::run($in, ['ids' => 'required|array|max:100', 'ids.*' => 'integer|exists:products,id', 'flags' => 'nullable|array']));
        $flags = is_array($in['flags'] ?? null) ? $in['flags'] : [];
        $db = Database::get();
        foreach ($in['ids'] as $id) {
            $sets = [];
            $vals = [];
            foreach (array_keys(self::FLAGS) as $flag) {
                $sets[] = "`{$flag}` = ?";
                $vals[] = !empty($flags[$id][$flag]) ? 1 : 0;
            }
            $db->exec('UPDATE products SET ' . implode(', ', $sets) . ', updated_at = ? WHERE id = ?', [...$vals, now(), (int) $id]);
        }
        CatalogCache::bump();     // without this the homepage rows stay stale
        ActivityLogger::log('products.flags_updated', null, null, ['count' => count($in['ids'])]);
        return $this->back(route('admin.featured.index'), 'Merchandising flags saved.');
    }
}
