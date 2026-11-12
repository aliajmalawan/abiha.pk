<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Admin\InsufficientStockException;
use App\Services\Admin\Pager;
use App\Services\Admin\ProductService;
use App\Services\Admin\StockWriter;
use App\Support\CatalogCache;

final class InventoryController extends AdminController
{
    public function index(Request $request): Response
    {
        $f = $this->validate($request->query, ['q' => 'nullable|string|max:100', 'status' => 'nullable|in:low,out']);
        [$search, $params] = ProductService::searchSql($f['q'] ?? null);
        $status = match ($f['status'] ?? null) {
            'out' => ProductService::OUT_OF_STOCK_SQL,
            'low' => ProductService::LOW_STOCK_SQL,
            default => '1 = 1',
        };
        $products = Pager::query($request, "SELECT p.* FROM products p WHERE p.deleted_at IS NULL AND {$search} AND {$status} ORDER BY p.name, p.id", $params, (int) cfg('abiha.pagination.admin', 20));
        $ids = array_map(static fn ($p) => (int) $p['id'], $products['items']);
        $variants = $ids ? Database::get()->all('SELECT * FROM product_variants WHERE product_id IN ' . Database::in($ids) . ' ORDER BY sort_order, id', $ids) : [];
        $labels = ProductService::variantLabels(array_map(static fn ($v) => (int) $v['id'], $variants));
        foreach ($products['items'] as &$p) {
            $p['variants'] = [];
            foreach ($variants as $v) {
                if ((int) $v['product_id'] === (int) $p['id']) {
                    $p['variants'][] = $v + ['label' => $labels[(int) $v['id']] ?? ''];
                }
            }
        }
        unset($p);
        return $this->adminView('admin/inventory/index', ['products' => $products, 'filters' => $f], 'Inventory');
    }

    /** Add stock, remove stock, or set an exact level (stock-take) for one product or variant. */
    public function adjust(Request $request): Response
    {
        $data = $this->validate($request->post, [
            'target' => 'required|in:product,variant',
            'id' => 'required|integer',
            'mode' => 'required|in:add,remove,set',
            'quantity' => 'required|integer|min:0|max:1000000',
            'reason' => 'required|in:restock,adjustment,return',
            'note' => 'nullable|string|max:190',
        ]);
        $db = Database::get();
        $item = $data['target'] === 'variant'
            ? $db->one('SELECT * FROM product_variants WHERE id = ?', [$data['id']])
            : $db->one('SELECT * FROM products WHERE id = ? AND deleted_at IS NULL', [$data['id']]);
        if ($item === null) {
            throw new HttpException(404);
        }
        $back = route('admin.inventory.index');
        if ($data['target'] === 'product' && (int) $item['has_variants'] === 1) {
            return $this->backWithErrors(['inventory' => 'This product tracks stock per variant. Adjust a variant instead.'], $back);
        }
        try {
            match ($data['mode']) {
                'add' => StockWriter::adjust($data['target'], (int) $item['id'], $data['quantity'], $data['reason'], null, Auth::id(), $data['note'] ?? null),
                'remove' => StockWriter::adjust($data['target'], (int) $item['id'], -$data['quantity'], $data['reason'], null, Auth::id(), $data['note'] ?? null),
                'set' => StockWriter::setLevel($data['target'], (int) $item['id'], $data['quantity'], Auth::id(), $data['note'] ?? null, $data['reason']),
            };
        } catch (InsufficientStockException $e) {
            return $this->backWithErrors(['inventory' => $e->getMessage()], $back);
        }
        CatalogCache::bump();
        return $this->back($back, 'Stock updated.');
    }

    /** CSV of current stock for every product and variant. */
    public function export(Request $request): Response
    {
        $db = Database::get();
        $rows = [];
        foreach ($db->all('SELECT * FROM products WHERE deleted_at IS NULL ORDER BY name, id') as $p) {
            if ((int) $p['has_variants'] === 1) {
                $vs = $db->all('SELECT * FROM product_variants WHERE product_id = ? ORDER BY sort_order, id', [(int) $p['id']]);
                $labels = ProductService::variantLabels(array_map(static fn ($v) => (int) $v['id'], $vs));
                foreach ($vs as $v) {
                    $rows[] = [$p['name'], $v['sku'], $labels[(int) $v['id']] ?? '', (int) $v['stock'], (int) $p['low_stock_threshold'], $p['is_active'] ? 'yes' : 'no'];
                }
            } else {
                $rows[] = [$p['name'], $p['sku'], '', (int) $p['stock'], (int) $p['low_stock_threshold'], $p['is_active'] ? 'yes' : 'no'];
            }
        }
        return self::csv('stock-' . date('Ymd') . '.csv', ['Product', 'SKU', 'Variant', 'On hand', 'Low-stock alert at', 'Visible'], $rows);
    }

    /** CSV of the whole stock ledger (inventory history). */
    public function exportLedger(Request $request): Response
    {
        $rows = [];
        foreach (Database::get()->all(
            'SELECT m.*, p.name AS product_name, v.sku AS variant_sku, u.name AS user_name FROM stock_movements m
             LEFT JOIN products p ON p.id = m.product_id LEFT JOIN product_variants v ON v.id = m.variant_id LEFT JOIN users u ON u.id = m.user_id ORDER BY m.id'
        ) as $m) {
            $rows[] = [dt((string) $m['created_at'], 'Y-m-d H:i'), $m['product_name'], $m['variant_sku'], (int) $m['change'], (int) $m['balance_after'], $m['reason'], $m['order_id'], $m['user_name'], $m['note']];
        }
        return self::csv('inventory-history-' . date('Ymd') . '.csv', ['When', 'Product', 'Variant SKU', 'Change', 'Balance after', 'Reason', 'Order id', 'By', 'Note'], $rows);
    }

    public function ledger(Request $request): Response
    {
        $f = $this->validate($request->query, ['q' => 'nullable|string|max:100', 'reason' => 'nullable|in:' . implode(',', StockWriter::REASONS)]);
        $where = ['1 = 1'];
        $params = [];
        if (!empty($f['reason'])) {
            $where[] = 'm.reason = ?';
            $params[] = $f['reason'];
        }
        if (!empty($f['q'])) {
            [$search, $sp] = ProductService::searchSql($f['q']);
            $where[] = $search;
            $params = [...$params, ...$sp];
        }
        $rows = Pager::query($request,
            'SELECT m.*, p.name AS product_name, v.sku AS variant_sku, u.name AS user_name FROM stock_movements m
             LEFT JOIN products p ON p.id = m.product_id LEFT JOIN product_variants v ON v.id = m.variant_id LEFT JOIN users u ON u.id = m.user_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY m.id DESC', $params, 30);
        return $this->adminView('admin/inventory/ledger', ['rows' => $rows, 'filters' => $f, 'reasons' => StockWriter::REASONS], 'Stock ledger');
    }

    /** Port of App\Support\Csv::download: BOM, header row, formula-looking text cells neutralised. */
    public static function csv(string $filename, array $headers, iterable $rows): Response
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, array_map(static function ($v) {
                return is_string($v) && $v !== '' && !is_numeric($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
            }, $row));
        }
        rewind($out);
        $body = (string) stream_get_contents($out);
        fclose($out);
        return Response::download($body, $filename);
    }
}
