<?php

namespace App\Services;

use App\Mail\OrderMail;
use App\Mail\StaffOrderMail;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the order emails. Mail is a courtesy on top of the order: any failure (SMTP down, wrong password, bad address) is reported
 * to the log and never stops or undoes an order, a payment or a status change.
 */
class OrderMailer
{
    /** Statuses that tell the customer something; the quiet internal ones (confirmed, processing) do not send an email. */
    private const NOTIFY = ['shipped', 'delivered', 'cancelled', 'returned'];

    public static function placed(Order $order): void
    {
        self::send(fn () => Mail::to($order->email)->send(new OrderMail($order, 'placed')));

        $notify = Setting::get('mail.notify');
        if ($notify) {
            self::send(fn () => Mail::to($notify)->send(new StaffOrderMail($order)));
        }
    }

    public static function paid(Order $order): void
    {
        self::send(fn () => Mail::to($order->email)->send(new OrderMail($order, 'paid')));
    }

    public static function statusChanged(Order $order, string $status): void
    {
        if (in_array($status, self::NOTIFY, true)) {
            self::send(fn () => Mail::to($order->email)->send(new OrderMail($order->fresh(), $status)));
        }
    }

    private static function send(\Closure $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
