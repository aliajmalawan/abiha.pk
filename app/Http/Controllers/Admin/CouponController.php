<?php

namespace App\Http\Controllers\Admin;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class CouponController extends ResourceController
{
    protected function config(): array
    {
        return [
            'model' => Coupon::class, 'label' => 'Coupon', 'plural' => 'Coupons', 'route' => 'admin.coupons',
            'search' => ['code', 'description'], 'withCount' => ['usages'],
            'defaults' => ['type' => 'percent', 'is_active' => true, 'min_subtotal' => 0],
            'columns' => [['code', 'Code'], ['type', 'Type'], ['value', 'Value'], ['usages_count', 'Used'], ['expires_at', 'Expires', 'date'], ['is_active', 'Active', 'bool']],
            'fields' => [
                ['name' => 'code', 'label' => 'Code', 'help' => 'Letters/numbers, stored upper-case.'],
                ['name' => 'description', 'label' => 'Internal description'],
                ['name' => 'type', 'label' => 'Discount type', 'type' => 'select', 'options' => ['percent' => 'Percentage (%)', 'fixed' => 'Fixed amount (PKR)']],
                ['name' => 'value', 'label' => 'Value', 'type' => 'number', 'help' => 'Percent (1-100) or PKR amount.'],
                ['name' => 'min_subtotal', 'label' => 'Minimum order subtotal (PKR)', 'type' => 'number'],
                ['name' => 'max_discount', 'label' => 'Maximum discount (PKR)', 'type' => 'number', 'help' => 'Optional cap for percentage coupons.'],
                ['name' => 'usage_limit', 'label' => 'Total redemption limit', 'type' => 'number', 'help' => 'Blank = unlimited.'],
                ['name' => 'per_customer_limit', 'label' => 'Limit per customer', 'type' => 'number', 'help' => 'Counted by account or checkout email. Blank = unlimited.'],
                ['name' => 'categories', 'label' => 'Only for these categories (optional)', 'type' => 'multiselect', 'relation' => 'categories',
                    'options' => fn () => Category::query()->orderBy('name')->pluck('name', 'id')->all(), 'help' => 'Leave empty for every category. A chosen category includes its sub-categories.'],
                ['name' => 'products', 'label' => 'Only for these products (optional)', 'type' => 'multiselect', 'relation' => 'products',
                    'options' => fn () => Product::query()->orderBy('name')->get(['id', 'name', 'sku'])->mapWithKeys(fn ($p) => [$p->id => $p->name.' ('.$p->sku.')'])->all(), 'help' => 'The discount only applies to these items. Leave both lists empty for the whole bag.'],
                ['name' => 'starts_at', 'label' => 'Starts', 'type' => 'datetime'],
                ['name' => 'expires_at', 'label' => 'Expires', 'type' => 'datetime'],
                ['name' => 'is_active', 'label' => 'Active', 'type' => 'checkbox'],
            ],
            'rules' => fn (?Model $m) => [
                'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($m?->id)],
                'description' => ['nullable', 'string', 'max:190'],
                'type' => ['required', 'in:percent,fixed'],
                'value' => ['required', 'integer', 'min:1', request('type') === 'percent' ? 'max:100' : 'max:10000000'],
                'min_subtotal' => ['nullable', 'integer', 'min:0', 'max:100000000'],
                'max_discount' => ['nullable', 'integer', 'min:1', 'max:100000000'],
                'usage_limit' => ['nullable', 'integer', 'min:1'],
                'per_customer_limit' => ['nullable', 'integer', 'min:1'],
                'starts_at' => ['nullable', 'bail', 'string', 'date'],
                'expires_at' => ['nullable', 'bail', 'string', 'date', 'after:starts_at'],
                'is_active' => ['nullable', 'boolean'],
                'categories' => ['nullable', 'array', 'max:200'], 'categories.*' => ['integer', 'exists:categories,id'],
                'products' => ['nullable', 'array', 'max:500'], 'products.*' => ['integer', 'exists:products,id'],
            ],
        ];
    }

    protected function beforeSave(array &$data, Model $model): void
    {
        $data['min_subtotal'] = $data['min_subtotal'] ?? 0;
    }

    protected function blockDelete(Model $model): ?string
    {
        return $model->usages()->exists() ? 'This coupon has been redeemed, so it is kept for order history. Deactivate it instead.' : null;
    }
}
