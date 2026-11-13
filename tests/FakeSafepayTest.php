<?php

declare(strict_types=1);

/* The local stand-in for Safepay: it works on localhost only and never on a live site; the shop's card flow runs end to end through it. */

use App\Controllers\Dev\FakeSafepayController;
use App\Core\Config;
use App\Core\Request;
use App\Services\Payments\Drivers\SafepayDriver;

T::$current = 'Local test payment page';

$origUrl = Config::get('url');
$origEnv = Config::get('env');
$origCard = Config::get('payments.card');

T::ok(FakeSafepayController::allowed(), 'allowed on localhost while testing');
Config::set('payments.card', ['dev_base_url' => 'http://localhost/abiha.pk/dev/safepay', 'environment' => 'sandbox'] + (array) $origCard);
T::eq('http://localhost/abiha.pk/dev/safepay', SafepayDriver::apiBase(), 'the driver talks to the local stand-in when told to');
T::eq('http://localhost/abiha.pk/dev/safepay', SafepayDriver::checkoutBase(), 'and sends the customer to its page');

Config::set('env', 'production');
T::ok(!FakeSafepayController::allowed(), 'never allowed when APP_ENV is production');
T::eq('https://sandbox.api.getsafepay.com', SafepayDriver::apiBase(), 'on a live site the driver ignores the local address and uses Safepay');
Config::set('env', $origEnv);

Config::set('url', 'https://abiha.pk');
T::ok(!FakeSafepayController::allowed(), 'never allowed on a real domain');
T::eq('https://sandbox.api.getsafepay.com', SafepayDriver::apiBase(), 'a real domain always uses Safepay');
T::eq(404, http('GET', '/dev/safepay/embedded?tracker=track_dev_0123456789abcdef')->status, 'the pages answer 404 on a real domain');
Config::set('url', $origUrl);

Config::set('payments.card', $origCard);
