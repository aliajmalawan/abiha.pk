<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    // role_id / is_active are NOT mass-assignable: only staff-facing code sets them explicitly.
    protected $fillable = ['name', 'email', 'phone', 'password'];

    protected $hidden = ['password', 'remember_token'];

    /** Mirror the database default so a freshly created instance is active, not "null = disabled". */
    protected $attributes = ['is_active' => true];

    /** @var array<string>|null request-level cache */
    private ?array $permissionSlugs = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class)->orderByDesc('is_default')->latest();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->latest();
    }

    public function wishlist(): HasOne
    {
        return $this->hasOne(Wishlist::class);
    }

    /** Get-or-create the single wishlist row. */
    public function ensureWishlist(): Wishlist
    {
        // firstOrCreate + setRelation: never trusts a stale cached "no wishlist" relation (unique user_id).
        $wishlist = $this->wishlist()->firstOrCreate();
        $this->setRelation('wishlist', $wishlist);

        return $wishlist;
    }

    /* ---------- authorization ---------- */

    public function isAdmin(): bool            // "may enter the admin area"
    {
        return (bool) $this->role?->is_staff;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role?->slug === Role::ADMIN;
    }

    public function hasPermission(string $slug): bool
    {
        if (! $this->is_active || ! $this->role?->is_staff) {
            return false;
        }

        return $this->isSuperAdmin() || in_array($slug, $this->permissionSlugs(), true);
    }

    /** @return array<string> */
    public function permissionSlugs(): array
    {
        return $this->permissionSlugs ??= $this->role ? $this->role->permissions()->pluck('slug')->all() : [];
    }
}
