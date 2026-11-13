<?php

/*
|--------------------------------------------------------------------------
| Payments  ->  cfg('payments.enabled'), cfg('payments.card.driver')
|--------------------------------------------------------------------------
| `gateways` maps a code to its class (the classes are written by the cart & orders slice under App\Services\Payments).
| `enabled` is the list customers can choose at checkout (comma-separated PAYMENTS_ENABLED in .env); the admin
| "payments.enabled" setting narrows it further.
|
| ONLINE PROVIDERS: `card` is registered but stays disabled until configured. To add a provider, write a card provider
| driver for it, put its credentials in .env (never in code or the admin) and add its code to PAYMENTS_ENABLED. An order
| is only ever marked paid after the provider's webhook signature AND the provider's API confirm the payment.
| Nothing in this app simulates a paid order.
*/
return [
    'gateways' => [
        'cod' => 'App\\Services\\Payments\\CashOnDeliveryGateway',
        'bank_transfer' => 'App\\Services\\Payments\\BankTransferGateway',
        'card' => 'App\\Services\\Payments\\CardGateway',   // disabled until a provider driver and its credentials are configured
    ],

    /*
    | Card payments (hosted provider checkout). All four values come from .env and are EMPTY by default, which
    | keeps card payments disabled. Nothing here is a real merchant credential.
    */
    'card' => [
        'driver' => env('CARD_PAYMENT_DRIVER'),             // class name of your card provider driver
        'key' => env('CARD_API_KEY'),
        'secret' => env('CARD_API_SECRET'),
        'webhook_secret' => env('CARD_WEBHOOK_SECRET'),
        'environment' => env('CARD_ENVIRONMENT', 'sandbox'),   // sandbox while testing, production when live
    ],

    'enabled' => array_values(array_filter(array_map('trim', explode(',', (string) env('PAYMENTS_ENABLED', 'cod,bank_transfer'))))),
];
