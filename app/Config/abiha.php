<?php

/*
|--------------------------------------------------------------------------
| ABIHA business settings (defaults only)  ->  cfg('abiha.name'), cfg('abiha.currency.symbol')
|--------------------------------------------------------------------------
| Real values (contact details, shipping, payments, policies) are NOT invented here.
| They are editable from the admin dashboard (settings table) and fall back to these.
| Admin -> Settings overrides abiha.name and abiha.currency.* at boot (App\Support\SiteSettings::apply()).
*/
return [
    'name' => 'ABIHA',
    'domain' => 'abiha.pk',
    'tagline' => null,

    'currency' => ['code' => 'PKR', 'symbol' => 'PKR', 'decimals' => 0],
    'locale' => 'en',

    'contact' => ['email' => null, 'phone' => null, 'address' => null, 'whatsapp' => null],
    'social' => ['instagram' => null, 'facebook' => null, 'tiktok' => null],

    // Development aid: flags placeholder content ("SAMPLE", "to be added"). ON outside production, OFF on a production site
    // unless you explicitly set ABIHA_SAMPLE_LABELS=true, so customers never see development placeholders.
    'show_sample_labels' => filter_var(env('ABIHA_SAMPLE_LABELS', env('APP_ENV', 'production') !== 'production' ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN),

    'pagination' => ['products' => 12, 'admin' => 20],

    // Staff sessions expire after this many idle minutes (customers use the normal session lifetime).
    'admin_idle_minutes' => (int) env('ADMIN_IDLE_MINUTES', '30'),

    // Administrative divisions of Pakistan (factual list, used for the checkout/address province field).
    'provinces' => [
        'Punjab', 'Sindh', 'Khyber Pakhtunkhwa', 'Balochistan',
        'Islamabad Capital Territory', 'Gilgit-Baltistan', 'Azad Jammu & Kashmir',
    ],
];
