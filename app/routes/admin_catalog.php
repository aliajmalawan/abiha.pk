<?php

declare(strict_types=1);

use App\Controllers\Admin\AttributeController;
use App\Controllers\Admin\BannerController;
use App\Controllers\Admin\CategoryController;
use App\Controllers\Admin\CollectionController;
use App\Controllers\Admin\DiscountController;
use App\Controllers\Admin\FeaturedController;
use App\Controllers\Admin\HomeSectionController;
use App\Controllers\Admin\InventoryController;
use App\Controllers\Admin\MenuController;
use App\Controllers\Admin\PageController;
use App\Controllers\Admin\ProductController;
use App\Controllers\Admin\ReviewController;
use App\Controllers\Admin\SettingController;
use App\Controllers\Admin\TestimonialController;

/** @var App\Core\Router $router */

// Slice M1: admin catalogue and content (same URLs, names and permissions as the old routes/admin.php).
$mw = static fn (string $perm): array => ['auth', 'staff', 'perm:' . $perm];

// products.manage
$router->get('/admin/products', [ProductController::class, 'index'], $mw('products.manage'), 'admin.products.index');
$router->get('/admin/products/create', [ProductController::class, 'create'], $mw('products.manage'), 'admin.products.create');
$router->post('/admin/products', [ProductController::class, 'store'], $mw('products.manage'), 'admin.products.store');
$router->get('/admin/products/{id:\d+}/edit', [ProductController::class, 'edit'], $mw('products.manage'), 'admin.products.edit');
$router->put('/admin/products/{id:\d+}', [ProductController::class, 'update'], $mw('products.manage'), 'admin.products.update');
$router->patch('/admin/products/{id:\d+}', [ProductController::class, 'update'], $mw('products.manage'));
$router->delete('/admin/products/{id:\d+}', [ProductController::class, 'destroy'], $mw('products.manage'), 'admin.products.destroy');
$router->get('/admin/featured', [FeaturedController::class, 'index'], $mw('products.manage'), 'admin.featured.index');
$router->put('/admin/featured', [FeaturedController::class, 'update'], $mw('products.manage'), 'admin.featured.update');
$router->get('/admin/attributes', [AttributeController::class, 'index'], $mw('products.manage'), 'admin.attributes.index');
$router->post('/admin/attributes', [AttributeController::class, 'store'], $mw('products.manage'), 'admin.attributes.store');
$router->delete('/admin/attributes/{id:\d+}', [AttributeController::class, 'destroy'], $mw('products.manage'), 'admin.attributes.destroy');
$router->post('/admin/attributes/{id:\d+}/values', [AttributeController::class, 'storeValue'], $mw('products.manage'), 'admin.attributes.values.store');
$router->delete('/admin/attribute-values/{id:\d+}', [AttributeController::class, 'destroyValue'], $mw('products.manage'), 'admin.attributes.values.destroy');

// categories.manage
CategoryController::routes($router, 'categories', 'admin.categories', $mw('categories.manage'));
CollectionController::routes($router, 'collections', 'admin.collections', $mw('categories.manage'));

// inventory.manage
$router->get('/admin/inventory', [InventoryController::class, 'index'], $mw('inventory.manage'), 'admin.inventory.index');
$router->post('/admin/inventory/adjust', [InventoryController::class, 'adjust'], $mw('inventory.manage'), 'admin.inventory.adjust');
$router->get('/admin/inventory/ledger', [InventoryController::class, 'ledger'], $mw('inventory.manage'), 'admin.inventory.ledger');
$router->get('/admin/inventory/export', [InventoryController::class, 'export'], $mw('inventory.manage'), 'admin.inventory.export');
$router->get('/admin/inventory/ledger/export', [InventoryController::class, 'exportLedger'], $mw('inventory.manage'), 'admin.inventory.ledger.export');

// coupons.manage (sale pricing)
$router->get('/admin/discounts', [DiscountController::class, 'index'], $mw('coupons.manage'), 'admin.discounts.index');
$router->post('/admin/discounts/apply', [DiscountController::class, 'apply'], $mw('coupons.manage'), 'admin.discounts.apply');
$router->post('/admin/discounts/clear', [DiscountController::class, 'clear'], $mw('coupons.manage'), 'admin.discounts.clear');

// reviews.manage
$router->get('/admin/reviews', [ReviewController::class, 'index'], $mw('reviews.manage'), 'admin.reviews.index');
$router->patch('/admin/reviews/{id:\d+}/approve', [ReviewController::class, 'approve'], $mw('reviews.manage'), 'admin.reviews.approve');
$router->patch('/admin/reviews/{id:\d+}/unapprove', [ReviewController::class, 'unapprove'], $mw('reviews.manage'), 'admin.reviews.unapprove');
$router->delete('/admin/reviews/{id:\d+}', [ReviewController::class, 'destroy'], $mw('reviews.manage'), 'admin.reviews.destroy');

// content.manage
PageController::routes($router, 'pages', 'admin.pages', $mw('content.manage'));
BannerController::routes($router, 'banners', 'admin.banners', $mw('content.manage'));
TestimonialController::routes($router, 'testimonials', 'admin.testimonials', $mw('content.manage'));
$router->get('/admin/home', [HomeSectionController::class, 'index'], $mw('content.manage'), 'admin.home.index');
$router->post('/admin/home', [HomeSectionController::class, 'store'], $mw('content.manage'), 'admin.home.store');
$router->patch('/admin/home', [HomeSectionController::class, 'reorder'], $mw('content.manage'), 'admin.home.reorder');
$router->delete('/admin/home/{id:\d+}', [HomeSectionController::class, 'destroy'], $mw('content.manage'), 'admin.home.destroy');
$router->get('/admin/home/{id:\d+}/edit', [HomeSectionController::class, 'edit'], $mw('content.manage'), 'admin.home.edit');
$router->put('/admin/home/{id:\d+}', [HomeSectionController::class, 'update'], $mw('content.manage'), 'admin.home.update');
$router->get('/admin/menus', [MenuController::class, 'edit'], $mw('content.manage'), 'admin.menus.edit');
$router->put('/admin/menus', [MenuController::class, 'update'], $mw('content.manage'), 'admin.menus.update');

// settings.manage
$router->get('/admin/settings', [SettingController::class, 'edit'], $mw('settings.manage'), 'admin.settings.edit');
$router->put('/admin/settings', [SettingController::class, 'update'], $mw('settings.manage'), 'admin.settings.update');

unset($mw);
