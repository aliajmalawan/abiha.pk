<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Mail\Mailer;
use App\Services\Payments\BankTransferGateway;
use App\Services\Payments\PaymentManager;
use App\Support\Settings;

/**
 * The order emails (port of the old OrderMailer + OrderMail/StaffOrderMail). Mail is a courtesy on top of the order:
 * any failure is logged and never stops or undoes an order, a payment or a status change. Messages go through the
 * email outbox (Mailer::queue) and are delivered right away when possible (Mailer::flush), else by the cron.
 *
 *   OrderMailer::placed($order)   OrderMailer::paid($order)   OrderMailer::statusChanged($order, 'shipped')
 * $order: an orders row (array) or an order id.
 */
final class OrderMailer
{
    /** Statuses that tell the customer something; the quiet internal ones (confirmed, processing) do not send an email. */
    public const NOTIFY = ['shipped', 'delivered', 'cancelled', 'returned'];

    public const HEADLINES = [
        'placed' => ['Order {n} received', 'Thank you. We have received your order.'],
        'paid' => ['Payment received for order {n}', 'We have received your payment. Thank you.'],
        'shipped' => ['Your order {n} has shipped', 'Your order is on its way.'],
        'delivered' => ['Your order {n} was delivered', 'Your order has been marked as delivered.'],
        'cancelled' => ['Your order {n} was cancelled', 'Your order has been cancelled.'],
        'returned' => ['Return recorded for order {n}', 'We have recorded the return of your order.'],
    ];

    public static function placed(array|int $order): void
    {
        $order = self::fresh($order);
        if (!$order) {
            return;
        }
        self::send((string) $order['email'], 'order', self::data($order, 'placed'));
        // The owner's address: Admin -> General settings -> Order notification email, else MAIL_ADMIN_NOTIFY from .env.
        $notify = Settings::get('mail.notify');
        if (!is_string($notify) || trim($notify) === '') {
            $notify = Config::get('mail.admin_notify');
        }
        if (is_string($notify) && trim($notify) !== '') {
            self::send(trim($notify), 'staff-order', ['order' => $order, 'adminUrl' => self::adminUrl($order)]);
        }
        self::flush();
    }

    public static function paid(array|int $order): void
    {
        if ($order = self::fresh($order)) {
            self::send((string) $order['email'], 'order', self::data($order, 'paid'));
            self::flush();
        }
    }

    public static function statusChanged(array|int $order, string $status): void
    {
        if (in_array($status, self::NOTIFY, true) && ($order = self::fresh($order))) {
            self::send((string) $order['email'], 'order', self::data($order, $status));
            self::flush();
        }
    }

    /** Template data of emails/order.php (also used by tests to render a message). */
    public static function data(array $order, string $kind): array
    {
        $bank = $order['payment_method'] === 'bank_transfer' && $order['payment_status'] !== 'paid' && $kind === 'placed'
            ? trim((new BankTransferGateway())->instructions()) : null;
        return [
            'order' => $order,
            'kind' => $kind,
            'headline' => self::HEADLINES[$kind][1],
            'subjectLine' => Config::get('abiha.name') . '®: ' . str_replace('{n}', (string) $order['number'], self::HEADLINES[$kind][0]),
            'orderUrl' => route('order.confirmation', ['number' => $order['number'], 'token' => $order['access_token']]),
            'bank' => $bank !== '' ? $bank : null,
            'paymentLabel' => PaymentManager::label((string) $order['payment_method']),
            'contactEmail' => Settings::get('contact.email'),
            'contactPhone' => Settings::get('contact.phone'),
        ];
    }

    public static function adminUrl(array $order): string
    {
        return route_exists('admin.orders.show') ? route('admin.orders.show', ['id' => $order['id'], 'number' => $order['number']]) : url('/admin/orders/' . rawurlencode((string) $order['number']));
    }

    private static function fresh(array|int $order): ?array
    {
        try {
            return OrderService::find(is_array($order) ? (int) $order['id'] : $order);
        } catch (\Throwable $e) {
            Logger::error('Order email failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private static function send(string $to, string $template, array $data): void
    {
        try {
            if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
                Mailer::queue($to, $template, $data);
            }
        } catch (\Throwable $e) {
            Logger::error('Order email failed', ['template' => $template, 'error' => $e->getMessage()]);
        }
    }

    private static function flush(): void
    {
        try {
            if (!\App\Core\Database::get()->inTransaction()) {
                Mailer::flush(10);
            }
        } catch (\Throwable $e) {
            Logger::error('Order email delivery failed', ['error' => $e->getMessage()]);
        }
    }
}
