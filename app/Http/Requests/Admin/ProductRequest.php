<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.manage') ?? false;
    }

    public function rules(): array
    {
        $product = $this->route('product');          // null on create
        $hasVariants = $this->boolean('has_variants');

        return [
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($product?->id)],
            'sku' => ['required', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($product?->id)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:50000'],
            'specifications' => ['nullable', 'array', 'max:25'],
            'specifications.*.label' => ['nullable', 'string', 'max:60'],
            'specifications.*.value' => ['nullable', 'string', 'max:300'],
            'price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'compare_price' => ['nullable', 'integer', 'gt:price', 'max:100000000'],
            'stock' => [$hasVariants ? 'nullable' : 'required', 'integer', 'min:0', 'max:1000000'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'badge' => ['nullable', 'string', 'max:40'],
            'meta_title' => ['nullable', 'string', 'max:190'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'has_variants' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'], 'is_featured' => ['nullable', 'boolean'], 'is_new' => ['nullable', 'boolean'],
            'is_bestseller' => ['nullable', 'boolean'], 'is_trending' => ['nullable', 'boolean'],

            'images' => ['nullable', 'array', 'max:12'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=8000,max_height=8000'],
            'image_order' => ['nullable', 'array', 'max:50'], 'image_order.*' => ['integer'],   // existing image ids, first = main photo
            'remove_images' => ['nullable', 'array'], 'remove_images.*' => ['integer'],
            'image_alts' => ['nullable', 'array'], 'image_alts.*' => ['nullable', 'string', 'max:160'],   // alt text per existing image, keyed by image id

            'variants' => [$hasVariants ? 'required' : 'nullable', 'array', 'max:100'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.sku' => ['required', 'string', 'max:80', 'distinct'],
            'variants.*.price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'variants.*.compare_price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'variants.*.stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'variants.*.is_active' => ['nullable', 'boolean'],
            'variants.*.options' => ['required', 'array', 'min:1'],
            'variants.*.options.*' => ['integer', 'exists:attribute_values,id'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            // At most 12 photos per product in total (what stays + what is being added).
            $product = $this->route('product');
            $kept = $product ? $product->images()->whereNotIn('id', array_map('intval', (array) $this->input('remove_images', [])))->count() : 0;
            if ($kept + count((array) $this->file('images', [])) > 12) {
                $v->errors()->add('images', 'A product can have at most 12 photos. Remove some first.');
            }
            if (! $this->boolean('has_variants')) {
                return;
            }
            $seen = [];
            foreach ((array) $this->input('variants', []) as $i => $row) {
                // Variant SKUs must be unique across the catalogue (ignoring the variant being edited).
                $clash = \App\Models\ProductVariant::query()->where('sku', $row['sku'] ?? '')->when(! empty($row['id']), fn ($q) => $q->where('id', '!=', $row['id']))->exists();
                if ($clash || \App\Models\Product::withTrashed()->where('sku', $row['sku'] ?? '')->exists()) {
                    $v->errors()->add("variants.$i.sku", 'This SKU is already in use.');
                }
                // The same option combination cannot appear twice.
                $combo = collect($row['options'] ?? [])->sort()->implode('-');
                if (isset($seen[$combo])) {
                    $v->errors()->add("variants.$i.options", 'Another variant already has this combination of options.');
                }
                $seen[$combo] = true;
            }
        }];
    }
}
