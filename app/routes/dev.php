<?php

declare(strict_types=1);

/**
 * LOCAL TEST ONLY: the stand-in for Safepay used to try the card flow on a developer's own computer.
 * Registered only when the site address is localhost and APP_ENV is not "production"; a live site never sees these routes.
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Dev\FakeSafepayController;

if (FakeSafepayController::allowed()) {
    $router->post('/dev/safepay/client/passport/v1/token', [FakeSafepayController::class, 'token'], ['nocsrf']);
    $router->post('/dev/safepay/order/payments/v3', [FakeSafepayController::class, 'create'], ['nocsrf']);
    $router->post('/dev/safepay/order/payments/v3/{token}/metadata', [FakeSafepayController::class, 'metadata'], ['nocsrf']);
    $router->get('/dev/safepay/reporter/api/v1/payments/{token}', [FakeSafepayController::class, 'report']);
    $router->get('/dev/safepay/embedded', [FakeSafepayController::class, 'page']);
    $router->post('/dev/safepay/embedded/pay', [FakeSafepayController::class, 'pay'], ['nocsrf']);
}
