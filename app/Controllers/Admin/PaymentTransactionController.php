<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Admin\Pager;

/** The append-only history of every payment event (attempts, provider notices, transfer reports, staff decisions, refunds). */
final class PaymentTransactionController extends AdminController
{
    public function index(Request $request): Response
    {
        $f = $this->validate($request->query, [
            'provider' => ['nullable', 'in:cod,bank_transfer,card'],
            'status' => ['nullable', 'in:pending,paid,failed,refunded'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $where = ['1 = 1'];
        $params = [];
        foreach (['provider' => 't.provider', 'status' => 't.status'] as $k => $col) {
            if (!empty($f[$k])) {
                $where[] = "{$col} = ?";
                $params[] = (string) $f[$k];
            }
        }
        if (!empty($f['q'])) {
            $like = Pager::like((string) $f['q']);
            $where[] = "(t.reference LIKE ? ESCAPE '!' OR o.number LIKE ? ESCAPE '!')";
            array_push($params, $like, $like);
        }
        $rows = Pager::query($request,
            'SELECT t.*, o.number AS order_number, u.name AS user_name FROM payment_transactions t LEFT JOIN orders o ON o.id = t.order_id LEFT JOIN users u ON u.id = t.user_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY t.id DESC', $params, (int) cfg('abiha.pagination.admin', 20));
        $totals = [];
        foreach (Database::get()->all('SELECT status, COUNT(*) AS n, SUM(amount) AS total FROM payment_transactions GROUP BY status') as $r) {
            $totals[$r['status']] = ['n' => (int) $r['n'], 'total' => (int) $r['total']];
        }

        return $this->adminView('admin/payment-history', ['rows' => $rows, 'filters' => $f, 'totals' => $totals], 'Payment history');
    }
}
