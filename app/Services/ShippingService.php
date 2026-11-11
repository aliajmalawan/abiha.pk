<?php

namespace App\Services;

use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use Illuminate\Support\Collection;

class ShippingService
{
    /** @return Collection<int, ShippingMethod> */
    public function methods(): Collection
    {
        return ShippingMethod::query()->with('rules')->where('is_active', true)->orderBy('sort_order')->get();
    }

    public function find(?string $code): ?ShippingMethod
    {
        return $code ? $this->methods()->firstWhere('code', $code) : null;
    }

    public function default(): ?ShippingMethod
    {
        return $this->methods()->first();
    }

    /**
     * Shipping cost in PKR for a destination, or null when the method is not offered there.
     * Resolution: city rule > province rule > method default. $subtotal is the amount AFTER discounts.
     */
    public function quote(ShippingMethod $method, int $subtotal, ?string $province = null, ?string $city = null): ?int
    {
        $rule = $this->ruleFor($method, $province, $city);
        if (! $rule) {
            return $method->costFor($subtotal);
        }
        if (! $rule->is_available) {
            return null;
        }

        $free = $rule->free_over ?? $method->free_over;

        return $free !== null && $subtotal >= $free ? 0 : $rule->cost;
    }

    /** Delivery estimate for a destination: the city/province rule's own estimate, else the method's. */
    public function etaFor(ShippingMethod $method, ?string $province = null, ?string $city = null): ?string
    {
        $rule = $this->ruleFor($method, $province, $city);
        $eta = trim((string) ($rule?->eta ?: $method->eta));

        return $eta !== '' ? $eta : null;
    }

    public function ruleFor(ShippingMethod $method, ?string $province, ?string $city): ?ShippingRule
    {
        $province = $this->norm($province);
        $city = $this->norm($city);
        $rules = $method->relationLoaded('rules') ? $method->rules : $method->rules()->get();

        if ($city !== '') {
            $hit = $rules->first(fn (ShippingRule $r) => $r->city !== null && $this->norm($r->city) === $city && ($r->province === null || $this->norm($r->province) === $province || $province === ''));
            if ($hit) {
                return $hit;
            }
        }

        return $province !== ''
            ? $rules->first(fn (ShippingRule $r) => $r->city === null && $r->province !== null && $this->norm($r->province) === $province)
            : null;
    }

    private function norm(?string $v): string
    {
        return mb_strtolower(trim((string) $v));
    }
}
