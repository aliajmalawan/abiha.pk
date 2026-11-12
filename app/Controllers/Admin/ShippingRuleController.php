<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;

/** City-wise / province-wise overrides for a shipping method. City beats province beats the method default. */
final class ShippingRuleController extends AdminController
{
    public function store(Request $request): Response
    {
        $db = Database::get();
        $method = $db->one('SELECT * FROM shipping_methods WHERE id = ?', [(int) $request->params['id']]);
        if (!$method) {
            throw new HttpException(404);
        }
        $back = route('admin.shipping.edit', ['id' => $method['id']]);
        $data = $this->validate($request->post, [
            'province' => ['nullable', 'in:' . implode(',', (array) cfg('abiha.provinces', []))],
            'city' => ['nullable', 'string', 'max:80'],
            'cost' => ['required', 'integer', 'min:0', 'max:1000000'],
            'free_over' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'eta' => ['nullable', 'string', 'max:80'],
            'is_available' => ['nullable', 'boolean'],
        ]);
        $city = trim((string) ($data['city'] ?? '')) ?: null;
        $province = $data['province'] ?? null;
        $eta = trim(strip_tags((string) ($data['eta'] ?? ''))) ?: null;

        if (!$province && !$city) {
            return $this->backWithErrors(['city' => 'Choose a province, a city, or both.'], $back, true, $request->post);
        }
        $duplicate = $db->value(
            'SELECT 1 FROM shipping_rules WHERE shipping_method_id = ? AND ' . ($province === null ? 'province IS NULL' : 'province = ?')
            . ' AND ' . ($city === null ? 'city IS NULL' : 'LOWER(city) = ?') . ' LIMIT 1',
            array_values(array_filter([(int) $method['id'], $province, $city === null ? null : mb_strtolower($city)], static fn ($v) => $v !== null))
        );
        if ($duplicate) {
            return $this->backWithErrors(['city' => 'A rule for that destination already exists. Delete it first to replace it.'], $back, true, $request->post);
        }

        $id = $db->insert('INSERT INTO shipping_rules (shipping_method_id, province, city, cost, free_over, eta, is_available, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(int) $method['id'], $province, $city, (int) $data['cost'], $data['free_over'] ?? null, $eta, $request->bool('is_available') ? 1 : 0, now(), now()]);
        ActivityLogger::log('shipping_rule.created', 'ShippingRule', $id, ['method' => $method['code'], 'destination' => self::destination(['city' => $city, 'province' => $province])]);

        return $this->back($back, 'Shipping rule added.');
    }

    public function destroy(Request $request): Response
    {
        $db = Database::get();
        $rule = $db->one('SELECT * FROM shipping_rules WHERE id = ?', [(int) $request->params['id']]);
        if (!$rule) {
            throw new HttpException(404);
        }
        ActivityLogger::log('shipping_rule.deleted', 'ShippingRule', (int) $rule['id'], ['destination' => self::destination($rule)]);
        $db->exec('DELETE FROM shipping_rules WHERE id = ?', [(int) $rule['id']]);

        return $this->back(route('admin.shipping.edit', ['id' => $rule['shipping_method_id']]), 'Shipping rule removed.');
    }

    /** "Lahore, Punjab", "Lahore", "Punjab" or "-" (the old ShippingRule::destination accessor). */
    public static function destination(array $rule): string
    {
        $city = $rule['city'] ?? null;
        $province = $rule['province'] ?? null;
        return $city ? $city . ($province ? ", {$province}" : '') : ($province ?? '-');
    }
}
