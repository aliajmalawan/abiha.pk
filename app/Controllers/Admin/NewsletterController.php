<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;
use App\Services\Admin\Pager;

/** Newsletter subscribers (port of the old Admin\NewsletterController). */
final class NewsletterController extends AdminController
{
    public function index(Request $request): Response
    {
        $q = trim(is_string($request->query['q'] ?? null) ? $request->query['q'] : '');
        $subscribers = Pager::query($request,
            'SELECT * FROM newsletters' . ($q !== '' ? " WHERE email LIKE ? ESCAPE '!'" : '') . ' ORDER BY id DESC', $q !== '' ? [Pager::like($q)] : [], 30);
        return $this->adminView('admin/newsletter/index', [
            'subscribers' => $subscribers,
            'activeCount' => (int) Database::get()->value('SELECT COUNT(*) FROM newsletters WHERE unsubscribed_at IS NULL'),
            'q' => $q,
        ], 'Newsletter');
    }

    /** CSV of active subscribers. Spreadsheet-formula characters are neutralised (CSV injection). */
    public function export(Request $request): Response
    {
        ActivityLogger::log('newsletter.exported');
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['email', 'subscribed_at']);
        foreach (Database::get()->all('SELECT email, subscribed_at FROM newsletters WHERE unsubscribed_at IS NULL ORDER BY id') as $r) {
            $email = (string) $r['email'];
            fputcsv($out, [preg_match('/^[=+\-@\t\r]/', $email) ? "'" . $email : $email, $r['subscribed_at'] ? dt($r['subscribed_at'], 'Y-m-d H:i:s') : null]);
        }
        rewind($out);
        $body = (string) stream_get_contents($out);
        fclose($out);
        return Response::download($body, 'newsletter-subscribers-' . date('Ymd') . '.csv');
    }

    public function destroy(Request $request): Response
    {
        $db = Database::get();
        if (!$db->value('SELECT id FROM newsletters WHERE id = ?', [(int) $request->params['id']])) {
            throw new HttpException(404);
        }
        $db->exec('DELETE FROM newsletters WHERE id = ?', [(int) $request->params['id']]);
        return $this->back(route('admin.newsletter.index'), 'Subscriber removed.');
    }
}
