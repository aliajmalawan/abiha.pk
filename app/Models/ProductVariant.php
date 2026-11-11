<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductVariant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'attribute_value_product_variant');
    }

    /** The value this variant has for an attribute slug ('size', 'color'), or null. */
    public function option(string $attributeSlug): ?AttributeValue
    {
        return $this->attributeValues->first(fn (AttributeValue $v) => $v->attribute?->slug === $attributeSlug);
    }

    public function getSizeAttribute(): ?string
    {
        return $this->option('size')?->value;
    }

    public function getColorAttribute(): ?string
    {
        return $this->option('color')?->value;
    }

    public function getColorHexAttribute(): ?string
    {
        return $this->option('color')?->color_hex;
    }

    public function getEffectivePriceAttribute(): int
    {
        return $this->price ?? $this->product->price;
    }

    public function getEffectiveComparePriceAttribute(): ?int
    {
        $compare = $this->compare_price ?? ($this->price === null ? $this->product->compare_price : null);

        return $compare && $compare > $this->effective_price ? $compare : null;
    }

    /** "Size: M / Colour: Black", ordered by attribute sort order. */
    public function getLabelAttribute(): string
    {
        return $this->attributeValues
            ->sortBy(fn ($v) => $v->attribute?->sort_order ?? 0)
            ->map(fn ($v) => ($v->attribute?->name ?? 'Option').': '.$v->value)
            ->implode(' / ');
    }
}
