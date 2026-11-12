<?php

declare(strict_types=1);

use App\Controllers\Store\HomeController;
use App\Controllers\Store\ProductController;
use App\Controllers\Store\ReviewController;
use App\Controllers\Store\ShopController;
use App\Controllers\Store\WishlistController;

/** @var App\Core\Router $router */

/* Storefront browse (slice S): same URLs and route names as the old routes/web.php. */
$router->get('/', [HomeController::class, 'index'], [], 'home');

$router->get('/shop', [ShopController::class, 'index'], [], 'shop');
$router->get('/category/{slug}', [ShopController::class, 'category'], [], 'category');
$router->get('/collections/{slug}', [ShopController::class, 'collection'], [], 'collection');
$router->get('/search/suggest', [ProductController::class, 'suggest'], ['throttle:search'], 'search.suggest');

$router->get('/products/{slug}', [ProductController::class, 'show'], [], 'product');
$router->get('/products/{slug}/quick-view', [ProductController::class, 'quickView'], [], 'product.quick');
$router->post('/products/{slug}/reviews', [ReviewController::class, 'store'], ['auth', 'throttle:review'], 'reviews.store');

$router->get('/wishlist', [WishlistController::class, 'index'], [], 'wishlist');
$router->post('/wishlist/{slug}/toggle', [WishlistController::class, 'toggle'], ['throttle:cart-write'], 'wishlist.toggle');
$router->post('/wishlist/{slug}/move-to-cart', [WishlistController::class, 'moveToCart'], ['throttle:cart-write'], 'wishlist.move');
