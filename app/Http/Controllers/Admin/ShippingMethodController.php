<?php

namespace App\Http\Controllers\Admin;

use App\Models\Order;
use App\Models\ShippingMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class ShippingMethodController extends ResourceController
{
    protected function config(): array
    {
        return [
            'partial' => 'admin.shipping.rules', 'model' => ShippingMethod::class, 'label' => 'Shipping method', 'plural' => 'Shipping methods', 'route' => 'admin.shipping',
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
            'rules' => fn (?Model $m) => [
                'name' => ['required', 'string', 'max:120'],
                'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/', Rule::unique('shipping_methods', 'code')->ignore($m?->id)],
                'description' => ['nullable', 'string', 'max:190'],
                'cost' => ['required', 'integer', 'min:0', 'max:1000000'],
                'free_over' => ['nullable', 'integer', 'min:0', 'max:100000000'],
                'eta' => ['nullable', 'string', 'max:80'],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
                'is_active' => ['nullable', 'boolean'],
            ],
        ];
    }

    protected function beforeSave(array &$data, Model $model): void
    {
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_sample'] = false;                    // the owner confirmed these values
    }

    protected function blockDelete(Model $model): ?string
    {
        return Order::query()->where('shipping_method_id', $model->id)->exists() ? 'Orders used this method. Deactivate it instead of deleting.' : null;
    }
}
