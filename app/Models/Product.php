<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'has_variants' => 'boolean', 'is_active' => 'boolean', 'is_featured' => 'boolean',
            'is_new' => 'boolean', 'is_bestseller' => 'boolean', 'is_trending' => 'boolean', 'is_sample' => 'boolean',
            'specifications' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /* ---------- relations ---------- */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    private ?array $reviewStatsMemo = null;

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function variants(): HasMany
    {
        // chaperone(): each variant is handed its parent product, so reading effective_price never re-queries it (an N+1 on every card)
        return $this->hasMany(ProductVariant::class)->chaperone('product')->with('attributeValues.attribute')->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /** Every variant including inactive ones (admin editing). */
    public function allVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->chaperone('product')->with('attributeValues.attribute')->orderBy('sort_order')->orderBy('id');
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class)->latest('id');
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class)->where('is_approved', true)->latest();
    }

    /* ---------- scopes ---------- */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        // '!' is the escape character (same meaning on MySQL and SQLite): a typed % or _ is searched for literally, not as a wildcard.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        return $q->where(fn ($w) => $w->whereRaw("name LIKE ? ESCAPE '!'", [$like])->orWhereRaw("sku LIKE ? ESCAPE '!'", [$like])->orWhereRaw("short_description LIKE ? ESCAPE '!'", [$like]));
    }


    /** Stock is running low: simple products at/below their threshold, or any variant at/below the product threshold. */
    public function scopeLowStock(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->where(fn ($x) => $x->where('has_variants', false)->where('stock', '>', 0)->whereColumn('stock', '<=', 'low_stock_threshold'))
            ->orWhere(fn ($x) => $x->where('has_variants', true)->whereHas('allVariants', fn ($v) => $v->where('stock', '>', 0)->whereColumn('product_variants.stock', '<=', 'products.low_stock_threshold'))));
    }

    /** Nothing left to sell: a simple product at 0, or a variant product with no variant in stock. */
    public function scopeOutOfStock(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->where(fn ($x) => $x->where('has_variants', false)->where('stock', '<=', 0))
            ->orWhere(fn ($x) => $x->where('has_variants', true)->whereDoesntHave('allVariants', fn ($v) => $v->where('stock', '>', 0))));
    }

    /** Eager-load everything a product card needs. */
    public function scopeForCard(Builder $q): Builder
    {
        return $q->with(['images', 'variants', 'category:id,name,slug']);
    }

    /* ---------- images ---------- */
    /** Storage path of the Nth image (for <x-picture>, which builds srcset/WebP from it). */
    public function imagePath(int $index = 0): ?string
    {
        return $this->images->values()->get($index)?->path;
    }

    public function imageUrl(int $index = 0): ?string
    {
        $img = $this->images->values()->get($index);

        return $img ? Storage::disk('public')->url($img->path) : null;
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->imageUrl(0);
    }

    public function getHoverImageUrlAttribute(): ?string
    {
        return $this->imageUrl(1);
    }

    /* ---------- pricing & stock (variant aware) ---------- */
    public function getDisplayPriceAttribute(): int
    {
        if ($this->has_variants && $this->variants->isNotEmpty()) {
            return (int) $this->variants->min(fn ($v) => $v->effective_price);
        }

        return $this->price;
    }

    public function getDisplayComparePriceAttribute(): ?int
    {
        if ($this->has_variants && $this->variants->isNotEmpty()) {
            $v = $this->variants->sortBy('effective_price')->first();

            return $v->effective_compare_price;
        }

        return $this->compare_price && $this->compare_price > $this->price ? $this->compare_price : null;
    }

    public function getPriceVariesAttribute(): bool
    {
        return $this->has_variants && $this->variants->pluck('effective_price')->unique()->count() > 1;
    }

    public function getIsOnSaleAttribute(): bool
    {
        return $this->display_compare_price !== null && $this->display_compare_price > $this->display_price;
    }

    public function getDiscountPercentAttribute(): int
    {
        return $this->is_on_sale ? (int) round((1 - $this->display_price / $this->display_compare_price) * 100) : 0;
    }

    public function getTotalStockAttribute(): int
    {
        return $this->has_variants ? (int) $this->variants->sum('stock') : max(0, $this->stock);
    }

    public function getInStockAttribute(): bool
    {
        return $this->total_stock > 0;
    }

    /**
     * Approved-review totals from ONE grouped query (never by loading every review): ['count' => n, 'average' => 4.3, 'stars' => [5 => n, …]].
     * Memoised on the instance, so the page can ask for the count, the average and the bars freely.
     */
    public function reviewStats(): array
    {
        return $this->reviewStatsMemo ??= (function () {
            $stars = $this->reviews()->reorder()->selectRaw('rating, count(*) as total')->groupBy('rating')->pluck('total', 'rating')->map(fn ($n) => (int) $n)->all();
            $count = array_sum($stars);
            $sum = array_sum(array_map(fn ($r, $n) => $r * $n, array_keys($stars), $stars));

            return ['count' => $count, 'average' => $count ? round($sum / $count, 1) : 0.0, 'stars' => $stars];
        })();
    }

    public function getReviewCountAttribute(): int
    {
        return $this->reviewStats()['count'];
    }

    public function getAverageRatingAttribute(): float
    {
        return (float) $this->reviewStats()['average'];
    }

    /* ---------- variant option helpers (drive the selectors) ---------- */
    public function getSizesAttribute(): array
    {
        return $this->variants->pluck('size')->filter()->unique()->values()->all();
    }

    public function getColorsAttribute(): array
    {
        return $this->variants->filter(fn ($v) => $v->color)->unique('color')
            ->map(fn ($v) => ['name' => $v->color, 'hex' => $v->color_hex])->values()->all();
    }

    public function getBadgeLabelAttribute(): ?string
    {
        if ($this->badge) {
            return $this->badge;
        }
        if (! $this->in_stock) {
            return 'Sold out';
        }
        if ($this->is_on_sale) {
            return 'Sale';
        }

        return $this->is_new ? 'New' : null;
    }

    /** Compact structure consumed by the Alpine product form (single source of truth for price/stock/SKU). */
    public function variantPayload(): array
    {
        return [
            'hasVariants' => $this->has_variants,
            'base' => ['sku' => $this->sku, 'price' => $this->price, 'compare' => $this->display_compare_price, 'stock' => $this->total_stock],
            'variants' => $this->variants->map(fn ($v) => [
                'id' => $v->id, 'sku' => $v->sku, 'size' => $v->size, 'color' => $v->color, 'hex' => $v->color_hex,
                'price' => $v->effective_price, 'compare' => $v->effective_compare_price, 'stock' => max(0, $v->stock),
            ])->values()->all(),
        ];
    }
}
