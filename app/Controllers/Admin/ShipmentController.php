<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\Admin\Pager;

/** Every shipment record (courier, tracking reference, status). Details are edited on the order itself. */
final class ShipmentController extends AdminController
{
    public function index(Request $request): Response
    {
        $f = $this->validate($request->query, ['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:pending,shipped,delivered,returned']]);
        $where = ['1 = 1'];
        $params = [];
        if (!empty($f['status'])) {
            $where[] = 's.status = ?';
            $params[] = (string) $f['status'];
        }
        if (!empty($f['q'])) {
            $like = Pager::like((string) $f['q']);
            $where[] = "(s.tracking_number LIKE ? ESCAPE '!' OR s.courier LIKE ? ESCAPE '!' OR o.number LIKE ? ESCAPE '!' OR o.email LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like, $like);
        }
        $shipments = Pager::query($request,
            'SELECT s.*, o.number AS order_number FROM shipments s LEFT JOIN orders o ON o.id = s.order_id WHERE ' . implode(' AND ', $where) . ' ORDER BY s.id DESC',
            $params, (int) cfg('abiha.pagination.admin', 20));

        return $this->adminView('admin/shipments/index', ['shipments' => $shipments, 'filters' => $f], 'Shipments');
    }
}
