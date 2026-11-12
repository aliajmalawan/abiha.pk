<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;
use App\Services\Admin\Pager;

/** Contact-form messages (port of the old Admin\ContactMessageController). */
final class ContactMessageController extends AdminController
{
    public function index(Request $request): Response
    {
        $f = $this->validate($request->query, ['status' => ['nullable', 'in:new,read,replied']]);
        $status = $f['status'] ?? null;
        $messages = Pager::query($request, 'SELECT * FROM contact_messages' . ($status ? ' WHERE status = ?' : '') . ' ORDER BY id DESC', $status ? [$status] : [], (int) cfg('abiha.pagination.admin', 20));
        return $this->adminView('admin/messages/index', ['messages' => $messages, 'status' => $status], 'Messages');
    }

    public function show(Request $request): Response
    {
        $message = $this->message($request);
        if ($message['status'] === 'new') {
            Database::get()->exec("UPDATE contact_messages SET status = 'read', updated_at = ? WHERE id = ?", [now(), (int) $message['id']]);
            $message['status'] = 'read';
        }
        return $this->adminView('admin/messages/show', ['message' => $message], 'Message');
    }

    public function update(Request $request): Response
    {
        $message = $this->message($request);
        $data = $this->validate($request->post, ['status' => ['required', 'in:new,read,replied']]);
        Database::get()->exec('UPDATE contact_messages SET status = ?, updated_at = ? WHERE id = ?', [(string) $data['status'], now(), (int) $message['id']]);
        return $this->back(route('admin.messages.show', ['id' => $message['id']]), 'Message updated.');
    }

    public function destroy(Request $request): Response
    {
        $message = $this->message($request);
        ActivityLogger::log('message.deleted', 'ContactMessage', (int) $message['id'], ['from' => $message['email']]);
        Database::get()->exec('DELETE FROM contact_messages WHERE id = ?', [(int) $message['id']]);
        return $this->redirect(route('admin.messages.index'), 'Message deleted.');
    }

    private function message(Request $request): array
    {
        $m = Database::get()->one('SELECT * FROM contact_messages WHERE id = ?', [(int) $request->params['id']]);
        if (!$m) {
            throw new HttpException(404);
        }
        return $m;
    }
}
