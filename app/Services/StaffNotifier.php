<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Notifications\StaffAlert;
use Illuminate\Support\Facades\Notification;

/** Tells staff who can process orders that something needs them. A failure here must never affect the customer's order. */
class StaffNotifier
{
    public static function newOrder(Order $order): void
    {
        self::send('orders.manage', new StaffAlert(
            'new_order', 'New order '.$order->number,
            config('abiha.currency.symbol').' '.number_format($order->total).' · '.ucwords(str_replace('_', ' ', $order->payment_method)),
            'admin.orders.show', ['order' => $order->number],
        ));
    }

    public static function transferReported(Order $order): void
    {
        self::send('payments.verify', new StaffAlert(
            'payment_reported', 'Bank transfer to verify: '.$order->number,
            'The customer sent a reference or receipt for '.config('abiha.currency.symbol').' '.number_format($order->total).'. Check it against the bank statement.',
            'admin.orders.show', ['order' => $order->number],
        ));
    }

    private static function send(string $permission, StaffAlert $alert): void
    {
        try {
            $staff = User::query()->where('is_active', true)
                ->whereHas('role.permissions', fn ($q) => $q->where('slug', $permission))->get();
            Notification::send($staff, $alert);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
