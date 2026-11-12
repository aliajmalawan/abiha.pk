<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Services\OrderService;

/** Guest order tracking by number + checkout email (port of Storefront\TrackOrderController). */
final class TrackOrderController extends Controller
{
    public function form(Request $request): Response
    {
        return $this->view('store/track', ['order' => null], ['title' => 'Track your order', 'noindex' => true]);
    }

    /** Guests look up an order with number + the email used at checkout (both must match). */
    public function lookup(Request $request): Response
    {
        $data = $this->validate($request, [
            'number' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:190'],
        ]);
        $row = Database::get()->one('SELECT * FROM orders WHERE number = ? AND LOWER(email) = ? LIMIT 1',
            [strtoupper(trim((string) $data['number'])), mb_strtolower(trim((string) $data['email']))]);
        if (!$row) {
            // Same message whether the number or the email was wrong (no order enumeration).
            throw ValidationException::one('number', 'We could not find an order matching those details.');
        }
        return $this->view('store/track', ['order' => OrderService::load($row)], ['title' => 'Track your order', 'noindex' => true]);
    }
}
