<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Admin\Pager;

/** A staff member's own alerts (table notifications). Nobody can read or change another person's. */
final class NotificationController extends AdminController
{
    private const MINE = 'notifiable_type = ? AND notifiable_id = ?';
    private const TYPE = 'App\Models\User';

    public function index(Request $request): Response
    {
        $list = Pager::query($request, 'SELECT * FROM notifications WHERE ' . self::MINE . ' ORDER BY created_at DESC, id DESC', [self::TYPE, Auth::id()], 20);
        foreach ($list['items'] as &$n) {
            $n['data'] = is_array($d = json_decode((string) $n['data'], true)) ? $d : [];
        }
        unset($n);
        $unread = (bool) Database::get()->value('SELECT 1 FROM notifications WHERE ' . self::MINE . ' AND read_at IS NULL LIMIT 1', [self::TYPE, Auth::id()]);
        return $this->adminView('admin/notifications', ['notifications' => $list, 'hasUnread' => $unread], 'Alerts');
    }

    /** Opens the alert's page and marks it read. Only a link inside this site is followed. */
    public function read(Request $request): Response
    {
        $db = Database::get();
        $n = $db->one('SELECT * FROM notifications WHERE id = ? AND ' . self::MINE, [(string) $request->params['id'], self::TYPE, Auth::id()]);
        if (!$n) {
            throw new HttpException(404);
        }
        if ($n['read_at'] === null) {
            $db->exec('UPDATE notifications SET read_at = ?, updated_at = ? WHERE id = ?', [now(), now(), $n['id']]);
        }
        $data = json_decode((string) $n['data'], true);
        $url = is_array($data) ? (string) ($data['url'] ?? '') : '';
        return str_starts_with($url, '/') && !str_starts_with($url, '//') ? $this->redirect($url) : $this->back(route('admin.notifications.index'));
    }

    public function readAll(Request $request): Response
    {
        Database::get()->exec('UPDATE notifications SET read_at = ?, updated_at = ? WHERE ' . self::MINE . ' AND read_at IS NULL', [now(), now(), self::TYPE, Auth::id()]);
        return $this->back(route('admin.notifications.index'), 'All alerts marked as read.');
    }
}
