<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\ValidationException;
use App\Services\Admin\ProductService;

final class CategoryController extends ResourceController
{
    protected function config(): array
    {
        return [
            'table' => 'categories', 'model' => 'Category', 'soft' => true,
            'label' => 'Category', 'plural' => 'Categories', 'route' => 'admin.categories',
            'search' => ['name', 'slug'], 'order' => ['sort_order', 'asc'],
            'select' => [
                '(SELECT pc.name FROM categories pc WHERE pc.id = t.parent_id AND pc.deleted_at IS NULL) AS parent_name',
                '(SELECT COUNT(*) FROM products px WHERE px.category_id = t.id AND px.deleted_at IS NULL) AS products_count',
            ],
            'defaults' => ['is_active' => true, 'sort_order' => 0],
            'columns' => [['name', 'Name'], ['parent.name', 'Parent'], ['products_count', 'Products'], ['sort_order', 'Order'], ['is_featured', 'Featured', 'bool'], ['is_active', 'Active', 'bool']],
            'fields' => [
                ['name' => 'name', 'label' => 'Name'],
                ['name' => 'parent_id', 'label' => 'Parent category', 'type' => 'select', 'blank' => 'None (top level)',
                    'options' => static function (?array $m): array {
                        $exclude = $m ? ProductService::descendantIds((int) $m['id']) : [];
                        $rows = Database::get()->all('SELECT id, name FROM categories WHERE deleted_at IS NULL' . ($exclude ? ' AND id NOT IN ' . Database::in($exclude) : '') . ' ORDER BY name', $exclude);
                        return array_column($rows, 'name', 'id');
                    }],
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
            'rules' => static fn (?array $m): array => [
                'name' => 'required|string|max:120',
                'parent_id' => 'nullable|integer|exists:categories,id',
                'slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:categories,slug,' . ($m['id'] ?? 'NULL')],
                'description' => 'nullable|string|max:1000',
                'image' => 'nullable',
                'meta_title' => 'nullable|string|max:160',
                'meta_description' => 'nullable|string|max:300',
                'sort_order' => 'nullable|integer|min:0|max:9999',
                'is_featured' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
            ],
        ];
    }

    protected function beforeSave(array &$data, ?array $model): void
    {
        // A category can never become its own ancestor (would loop the tree forever).
        if ($model && !empty($data['parent_id']) && in_array((int) $data['parent_id'], ProductService::descendantIds((int) $model['id']), true)) {
            throw ValidationException::withMessages(['parent_id' => 'A category cannot be placed under itself or one of its own sub-categories.']);
        }
        $data['sort_order'] = $data['sort_order'] ?? 0;
        if (empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug('categories', (string) $data['name'], 'category', (int) ($model['id'] ?? 0));
        }
    }

    protected function blockDelete(array $row): ?string
    {
        $db = Database::get();
        if ((int) $db->value('SELECT COUNT(*) FROM products WHERE category_id = ?', [(int) $row['id']]) > 0) {
            return 'This category still has products. Move or delete them first, or hide the category instead.';
        }
        return (int) $db->value('SELECT COUNT(*) FROM categories WHERE parent_id = ? AND deleted_at IS NULL', [(int) $row['id']]) > 0
            ? 'This category has sub-categories. Move or delete them first.' : null;
    }
}
