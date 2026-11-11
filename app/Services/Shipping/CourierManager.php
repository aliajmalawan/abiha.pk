<?php

namespace App\Services\Shipping;

use Illuminate\Support\Collection;

/** The couriers registered in config/shipping.php. Empty by default: shipping is managed by hand. */
class CourierManager
{
    /** @return Collection<string, CourierProvider> every registered courier, configured or not */
    public function all(): Collection
    {
        return collect(config('shipping.couriers', []))
            ->map(fn ($class) => is_string($class) && class_exists($class) && is_subclass_of($class, CourierProvider::class) ? app($class) : null)
            ->filter()
            ->keyBy(fn (CourierProvider $c) => $c->code());
    }

    /** @return Collection<string, CourierProvider> only couriers with working credentials */
    public function available(): Collection
    {
        return $this->all()->filter(fn (CourierProvider $c) => $c->isConfigured());
    }

    public function find(?string $code): ?CourierProvider
    {
        return $code ? $this->available()->get($code) : null;
    }
}
