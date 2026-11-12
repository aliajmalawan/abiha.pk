<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\View;

/**
 * "Who did what" (table activity_logs), port of the old App\Services\ActivityLogger.
 *
 *   ActivityLogger::log('order.status', 'Order', $order['id'], ['from' => 'pending', 'to' => 'processing']);
 *   ActivityLogger::diff($before, $after)   // [field => [old, new]] of changed fields (for update logs)
 *
 * subject_type keeps the old Laravel morph class names ("App\Models\Order"), so old and new rows read the same:
 * pass the short model name ('Order', 'Product', 'User', ...) or a full class name. Never put passwords/tokens in $properties.
 */
final class ActivityLogger
{
    public static function log(string $action, ?string $subjectType = null, int|string|null $subjectId = null, array $properties = []): int
    {
        $type = $subjectType === null ? null : (str_contains($subjectType, '\\') ? $subjectType : 'App\\Models\\' . $subjectType);
        $request = View::shared('request');
        return Database::get()->insert(
            'INSERT INTO activity_logs (user_id, action, subject_type, subject_id, properties, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                Auth::id(),
                $action,
                $type,
                $subjectId === null ? null : (int) $subjectId,
                $properties ? json_encode($properties, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                $request instanceof \App\Core\Request ? $request->ip() : null,
                now(),
            ]
        );
    }

    /**
     * Changed attributes only, as [field => [old, new]].
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public static function diff(array $before, array $after, array $hidden = ['password', 'remember_token', 'updated_at']): array
    {
        $changes = [];
        foreach ($after as $field => $new) {
            if (in_array($field, $hidden, true) || !array_key_exists($field, $before)) {
                continue;
            }
            $old = $before[$field];
            $same = (is_scalar($old) || $old === null) && (is_scalar($new) || $new === null)
                ? ($old === null) === ($new === null) && (string) $old === (string) $new      // "5" (database) equals 5 (form)
                : json_encode($old) === json_encode($new);
            if (!$same) {
                $changes[$field] = [$before[$field], $new];
            }
        }
        return $changes;
    }
}
