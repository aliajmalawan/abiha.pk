<?php

namespace App\Services\Shipping;

/** A courier's own answer about a parcel. Shown to staff as received; the shop never makes events up. */
final class TrackingResult
{
    /** @param  list<array{at:?string,status:string,location:?string}>  $events  newest first, exactly as the courier reported them */
    public function __construct(public readonly string $status, public readonly array $events = [])
    {
    }
}
