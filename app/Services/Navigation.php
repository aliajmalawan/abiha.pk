<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\CatalogCache;

/**
 * Data for the header (mega menu + mobile drawer): active top-level categories with their active children, ordered by
 * sort_order (the old Category::active()->roots()->with('children')->orderBy('sort_order'), cached 10 minutes in
 * CatalogCache under "nav.categories"). The storefront slice may replace the query; keep the array shape:
 *   [['id' => 12, 'name' => 'Sportswear', 'slug' => 'sportswear', 'children' => [['id'=>13,'name'=>'T-shirts','slug'=>'t-shirts'], ...]], ...]
 */
final class Navigation
{
    /** @return list<array{id:int,name:string,slug:string,children:list<array{id:int,name:string,slug:string}>}> */
    public static function categories(): array
    {
        return CatalogCache::remember('nav.categories', 600, static function (): array {
            $db = Database::get();
            $roots = $db->all('SELECT id, name, slug FROM categories WHERE is_active = 1 AND parent_id IS NULL AND deleted_at IS NULL ORDER BY sort_order, id');
            if (!$roots) {
                return [];
            }
            $ids = array_map(static fn ($r) => (int) $r['id'], $roots);
            $children = $db->all(
                'SELECT id, parent_id, name, slug FROM categories WHERE is_active = 1 AND deleted_at IS NULL AND parent_id IN ' . Database::in($ids) . ' ORDER BY sort_order, id',
                $ids
            );
            $byParent = [];
            foreach ($children as $c) {
                $byParent[(int) $c['parent_id']][] = ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'slug' => (string) $c['slug']];
            }
            return array_map(static fn ($r) => [
                'id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug'],
                'children' => $byParent[(int) $r['id']] ?? [],
            ], $roots);
        });
    }
}
