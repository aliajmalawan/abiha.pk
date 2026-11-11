<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** City-wise / province-wise overrides for a shipping method. City beats province beats the method default. */
class ShippingRuleController extends Controller
{
    public function store(Request $request, ShippingMethod $method): RedirectResponse
    {
        $data = $request->validate([
            'province' => ['nullable', Rule::in(config('abiha.provinces'))],
            'city' => ['nullable', 'string', 'max:80'],
            'cost' => ['required', 'integer', 'min:0', 'max:1000000'],
            'free_over' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'eta' => ['nullable', 'string', 'max:80'],
            'is_available' => ['nullable', 'boolean'],
        ]);
        $data['city'] = trim((string) ($data['city'] ?? '')) ?: null;
        $data['province'] = $data['province'] ?? null;
        $data['eta'] = trim(strip_tags((string) ($data['eta'] ?? ''))) ?: null;

        if (! $data['province'] && ! $data['city']) {
            return back()->withErrors(['city' => 'Choose a province, a city, or both.'])->withInput();
        }
        $duplicate = $method->rules()->where('province', $data['province'])
            ->when($data['city'], fn ($q, $c) => $q->whereRaw('LOWER(city) = ?', [mb_strtolower($c)]), fn ($q) => $q->whereNull('city'))->exists();
        if ($duplicate) {
            return back()->withErrors(['city' => 'A rule for that destination already exists. Delete it first to replace it.'])->withInput();
        }

        $rule = $method->rules()->create($data + ['is_available' => $request->boolean('is_available')]);
        ActivityLogger::log('shipping_rule.created', $rule, ['method' => $method->code, 'destination' => $rule->destination]);

        return back()->with('status', 'Shipping rule added.');
    }

    public function destroy(ShippingRule $rule): RedirectResponse
    {
        ActivityLogger::log('shipping_rule.deleted', $rule, ['destination' => $rule->destination]);
        $rule->delete();

        return back()->with('status', 'Shipping rule removed.');
    }
}
