<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Core\Request;

/**
 * Offset pagination for the admin lists (Laravel's paginate()->withQueryString() + the old "abiha" pagination view).
 *
 *   $p = Pager::query($request, 'SELECT p.* FROM products p WHERE ... ORDER BY p.id DESC', $params, 20);
 *   $p['items'] (rows), $p['total'], $p['page'], $p['last'];   in a view: View::partial('admin/partials/pagination', ['pager' => $p])
 */
final class Pager
{
    /** @return array{items:list<array>,total:int,page:int,last:int,per:int,path:string,query:array} */
    public static function query(Request $request, string $sql, array $params, int $perPage): array
    {
        $db = Database::get();
        $total = (int) $db->value('SELECT COUNT(*) FROM (' . $sql . ') AS pager_count', $params);
        $page = max(1, (int) (is_numeric($request->query['page'] ?? null) ? $request->query['page'] : 1));
        $last = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $items = $db->all($sql . ' LIMIT ' . $perPage . ' OFFSET ' . $offset, $params);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'last' => $last, 'per' => $perPage, 'path' => $request->path, 'query' => $request->query];
    }

    public static function url(array $p, int $page): string
    {
        $q = $p['query'];
        $q['page'] = $page;
        return url($p['path'], $q);
    }

    /**
     * Laravel's UrlWindow (onEachSide 3): list of page-number arrays and '...' separators.
     *
     * @return list<string|list<int>>
     */
    public static function elements(array $p): array
    {
        $last = $p['last'];
        $cur = $p['page'];
        $each = 3;
        if ($last < ($each * 2) + 8) {
            return [range(1, $last)];
        }
        $window = $each + 4;
        if ($cur <= $window) {
            return [range(1, $window + $each), '...', [$last - 1, $last]];
        }
        if ($cur > $last - $window) {
            return [[1, 2], '...', range($last - ($window + ($each - 1)), $last)];
        }
        return [[1, 2], '...', range($cur - $each, $cur + $each), '...', [$last - 1, $last]];
    }

    /** LIKE pattern with '!' as escape character: a typed % or _ is searched literally (use ... LIKE ? ESCAPE '!'). */
    public static function like(string $term): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
    }
}
