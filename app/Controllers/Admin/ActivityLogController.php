<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\Admin\Pager;

/** Who changed what (read-only; port of the old Admin\ActivityLogController). */
final class ActivityLogController extends AdminController
{
    public function index(Request $request): Response
    {
        $f = $this->validate($request->query, ['q' => ['nullable', 'string', 'max:80'], 'user' => ['nullable', 'integer']]);
        $where = ['1 = 1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = "l.action LIKE ? ESCAPE '!'";
            $params[] = Pager::like((string) $f['q']);
        }
        if (!empty($f['user'])) {
            $where[] = 'l.user_id = ?';
            $params[] = (int) $f['user'];
        }
        $logs = Pager::query($request, 'SELECT l.*, u.name AS user_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY l.id DESC', $params, 40);
        return $this->adminView('admin/logs', ['logs' => $logs, 'filters' => $f], 'Activity log');
    }
}
