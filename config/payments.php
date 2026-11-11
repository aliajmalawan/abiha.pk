<?php

use App\Services\Payments\BankTransferGateway;
use App\Services\Payments\CardGateway;
use App\Services\Payments\CashOnDeliveryGateway;

/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
| `gateways` maps a code to its class. `enabled` is the list customers can choose at checkout
| (comma-separated PAYMENTS_ENABLED in .env).
|
| ONLINE PROVIDERS: `card` is registered but stays disabled until configured. To add a provider,
| write a CardProviderDriver for it (or, for a wallet, an OnlinePaymentGateway) and register it below, put its credentials in
| .env (never in code) and add its code to PAYMENTS_ENABLED. An order is only ever marked paid
| after the provider's webhook signature AND the provider's API confirm the payment, see
| App\Http\Controllers\PaymentWebhookController. Nothing in this app simulates a paid order.
*/
return [
    'gateways' => [
        'cod' => CashOnDeliveryGateway::class,
        'bank_transfer' => BankTransferGateway::class,
        'card' => CardGateway::class,   // disabled until a provider driver and its credentials are configured (see `card` below)
    ],

    /*
    | Card payments (hosted provider checkout). All four values come from .env and are EMPTY by default, which
    | keeps card payments disabled. Nothing here is a real merchant credential.
    */
    'card' => [
        'driver' => env('CARD_PAYMENT_DRIVER'),             // class name of your CardProviderDriver
        'key' => env('CARD_API_KEY'),
        'secret' => env('CARD_API_SECRET'),
        'webhook_secret' => env('CARD_WEBHOOK_SECRET'),
    ],

    'enabled' => array_filter(array_map('trim', explode(',', env('PAYMENTS_ENABLED', 'cod,bank_transfer')))),
];
