<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['shipped_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /** Add a factual entry to the timeline. Only called for things that really happened (staff action, status change, courier answer). */
    public function logEvent(string $status, ?string $description = null, string $source = 'system', ?int $userId = null, ?string $location = null): ShipmentEvent
    {
        return $this->events()->create(['status' => $status, 'description' => $description, 'source' => $source, 'user_id' => $userId, 'location' => $location, 'occurred_at' => now()]);
    }
}
