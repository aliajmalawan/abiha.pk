<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uploads;
use App\Core\View;
use App\Services\ActivityLogger;
use App\Services\Admin\Pager;
use App\Services\Admin\ReportService;
use App\Services\Cart\CartException;
use App\Services\OrderMailer;
use App\Services\OrderService;
use App\Services\Payments\PaymentService;
use App\Services\Shipping\CourierException;
use App\Services\Shipping\CourierManager;

/**
 * Orders in the admin (port of the old Admin\OrderController): list + filters + CSV, detail page, status machine,
 * courier details, payment verification / rejection / refund record, private receipt, printable invoice.
 * Every state change goes through OrderService / PaymentService (slice C), never around them.
 */
final class OrderController extends AdminController
{
    public function index(Request $request): Response
    {
        $f = $this->filters($request);
        [$where, $params] = $this->filtered($f);
        $sql = 'SELECT o.*, a.name AS name, a.phone AS phone, (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS items_count '
            . self::FROM . " WHERE {$where} ORDER BY o.id DESC";
        $counts = array_map('intval', array_column(Database::get()->all('SELECT status, COUNT(*) AS c FROM orders GROUP BY status'), 'c', 'status'));

        return $this->adminView('admin/orders/index', [
            'orders' => Pager::query($request, $sql, $params, (int) cfg('abiha.pagination.admin', 20)),
            'filters' => $f,
            'counts' => $counts,
        ], 'Orders');
    }

    /** CSV of the orders matching the current list filters (plus optional date range). */
    public function export(Request $request): Response
    {
        $f = $this->filters($request);
        ActivityLogger::log('orders.exported', null, null, array_filter($f, static fn ($v) => $v !== null && $v !== ''));
        [$where, $params] = $this->filtered($f);
        return self::stream('orders-' . date('Ymd-His') . '.csv', 'SELECT o.*, a.name AS name, a.phone AS phone, a.province AS province, a.city AS city, a.address AS address ' . self::FROM . " WHERE {$where}", $params);
    }

    /** Used by Reports -> Orders: [sql, params] from ReportService::ordersQuery(). */
    public static function exportRange(string $name, string $sql, array $params): Response
    {
        return self::stream($name, $sql, $params);
    }

    public function show(Request $request): Response
    {
        $order = $this->order($request);
        $db = Database::get();
        $id = (int) $order['id'];
        $order['history'] = $db->all('SELECT h.*, u.name AS user_name FROM order_status_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.order_id = ? ORDER BY h.id DESC', [$id]);
        $order['payments'] = $db->all('SELECT p.*, u.name AS verifier_name FROM payments p LEFT JOIN users u ON u.id = p.verified_by WHERE p.order_id = ? ORDER BY p.id', [$id]);
        $order['transactions'] = $db->all('SELECT t.*, u.name AS user_name FROM payment_transactions t LEFT JOIN users u ON u.id = t.user_id WHERE t.order_id = ? ORDER BY t.id', [$id]);
        $order['events'] = $order['shipment']
            ? $db->all('SELECT e.*, u.name AS user_name FROM shipment_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.shipment_id = ? ORDER BY e.occurred_at DESC, e.id DESC', [(int) $order['shipment']['id']])
            : [];
        $order['account'] = $order['user_id'] ? $db->one('SELECT id, name, email FROM users WHERE id = ?', [(int) $order['user_id']]) : null;

        return $this->adminView('admin/orders/show', [
            'couriers' => CourierManager::available(),
            'order' => $order,
            'nextStatuses' => OrderService::TRANSITIONS[$order['status']] ?? [],
            'canVerify' => in_array($order['payment_method'], ['bank_transfer', 'cod'], true) && $order['payment_status'] !== 'paid' && $order['status'] !== 'cancelled' && $order['status'] !== 'returned',
            'canRefund' => $order['payment_status'] === 'paid' && in_array($order['status'], ['cancelled', 'returned'], true),
        ], 'Order ' . $order['number']);
    }

    public function updateStatus(Request $request): Response
    {
        $order = $this->order($request);
        $data = $this->validate($request->post, [
            'status' => ['required', 'in:' . implode(',', array_keys(OrderService::STATUSES))],
            'note' => ['nullable', 'string', 'max:190'],
            'restock' => ['nullable', 'boolean'],
        ]);
        try {
            OrderService::changeStatus($order, (string) $data['status'], Auth::user(), $data['note'] ?? null, $request->bool('restock'));
        } catch (CartException $e) {
            return $this->backWithErrors(['status' => $e->getMessage()], $this->showUrl($order));
        }
        OrderMailer::statusChanged((int) $order['id'], (string) $data['status']);
        return $this->back($this->showUrl($order), 'Order status updated.');
    }

    public function updateTracking(Request $request): Response
    {
        $order = $this->order($request);
        $data = $this->validate($request->post, [
            'courier' => ['nullable', 'string', 'max:80'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'tracking_url' => ['nullable', 'url', 'max:255'],
        ]);
        $clean = static fn ($v): ?string => $v !== null ? (trim(strip_tags((string) $v)) ?: null) : null;
        OrderService::setTracking($order, $clean($data['tracking_number'] ?? null), $data['tracking_url'] ?? null, Auth::user(), $clean($data['courier'] ?? null));
        return $this->back($this->showUrl($order), 'Shipping details saved.');
    }

    /** Book the parcel through a connected courier API. Only possible when a courier integration is installed and configured. */
    public function bookShipment(Request $request): Response
    {
        $order = $this->order($request);
        $courier = CourierManager::find($request->str('courier'));
        if (!$courier) {
            throw new HttpException(404);
        }
        if ($order['tracking_number'] || !in_array($order['status'], ['confirmed', 'processing'], true)) {
            return $this->backWithErrors(['shipment' => 'Only a confirmed or processing order without a tracking number can be booked.'], $this->showUrl($order));
        }
        try {
            $shipment = $courier->createShipment($order);
        } catch (CourierException $e) {
            return $this->backWithErrors(['shipment' => $courier->label() . ': ' . $e->getMessage()], $this->showUrl($order));
        }
        $sid = OrderService::saveShipment((int) $order['id'], ['courier' => $courier->label(), 'courier_reference' => $shipment->reference, 'tracking_number' => $shipment->trackingNumber, 'tracking_url' => $shipment->trackingUrl]);
        OrderService::logShipmentEvent($sid, 'booked', 'Booked with ' . $courier->label(), 'staff', Auth::id());
        ActivityLogger::log('order.shipment_booked', 'Order', (int) $order['id'], ['courier' => $courier->code(), 'tracking_number' => $shipment->trackingNumber]);
        return $this->back($this->showUrl($order), 'Shipment booked with ' . $courier->label() . '.');
    }

    /** Staff view of a customer's payment receipt. It lives on the private disk and is only ever served through this route. */
    public function paymentProof(Request $request): Response
    {
        $order = $this->order($request);
        $p = Database::get()->one('SELECT * FROM payments WHERE id = ? AND order_id = ?', [(int) $request->params['payment'], (int) $order['id']]);
        if (!$p || empty($p['proof_path'])) {
            throw new HttpException(404);
        }
        try {
            $abs = Uploads::privatePath((string) $p['proof_path']);
        } catch (\InvalidArgumentException) {
            throw new HttpException(404);
        }
        if (!is_file($abs)) {
            throw new HttpException(404);
        }
        $isPdf = str_ends_with((string) $p['proof_path'], '.pdf');
        $ext = $isPdf ? 'pdf' : pathinfo((string) $p['proof_path'], PATHINFO_EXTENSION);
        $type = $isPdf ? 'application/pdf' : (Uploads::mime($abs) ?: 'application/octet-stream');
        return Response::file($abs, $type, !$isPdf, 'payment-proof-' . $order['number'] . '.' . $ext)
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox")
            ->header('Cache-Control', 'private, no-store');
    }

    public function verifyPayment(Request $request): Response
    {
        $order = $this->order($request);
        $data = $this->validate($request->post, ['reference' => ['nullable', 'string', 'max:120'], 'amount' => ['nullable', 'integer', 'min:0'], 'note' => ['nullable', 'string', 'max:190']]);
        try {
            PaymentService::verifyManually($order, (array) Auth::user(), $data['reference'] ?? null, isset($data['amount']) ? (int) $data['amount'] : null, $data['note'] ?? null);
        } catch (CartException $e) {
            return $this->backWithErrors(['payment' => $e->getMessage()], $this->showUrl($order));
        }
        OrderMailer::paid((int) $order['id']);
        return $this->back($this->showUrl($order), 'Payment verified.');
    }

    public function rejectPayment(Request $request): Response
    {
        $order = $this->order($request);
        $data = $this->validate($request->post, ['note' => ['nullable', 'string', 'max:190']]);
        try {
            PaymentService::rejectManually($order, (array) Auth::user(), $data['note'] ?? null);
        } catch (CartException $e) {
            return $this->backWithErrors(['payment' => $e->getMessage()], $this->showUrl($order));
        }
        return $this->back($this->showUrl($order), 'Payment marked as not received.');
    }

    /** Record (not perform) a refund once the money has been returned to the customer. */
    public function recordRefund(Request $request): Response
    {
        $order = $this->order($request);
        $data = $this->validate($request->post, ['note' => ['nullable', 'string', 'max:190']]);
        try {
            PaymentService::recordRefund($order, (array) Auth::user(), $data['note'] ?? null);
        } catch (CartException $e) {
            return $this->backWithErrors(['payment' => $e->getMessage()], $this->showUrl($order));
        }
        return $this->back($this->showUrl($order), 'Refund recorded.');
    }

    /** Printable invoice / packing slip. */
    public function invoice(Request $request): Response
    {
        return Response::html(View::partial('admin/orders/invoice', ['order' => $this->order($request)]));
    }

    /* ---------------- internals ---------------- */

    /** FROM clause: orders + their (first) shipping-address snapshot. */
    private const FROM = "FROM orders o LEFT JOIN order_addresses a ON a.id = (SELECT a2.id FROM order_addresses a2 WHERE a2.order_id = o.id AND a2.type = 'shipping' ORDER BY a2.id LIMIT 1)";

    private function order(Request $request): array
    {
        $order = OrderService::findByNumber((string) ($request->params['number'] ?? ''));
        if (!$order) {
            throw new HttpException(404);
        }
        return $order;
    }

    private function showUrl(array $order): string
    {
        return route('admin.orders.show', ['number' => $order['number']]);
    }

    private function filters(Request $request): array
    {
        return $this->validate($request->query, [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:' . implode(',', array_keys(OrderService::STATUSES))],
            'payment' => ['nullable', 'in:' . implode(',', array_keys(OrderService::PAYMENT_STATUSES))],
            'method' => ['nullable', 'string', 'max:30'],
            'from' => ['nullable', 'bail', 'string', 'date'], 'to' => ['nullable', 'bail', 'string', 'date', 'after_or_equal:from'],
        ]);
    }

    /** @return array{0:string,1:list<mixed>} WHERE clause (on aliases o / a) and its parameters */
    private function filtered(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        foreach (['status' => 'o.status', 'payment' => 'o.payment_status', 'method' => 'o.payment_method'] as $k => $col) {
            if (!empty($f[$k])) {
                $where[] = "{$col} = ?";
                $params[] = (string) $f[$k];
            }
        }
        if (!empty($f['from'])) {
            $where[] = 'o.created_at >= ?';
            $params[] = date('Y-m-d 00:00:00', (int) strtotime((string) $f['from']));
        }
        if (!empty($f['to'])) {
            $where[] = 'o.created_at <= ?';
            $params[] = date('Y-m-d 23:59:59', (int) strtotime((string) $f['to']));
        }
        if (!empty($f['q'])) {
            $like = Pager::like((string) $f['q']);
            $where[] = "(o.number LIKE ? ESCAPE '!' OR o.email LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM order_addresses s WHERE s.order_id = o.id AND s.type = 'shipping' AND (s.name LIKE ? ESCAPE '!' OR s.phone LIKE ? ESCAPE '!')))";
            array_push($params, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    /** CSV of orders (the select must provide o.* plus name, phone, province, city, address). Oldest first. */
    private static function stream(string $name, string $sql, array $params): Response
    {
        $headers = ['Order', 'Date', 'Status', 'Payment status', 'Payment method', 'Shipping method', 'Customer', 'Email', 'Phone', 'Province', 'City', 'Address',
            'Items', 'Subtotal', 'Discount', 'Coupon', 'Shipping', 'Tax included', 'Total', 'Tracking'];
        $db = Database::get();
        $rows = (static function () use ($db, $sql, $params): \Generator {
            $last = 0;
            do {
                $batch = $db->all("SELECT * FROM ({$sql}) x WHERE x.id > ? ORDER BY x.id LIMIT 500", [...$params, $last]);
                if (!$batch) {
                    break;
                }
                $ids = array_map(static fn ($o) => (int) $o['id'], $batch);
                $items = [];
                foreach ($db->all('SELECT order_id, name, quantity, options FROM order_items WHERE order_id IN ' . Database::in($ids) . ' ORDER BY id', $ids) as $i) {
                    $items[(int) $i['order_id']][] = $i['quantity'] . '× ' . $i['name'] . ($i['options'] ? " ({$i['options']})" : '');
                }
                $tracking = array_column($db->all('SELECT s.order_id, s.tracking_number FROM shipments s WHERE s.id IN (SELECT MAX(s2.id) FROM shipments s2 WHERE s2.order_id IN ' . Database::in($ids) . ' GROUP BY s2.order_id)', $ids), 'tracking_number', 'order_id');
                foreach ($batch as $o) {
                    $last = (int) $o['id'];
                    yield [$o['number'], dt((string) $o['created_at'], 'Y-m-d H:i'), $o['status'], $o['payment_status'], $o['payment_method'], $o['shipping_method_name'],
                        $o['name'], $o['email'], $o['phone'], $o['province'], $o['city'], $o['address'],
                        implode('; ', $items[$last] ?? []),
                        (int) $o['subtotal'], (int) $o['discount'], $o['coupon_code'], (int) $o['shipping_cost'], (int) $o['tax_amount'], (int) $o['total'], $tracking[$last] ?? null];
                }
            } while (count($batch) === 500);
        })();
        return InventoryController::csv($name, $headers, $rows);
    }
}
