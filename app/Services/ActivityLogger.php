<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /** Record who did what. Never put passwords/tokens in $properties. */
    public static function log(string $action, ?Model $subject = null, array $properties = []): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
            'ip_address' => request()?->ip(),
        ]);
    }

    /** Changed attributes only, as [field => [old, new]] (for update logs). */
    public static function diff(Model $model, array $hidden = ['password', 'remember_token', 'updated_at']): array
    {
        $changes = [];
        foreach ($model->getChanges() as $field => $new) {
            if (! in_array($field, $hidden, true)) {
                $changes[$field] = [$model->getOriginal($field), $new];
            }
        }

        return $changes;
    }
}
