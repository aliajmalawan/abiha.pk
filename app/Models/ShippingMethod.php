<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingMethod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_sample' => 'boolean'];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ShippingRule::class)->orderByRaw('city is null')->orderBy('province')->orderBy('city');
    }

    /** Default (no destination rule) cost. Use ShippingService::quote() when the destination is known. */
    public function costFor(int $subtotal): int
    {
        return $this->free_over !== null && $subtotal >= $this->free_over ? 0 : $this->cost;
    }
}
