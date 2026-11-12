<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;

/** Shipping methods (port of the old Admin\ShippingMethodController). City/province rules are edited under the form. */
final class ShippingMethodController extends ResourceController
{
    protected function config(): array
    {
        return [
            'table' => 'shipping_methods', 'model' => 'ShippingMethod', 'partial' => 'admin/shipping/rules',
            'label' => 'Shipping method', 'plural' => 'Shipping methods', 'route' => 'admin.shipping',
            'search' => ['name', 'code'], 'order' => ['sort_order', 'asc'],
            'defaults' => ['cost' => 0, 'is_active' => true, 'sort_order' => 0],
            'columns' => [['name', 'Name'], ['cost', 'Cost (PKR)', 'money'], ['free_over', 'Free over', 'money'], ['eta', 'Delivery time'], ['is_sample', 'Sample', 'bool'], ['is_active', 'Active', 'bool']],
            'fields' => [
                ['name' => 'name', 'label' => 'Name'],
                ['name' => 'code', 'label' => 'Code', 'help' => 'Short unique id, e.g. standard. Cannot be changed to one already in use.'],
                ['name' => 'description', 'label' => 'Description'],
                ['name' => 'cost', 'label' => 'Shipping cost (PKR)', 'type' => 'number'],
                ['name' => 'free_over', 'label' => 'Free shipping when order is at least (PKR)', 'type' => 'number', 'help' => 'Compared against subtotal minus discounts. Blank = never free.'],
                ['name' => 'eta', 'label' => 'Estimated delivery time', 'help' => 'e.g. 3-5 working days'],
                ['name' => 'sort_order', 'label' => 'Display order', 'type' => 'number'],
                ['name' => 'is_active', 'label' => 'Available at checkout', 'type' => 'checkbox'],
            ],
            'rules' => static fn (?array $m): array => [
                'name' => ['required', 'string', 'max:120'],
                'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/', 'unique:shipping_methods,code,' . ($m['id'] ?? 'NULL')],
                'description' => ['nullable', 'string', 'max:190'],
                'cost' => ['required', 'integer', 'min:0', 'max:1000000'],
                'free_over' => ['nullable', 'integer', 'min:0', 'max:100000000'],
                'eta' => ['nullable', 'string', 'max:80'],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
                'is_active' => ['nullable', 'boolean'],
            ],
        ];
    }

    protected function beforeSave(array &$data, ?array $model): void
    {
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_sample'] = 0;                    // the owner confirmed these values
    }

    protected function blockDelete(array $row): ?string
    {
        return Database::get()->value('SELECT 1 FROM orders WHERE shipping_method_id = ? LIMIT 1', [(int) $row['id']])
            ? 'Orders used this method. Deactivate it instead of deleting.' : null;
    }
}
