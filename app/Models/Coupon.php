<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = strtoupper(trim($value));
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'coupon_category');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'coupon_product');
    }

    /** True when the coupon only applies to chosen products or categories. */
    public function isRestricted(): bool
    {
        return $this->categories()->exists() || $this->products()->exists();
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    /** Discount in whole PKR for a given subtotal (never more than the subtotal). */
    public function discountFor(int $subtotal): int
    {
        $amount = $this->type === 'percent' ? (int) floor($subtotal * $this->value / 100) : $this->value;
        if ($this->max_discount !== null) {
            $amount = min($amount, $this->max_discount);
        }

        return max(0, min($amount, $subtotal));
    }
}
