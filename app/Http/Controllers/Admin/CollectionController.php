<?php

namespace App\Http\Controllers\Admin;

use App\Models\Collection;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CollectionController extends ResourceController
{
    protected function config(): array
    {
        return [
            'model' => Collection::class, 'label' => 'Collection', 'plural' => 'Collections', 'route' => 'admin.collections', 'imageDir' => 'collections',
            'search' => ['name', 'slug'], 'order' => ['sort_order', 'asc'], 'withCount' => ['products'],
            'defaults' => ['is_active' => true, 'sort_order' => 0],
            'columns' => [['name', 'Name'], ['products_count', 'Products'], ['is_trending', 'On homepage', 'bool'], ['is_active', 'Active', 'bool']],
            'fields' => [
                ['name' => 'name', 'label' => 'Name'],
                ['name' => 'slug', 'label' => 'URL slug', 'help' => 'Leave blank to generate from the name.'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rows' => 3],
                ['name' => 'image', 'label' => 'Image', 'type' => 'image', 'help' => 'JPG, PNG or WebP, up to 4 MB, at most 8000×8000 px. A picture at least 800×800 px looks sharp.'],
                ['name' => 'products', 'label' => 'Products in this collection', 'type' => 'multiselect', 'relation' => 'products',
                    'options' => fn () => Product::query()->orderBy('name')->pluck('name', 'id')->all()],
                ['name' => 'sort_order', 'label' => 'Display order', 'type' => 'number'],
                ['name' => 'is_trending', 'label' => 'Show under "Featured collections" on the homepage', 'type' => 'checkbox'],
                ['name' => 'is_active', 'label' => 'Visible', 'type' => 'checkbox'],
            ],
            'rules' => fn (?Model $m) => [
                'name' => ['required', 'string', 'max:120'],
                'slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('collections', 'slug')->ignore($m?->id)],
                'description' => ['nullable', 'string', 'max:1000'],
                'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=8000,max_height=8000'],
                'products' => ['nullable', 'array'], 'products.*' => ['integer', 'exists:products,id'],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
                'is_trending' => ['nullable', 'boolean'], 'is_active' => ['nullable', 'boolean'],
            ],
        ];
    }

    protected function beforeSave(array &$data, Model $model): void
    {
        $data['sort_order'] = $data['sort_order'] ?? 0;
        if (empty($data['slug'])) {
            $base = Str::slug($data['name']) ?: 'collection';
            $slug = $base;
            for ($i = 2; Collection::query()->where('slug', $slug)->where('id', '!=', $model->id ?? 0)->exists(); $i++) {
                $slug = $base.'-'.$i;
            }
            $data['slug'] = $slug;
        }
    }
}
