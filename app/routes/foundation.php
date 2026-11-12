<?php

declare(strict_types=1);

use App\Controllers\FoundationController;
use App\Core\HttpException;

/** @var App\Core\Router $router */

/*
 * Foundation fallbacks. This file is loaded LAST (see app/routes.php) and only registers a route when no slice has
 * registered that route NAME yet, so the shared chrome (header, footer, error pages, admin sidebar) can always build its
 * links with route(). Until the owning slice exists these URLs answer 404 (the home page shows a placeholder).
 * Slices never need to edit this file: registering the same name in their own file wins automatically.
 */
$fallbacks = [
    // [method, path, name]                              owner slice
    ['GET', '/shop', 'shop'],                            // S
    ['GET', '/category/{slug}', 'category'],             // S
    ['GET', '/collections/{slug}', 'collection'],        // S
    ['GET', '/search/suggest', 'search.suggest'],        // S
    ['GET', '/products/{slug}', 'product'],              // S
    ['GET', '/products/{slug}/quick-view', 'product.quick'], // S
    ['GET', '/wishlist', 'wishlist'],                    // S
    ['GET', '/cart', 'cart'],                            // C
    ['GET', '/cart/drawer', 'cart.drawer'],              // C
    ['GET', '/checkout', 'checkout'],                    // C
    ['GET', '/track-order', 'track'],                    // C
    ['POST', '/newsletter', 'newsletter'],               // A
    ['GET', '/shipping-returns', 'page.shipping'],       // A
    ['GET', '/contact', 'contact'],                      // A
    ['GET', '/pages/{slug}', 'page'],                    // A
    ['GET', '/login', 'login'],                          // A
    ['GET', '/register', 'register'],                    // A
    ['GET', '/forgot-password', 'password.request'],     // A
    ['POST', '/logout', 'logout'],                       // A
    ['GET', '/account', 'account.dashboard'],            // A
    ['GET', '/sitemap.xml', 'sitemap'],                  // A
    ['GET', '/robots.txt', 'robots'],                    // A
    ['GET', '/admin/login', 'admin.login'],              // M2
    ['GET', '/admin', 'admin.dashboard'],                // M2
    ['GET', '/admin/notifications', 'admin.notifications.index'], // M2
];
$notBuilt = static function (): never {
    throw new HttpException(404);
};
foreach ($fallbacks as [$method, $path, $name]) {
    if (!$router->has($name)) {
        $method === 'POST' ? $router->post($path, $notBuilt, [], $name) : $router->get($path, $notBuilt, [], $name);
    }
}

// Temporary home page so the chrome can be viewed; the storefront slice registers the real "home" route.
if (!$router->has('home')) {
    $router->get('/', [FoundationController::class, 'home'], [], 'home');
}
unset($fallbacks, $notBuilt, $method, $path, $name);
