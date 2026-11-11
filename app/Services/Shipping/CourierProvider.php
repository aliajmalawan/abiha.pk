<?php

namespace App\Services\Shipping;

use App\Models\Order;

/**
 * Contract for a courier company's API (booking and tracking). Shipping works WITHOUT any of this: staff hand the
 * parcel to a courier themselves and type the courier name, tracking number and link on the order (manual mode).
 *
 * A provider is written against the courier's own API documentation once the business has an account with them.
 * None ships with the app, because account credentials and the courier's terms are the owner's to supply.
 *
 * Rules for implementations:
 *  - never invent data: return only what the courier's API returned;
 *  - take the weight/COD amount/address from the Order (server side), never from the browser;
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

    /** Book a pickup/shipment for the order. @throws CourierException */
    public function createShipment(Order $order): Shipment;

    /** Ask the courier for the current status of a tracking number. Live data only, never stored as fact. @throws CourierException */
    public function track(string $trackingNumber): TrackingResult;
}
