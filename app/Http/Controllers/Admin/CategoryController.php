<?php

namespace App\Http\Controllers\Admin;

use App\Models\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryController extends ResourceController
{
    protected function config(): array
    {
        return [
            'model' => Category::class, 'label' => 'Category', 'plural' => 'Categories', 'route' => 'admin.categories',
            'search' => ['name', 'slug'], 'order' => ['sort_order', 'asc'], 'with' => ['parent'], 'withCount' => ['products'],
            'defaults' => ['is_active' => true, 'sort_order' => 0],
            'columns' => [['name', 'Name'], ['parent.name', 'Parent'], ['products_count', 'Products'], ['sort_order', 'Order'], ['is_featured', 'Featured', 'bool'], ['is_active', 'Active', 'bool']],
            'fields' => [
                ['name' => 'name', 'label' => 'Name'],
                ['name' => 'parent_id', 'label' => 'Parent category', 'type' => 'select', 'blank' => 'None (top level)',
                    'options' => fn (?Model $m) => Category::query()->when($m?->exists, fn ($q) => $q->whereNotIn('id', $m->descendantIds()))->orderBy('name')->pluck('name', 'id')->all()],
                ['name' => 'slug', 'label' => 'URL slug', 'help' => 'Leave blank to generate from the name.'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rows' => 3],
                ['name' => 'image', 'label' => 'Image', 'type' => 'image', 'help' => 'JPG, PNG or WebP, up to 4 MB, at most 8000×8000 px. A picture at least 800×800 px looks sharp.'],
                ['name' => 'meta_title', 'label' => 'SEO title'],
                ['name' => 'meta_description', 'label' => 'SEO description', 'type' => 'textarea', 'rows' => 2],
                ['name' => 'sort_order', 'label' => 'Display order', 'type' => 'number'],
                ['name' => 'is_featured', 'label' => 'Feature on the homepage', 'type' => 'checkbox'],
                ['name' => 'is_active', 'label' => 'Visible in the shop', 'type' => 'checkbox'],
            ],
            'imageDir' => 'categories',
            'rules' => fn (?Model $m) => [
                'name' => ['required', 'string', 'max:120'],
                'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
                'slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')->ignore($m?->id)],
                'description' => ['nullable', 'string', 'max:1000'],
                'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=8000,max_height=8000'],
                'meta_title' => ['nullable', 'string', 'max:160'],
                'meta_description' => ['nullable', 'string', 'max:300'],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
                'is_featured' => ['nullable', 'boolean'],
                'is_active' => ['nullable', 'boolean'],
            ],
        ];
    }

    protected function beforeSave(array &$data, Model $model): void
    {
        // A category can never become its own ancestor (would loop the tree forever).
        if ($model->exists && ! empty($data['parent_id']) && in_array((int) $data['parent_id'], $model->descendantIds(), true)) {
            throw ValidationException::withMessages(['parent_id' => 'A category cannot be placed under itself or one of its own sub-categories.']);
        }

        $data['sort_order'] = $data['sort_order'] ?? 0;
        if (empty($data['slug'])) {
            $base = Str::slug($data['name']) ?: 'category';
            $slug = $base;
            for ($i = 2; Category::query()->where('slug', $slug)->where('id', '!=', $model->id ?? 0)->exists(); $i++) {
                $slug = $base.'-'.$i;
            }
            $data['slug'] = $slug;
        }
    }

    protected function blockDelete(Model $model): ?string
    {
        if ($model->products()->withTrashed()->exists()) {
            return 'This category still has products. Move or delete them first, or hide the category instead.';
        }

        return Category::query()->where('parent_id', $model->id)->exists() ? 'This category has sub-categories. Move or delete them first.' : null;
    }
}
