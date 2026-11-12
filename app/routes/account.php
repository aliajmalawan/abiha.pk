<?php

declare(strict_types=1);

/*
 * Slice A: auth, customer account, content pages, newsletter, sitemap/robots (same URLs and names as the old routes/web.php).
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Account\AccountController;
use App\Controllers\Account\AddressController;
use App\Controllers\Account\OrderController as AccountOrderController;
use App\Controllers\Auth\LoginController;
use App\Controllers\Auth\PasswordResetController;
use App\Controllers\Auth\RegisterController;
use App\Controllers\Store\NewsletterController;
use App\Controllers\Store\PageController;
use App\Controllers\Store\SeoController;

/* ---------- Search engines ---------- */
$router->get('/sitemap.xml', [SeoController::class, 'sitemap'], [], 'sitemap');
$router->get('/robots.txt', [SeoController::class, 'robots'], [], 'robots');

/* ---------- Content ---------- */
$router->post('/newsletter', [NewsletterController::class, 'store'], ['throttle:newsletter'], 'newsletter');
$router->get('/shipping-returns', [PageController::class, 'shippingReturns'], [], 'page.shipping');
$router->get('/contact', [PageController::class, 'contact'], [], 'contact');
$router->post('/contact', [PageController::class, 'sendContact'], ['throttle:contact'], 'contact.send');
$router->get('/pages/{slug}', [PageController::class, 'show'], [], 'page');

/* ---------- Auth ---------- */
$router->get('/login', [LoginController::class, 'show'], ['guest'], 'login');
$router->post('/login', [LoginController::class, 'store'], ['guest', 'throttle:login']);
$router->get('/register', [RegisterController::class, 'show'], ['guest'], 'register');
$router->post('/register', [RegisterController::class, 'store'], ['guest', 'throttle:register']);
$router->get('/forgot-password', [PasswordResetController::class, 'request'], ['guest'], 'password.request');
$router->post('/forgot-password', [PasswordResetController::class, 'email'], ['guest', 'throttle:forgot-password'], 'password.email');
$router->get('/reset-password/{token}', [PasswordResetController::class, 'form'], ['guest'], 'password.reset');
$router->post('/reset-password', [PasswordResetController::class, 'update'], ['guest', 'throttle:password-reset'], 'password.update');
$router->post('/logout', [LoginController::class, 'destroy'], ['auth'], 'logout');

/* ---------- Customer account ---------- */
$router->group('/account', ['auth', 'throttle:account'], static function (App\Core\Router $r): void {
    $r->get('', [AccountController::class, 'dashboard'], [], 'account.dashboard');
    $r->get('/profile', [AccountController::class, 'profile'], [], 'account.profile');
    $r->put('/profile', [AccountController::class, 'updateProfile'], [], 'account.profile.update');
    $r->put('/password', [AccountController::class, 'updatePassword'], [], 'account.password.update');

    $r->get('/addresses', [AddressController::class, 'index'], [], 'account.addresses');
    $r->post('/addresses', [AddressController::class, 'store'], [], 'account.addresses.store');
    $r->put('/addresses/{address:\d+}', [AddressController::class, 'update'], [], 'account.addresses.update');
    $r->delete('/addresses/{address:\d+}', [AddressController::class, 'destroy'], [], 'account.addresses.destroy');

    $r->get('/orders', [AccountOrderController::class, 'index'], [], 'account.orders');
    $r->get('/orders/{number}', [AccountOrderController::class, 'show'], [], 'account.orders.show');
    $r->post('/orders/{number}/cancel', [AccountOrderController::class, 'cancel'], [], 'account.orders.cancel');
});
