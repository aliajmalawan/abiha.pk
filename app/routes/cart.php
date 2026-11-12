<?php

declare(strict_types=1);

use App\Controllers\Store\CartController;
use App\Controllers\Store\CheckoutController;
use App\Controllers\Store\PaymentWebhookController;
use App\Controllers\Store\TrackOrderController;

/** @var App\Core\Router $router  Slice C: cart, checkout, orders, payments (same URLs and names as routes/web.php). */

/* ---------- Cart ---------- */
$router->get('/cart', [CartController::class, 'index'], [], 'cart');
$router->get('/cart/drawer', [CartController::class, 'drawer'], [], 'cart.drawer');   // HTML fragment for the slide-in bag
$router->post('/cart/items', [CartController::class, 'store'], ['throttle:cart-add'], 'cart.add');
$router->patch('/cart/items/{key}', [CartController::class, 'update'], ['throttle:cart-write'], 'cart.update');
$router->delete('/cart/items/{key}', [CartController::class, 'destroy'], ['throttle:cart-write'], 'cart.remove');
$router->post('/cart/coupon', [CartController::class, 'applyCoupon'], ['throttle:coupon'], 'cart.coupon');
$router->delete('/cart/coupon', [CartController::class, 'removeCoupon'], ['throttle:cart-write'], 'cart.coupon.remove');

/* ---------- Checkout ---------- */
$router->get('/checkout', [CheckoutController::class, 'show'], [], 'checkout');
$router->post('/checkout', [CheckoutController::class, 'store'], ['throttle:checkout'], 'checkout.store');
$router->get('/order/{number}/thank-you', [CheckoutController::class, 'confirmation'], [], 'order.confirmation');
$router->post('/order/{number}/payment-reference', [CheckoutController::class, 'paymentReference'], ['throttle:payment-reference'], 'order.payment-reference');
$router->post('/order/{number}/pay', [CheckoutController::class, 'retryPayment'], ['throttle:payment-reference'], 'order.pay');

$router->get('/track-order', [TrackOrderController::class, 'form'], [], 'track');
$router->post('/track-order', [TrackOrderController::class, 'lookup'], ['throttle:track-order'], 'track.lookup');

/* ---------- Payment provider webhooks (CSRF-exempt) ---------- */
$router->post('/webhooks/payments/{provider}', [PaymentWebhookController::class, 'handle'], ['nocsrf', 'throttle:webhooks'], 'webhooks.payments');
