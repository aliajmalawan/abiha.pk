<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/** Port of the old Storefront\NewsletterController: subscribe (or re-subscribe), same answer for new and existing emails. */
final class NewsletterController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $this->validate($request->post, ['email' => ['required', 'email', 'max:190']]);
        $email = mb_strtolower((string) $data['email']);
        $db = Database::get();
        $row = $db->one('SELECT * FROM newsletters WHERE email = ? LIMIT 1', [$email]);
        $now = now();
        if (!$row) {
            $db->insert('INSERT INTO newsletters (email, subscribed_at, created_at, updated_at) VALUES (?, ?, ?, ?)', [$email, $now, $now, $now]);
        } elseif ($row['unsubscribed_at']) {
            $db->exec('UPDATE newsletters SET unsubscribed_at = NULL, subscribed_at = ?, updated_at = ? WHERE id = ?', [$now, $now, $row['id']]);   // re-subscribe
        }

        // Same response whether new or existing (does not reveal who is subscribed).
        $message = 'Thank you for subscribing.';
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'message' => $message]);
        }
        Session::flash('newsletter', $message);
        return $this->back(route('home'));
    }
}
