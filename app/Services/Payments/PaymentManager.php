<?php

namespace App\Services\Payments;

use App\Models\Setting;
use Illuminate\Support\Collection;

class PaymentManager
{
    /** Codes the admin has switched on (Admin → Payments). Falls back to config/.env until first saved. */
    public function enabledCodes(): array
    {
        $stored = Setting::get('payments.enabled');

        return array_values(is_array($stored) ? $stored : config('payments.enabled', []));
    }

    /** @return Collection<string, PaymentGateway> keyed by code, only gateways that are enabled AND available */
    public function enabled(): Collection
    {
        return collect($this->enabledCodes())
            ->filter(fn ($code) => isset(config('payments.gateways')[$code]))
            ->map(fn ($code) => app(config('payments.gateways')[$code]))
            ->filter(fn (PaymentGateway $g) => $g->isAvailable())
            ->keyBy(fn (PaymentGateway $g) => $g->code());
    }

    /** Every registered gateway, enabled or not (admin screen). @return Collection<string, PaymentGateway> */
    public function all(): Collection
    {
        return collect(config('payments.gateways'))->map(fn ($class) => app($class))->keyBy(fn (PaymentGateway $g) => $g->code());
    }

    public function find(string $code): ?PaymentGateway
    {
        return $this->enabled()->get($code);
    }

    /** Online gateway by code regardless of "enabled" (webhooks must still be processed after disabling). */
    public function online(string $code): ?OnlinePaymentGateway
    {
        $class = config('payments.gateways')[$code] ?? null;
        $gateway = $class ? app($class) : null;

        return $gateway instanceof OnlinePaymentGateway ? $gateway : null;
    }
}
