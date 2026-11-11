<?php

/*
|--------------------------------------------------------------------------
| Courier integrations
|--------------------------------------------------------------------------
| Shipping charges, free-shipping thresholds, province/city rules and delivery estimates are managed in
| Admin -> Shipping. Parcels are handed to a courier and tracked by hand (Admin -> Orders -> Shipping).
|
| To connect a courier's API later, write a class implementing App\Services\Shipping\CourierProvider
| and register it here, then put its credentials in .env (read in this file only, never in the admin).
| No courier is registered, and nothing in the app invents courier names or tracking events.
|
|   'couriers' => [ \App\Services\Shipping\Couriers\YourCourier::class ],
*/
return [
    'couriers' => [],
];
