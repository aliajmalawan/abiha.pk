<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    public const ADMIN = 'admin';
    public const MANAGER = 'manager';
    public const STAFF = 'staff';
    public const PRODUCT_MANAGER = 'product_manager';
    public const INVENTORY_MANAGER = 'inventory_manager';
    public const CONTENT_MANAGER = 'content_manager';
    public const CUSTOMER = 'customer';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_staff' => 'boolean', 'is_system' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public static function customer(): self
    {
        return static::query()->where('slug', self::CUSTOMER)->firstOrFail();
    }
}
