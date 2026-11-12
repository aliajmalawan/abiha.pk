<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;

/** Coupons (port of the old Admin\CouponController), with optional category / product restrictions. */
final class CouponController extends ResourceController
{
    /** The submitted discount type (decides the "value" maximum, like the old request('type')). */
    private static ?string $type = null;

    protected function config(): array
    {
        return [
            'table' => 'coupons', 'model' => 'Coupon', 'soft' => true,
            'label' => 'Coupon', 'plural' => 'Coupons', 'route' => 'admin.coupons',
            'search' => ['code', 'description'],
            'select' => ['(SELECT COUNT(*) FROM coupon_usages cu WHERE cu.coupon_id = t.id) AS usages_count'],
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
                ['name' => 'categories', 'label' => 'Only for these categories (optional)', 'type' => 'multiselect', 'pivot' => ['coupon_category', 'coupon_id', 'category_id'],
                    'options' => static fn () => array_column(Database::get()->all('SELECT id, name FROM categories WHERE deleted_at IS NULL ORDER BY name'), 'name', 'id'),
                    'help' => 'Leave empty for every category. A chosen category includes its sub-categories.'],
                ['name' => 'products', 'label' => 'Only for these products (optional)', 'type' => 'multiselect', 'pivot' => ['coupon_product', 'coupon_id', 'product_id'],
                    'options' => static function (): array {
                        $out = [];
                        foreach (Database::get()->all('SELECT id, name, sku FROM products WHERE deleted_at IS NULL ORDER BY name') as $p) {
                            $out[$p['id']] = $p['name'] . ' (' . $p['sku'] . ')';
                        }
                        return $out;
                    },
                    'help' => 'The discount only applies to these items. Leave both lists empty for the whole bag.'],
                ['name' => 'starts_at', 'label' => 'Starts', 'type' => 'datetime'],
                ['name' => 'expires_at', 'label' => 'Expires', 'type' => 'datetime'],
                ['name' => 'is_active', 'label' => 'Active', 'type' => 'checkbox'],
            ],
            'rules' => static fn (?array $m): array => [
                'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:coupons,code,' . ($m['id'] ?? 'NULL')],
                'description' => ['nullable', 'string', 'max:190'],
                'type' => ['required', 'in:percent,fixed'],
                'value' => ['required', 'integer', 'min:1', self::$type === 'percent' ? 'max:100' : 'max:10000000'],
                'min_subtotal' => ['nullable', 'integer', 'min:0', 'max:100000000'],
                'max_discount' => ['nullable', 'integer', 'min:1', 'max:100000000'],
                'usage_limit' => ['nullable', 'integer', 'min:1'],
                'per_customer_limit' => ['nullable', 'integer', 'min:1'],
                'starts_at' => ['nullable', 'bail', 'string', 'date'],
                'expires_at' => ['nullable', 'bail', 'string', 'date'],
                'is_active' => ['nullable', 'boolean'],
                'categories' => ['nullable', 'array', 'max:200'], 'categories.*' => ['integer', 'exists:categories,id'],
                'products' => ['nullable', 'array', 'max:500'], 'products.*' => ['integer', 'exists:products,id'],
            ],
        ];
    }

    protected function persist(Request $request, ?array $model): Response
    {
        self::$type = is_string($request->post['type'] ?? null) ? $request->post['type'] : null;
        // Laravel's after:starts_at (the core Validator only has after_or_equal)
        $s = $request->post['starts_at'] ?? null;
        $x = $request->post['expires_at'] ?? null;
        if (is_string($s) && is_string($x) && trim($s) !== '' && trim($x) !== '' && strtotime($s) !== false && strtotime($x) !== false && strtotime($x) <= strtotime($s)) {
            throw ValidationException::withMessages(['expires_at' => 'The expires at field must be a date after starts at.']);
        }
        return parent::persist($request, $model);
    }

    protected function beforeSave(array &$data, ?array $model): void
    {
        $data['code'] = strtoupper(trim((string) $data['code']));
        $data['min_subtotal'] = $data['min_subtotal'] ?? 0;
        foreach (['starts_at', 'expires_at'] as $k) {
            if (!empty($data[$k])) {
                $data[$k] = date('Y-m-d H:i:s', (int) strtotime((string) $data[$k]));
            }
        }
    }

    protected function blockDelete(array $row): ?string
    {
        return Database::get()->value('SELECT 1 FROM coupon_usages WHERE coupon_id = ? LIMIT 1', [(int) $row['id']])
            ? 'This coupon has been redeemed, so it is kept for order history. Deactivate it instead.' : null;
    }

    /** Coupons are not part of the storefront catalogue cache. */
    protected function bump(): void
    {
    }
}
