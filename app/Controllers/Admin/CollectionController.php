<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;

final class CollectionController extends ResourceController
{
    protected function config(): array
    {
        return [
            'table' => 'collections', 'model' => 'Collection', 'soft' => true,
            'label' => 'Collection', 'plural' => 'Collections', 'route' => 'admin.collections', 'imageDir' => 'collections',
            'search' => ['name', 'slug'], 'order' => ['sort_order', 'asc'],
            'select' => ['(SELECT COUNT(*) FROM collection_product cp JOIN products px ON px.id = cp.product_id AND px.deleted_at IS NULL WHERE cp.collection_id = t.id) AS products_count'],
            'defaults' => ['is_active' => true, 'sort_order' => 0],
            'columns' => [['name', 'Name'], ['products_count', 'Products'], ['is_trending', 'On homepage', 'bool'], ['is_active', 'Active', 'bool']],
            'fields' => [
                ['name' => 'name', 'label' => 'Name'],
                ['name' => 'slug', 'label' => 'URL slug', 'help' => 'Leave blank to generate from the name.'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rows' => 3],
                ['name' => 'image', 'label' => 'Image', 'type' => 'image', 'help' => 'JPG, PNG or WebP, up to 4 MB, at most 8000×8000 px. A picture at least 800×800 px looks sharp.'],
                ['name' => 'products', 'label' => 'Products in this collection', 'type' => 'multiselect', 'pivot' => ['collection_product', 'collection_id', 'product_id'],
                    'options' => static fn (): array => array_column(Database::get()->all('SELECT id, name FROM products WHERE deleted_at IS NULL ORDER BY name'), 'name', 'id')],
                ['name' => 'sort_order', 'label' => 'Display order', 'type' => 'number'],
                ['name' => 'is_trending', 'label' => 'Show under "Featured collections" on the homepage', 'type' => 'checkbox'],
                ['name' => 'is_active', 'label' => 'Visible', 'type' => 'checkbox'],
            ],
            'rules' => static fn (?array $m): array => [
                'name' => 'required|string|max:120',
                'slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:collections,slug,' . ($m['id'] ?? 'NULL')],
                'description' => 'nullable|string|max:1000',
                'image' => 'nullable',
                'products' => 'nullable|array', 'products.*' => 'integer|exists:products,id',
                'sort_order' => 'nullable|integer|min:0|max:9999',
                'is_trending' => 'nullable|boolean', 'is_active' => 'nullable|boolean',
            ],
        ];
    }

    protected function beforeSave(array &$data, ?array $model): void
    {
        $data['sort_order'] = $data['sort_order'] ?? 0;
        if (empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug('collections', (string) $data['name'], 'collection', (int) ($model['id'] ?? 0));
        }
    }
}
