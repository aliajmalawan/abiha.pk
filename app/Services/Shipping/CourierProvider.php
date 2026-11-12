<?php

declare(strict_types=1);

namespace App\Services\Shipping;

/**
 * Contract for a courier company's API (booking and tracking). Shipping works WITHOUT any of this: staff hand the
 * parcel to a courier themselves and type the courier name, tracking number and link on the order (manual mode).
 * Register implementations in app/Config/shipping.php ('couriers'); credentials go in .env.
 *
 * Rules for implementations:
 *  - never invent data: return only what the courier's API returned;
 *  - take the weight/COD amount/address from the order (server side, OrderService::find()), never from the browser;
 *  - throw CourierException with a plain-English message when the courier refuses or is unreachable.
 */
interface CourierProvider
{
    /** Stable code stored on a shipping method, e.g. "mycourier". */
    public function code(): string;

    /** Name shown to staff and customers. */
    public function label(): string;

    /** False while credentials are missing: the courier is then not offered anywhere. */
    public function isConfigured(): bool;

    /** Book a pickup/shipment for the order (OrderService::find() row). @throws CourierException */
    public function createShipment(array $order): Shipment;

    /** Ask the courier for the current status of a tracking number. Live data only. @throws CourierException */
    public function track(string $trackingNumber): TrackingResult;
}
