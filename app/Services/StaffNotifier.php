<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Money;

/**
 * Tells staff who can process orders that something needs them (in-app alerts under the admin bell, table
 * notifications, the old Laravel StaffAlert database notification format). A failure never affects the customer's order.
 */
final class StaffNotifier
{
    public static function newOrder(array $order): void
    {
        self::send('orders.manage', 'new_order', 'New order ' . $order['number'],
            Money::symbol() . ' ' . number_format((int) $order['total']) . ' · ' . ucwords(str_replace('_', ' ', (string) $order['payment_method'])), $order);
    }

    public static function transferReported(array $order): void
    {
        self::send('payments.verify', 'payment_reported', 'Bank transfer to verify: ' . $order['number'],
            'The customer sent a reference or receipt for ' . Money::symbol() . ' ' . number_format((int) $order['total']) . '. Check it against the bank statement.', $order);
    }

    private static function send(string $permission, string $kind, string $title, string $body, array $order): void
    {
        try {
            $db = Database::get();
            // Active staff whose role holds the permission (the Super Admin role "admin" holds all permissions).
            $ids = $db->column(
                "SELECT DISTINCT u.id FROM users u JOIN roles r ON r.id = u.role_id
                 LEFT JOIN permission_role pr ON pr.role_id = r.id LEFT JOIN permissions p ON p.id = pr.permission_id
                 WHERE u.is_active = 1 AND u.deleted_at IS NULL AND (p.slug = ? OR r.slug = 'admin')",
                [$permission]
            );
            $url = '/admin/orders/' . rawurlencode((string) $order['number']);
            $data = json_encode(['kind' => $kind, 'title' => $title, 'body' => $body, 'url' => $url], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            foreach ($ids as $id) {
                $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $db->insert('INSERT INTO notifications (id, type, notifiable_type, notifiable_id, data, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$uuid, 'App\\Notifications\\StaffAlert', 'App\\Models\\User', (int) $id, $data, now(), now()]);
            }
        } catch (\Throwable $e) {
            Logger::error('Staff notification failed', ['error' => $e->getMessage()]);
        }
    }
}
