<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\AddressController;
use App\Http\Controllers\Account\OrderController as AccountOrderController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\NewsletterController;
use App\Http\Controllers\Storefront\PageController;
use App\Http\Controllers\Storefront\ProductController;
use App\Http\Controllers\Storefront\ReviewController;
use App\Http\Controllers\Storefront\ShopController;
use App\Http\Controllers\Storefront\TrackOrderController;
use App\Http\Controllers\Storefront\WishlistController;
use Illuminate\Support\Facades\Route;

/* ---------- Search engines ---------- */
Route::get('/sitemap.xml', [\App\Http\Controllers\SeoController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [\App\Http\Controllers\SeoController::class, 'robots'])->name('robots');

/* ---------- Storefront ---------- */
Route::get('/', HomeController::class)->name('home');

Route::get('/shop', [ShopController::class, 'index'])->name('shop');
Route::get('/category/{category:slug}', [ShopController::class, 'category'])->name('category');
Route::get('/collections/{collection:slug}', [ShopController::class, 'collection'])->name('collection');
Route::get('/search/suggest', [ProductController::class, 'suggest'])->middleware('throttle:search')->name('search.suggest');

Route::get('/products/{product:slug}', [ProductController::class, 'show'])->name('product');
Route::get('/products/{product:slug}/quick-view', [ProductController::class, 'quickView'])->name('product.quick');
Route::post('/products/{product:slug}/reviews', [ReviewController::class, 'store'])->middleware(['auth', 'throttle:review'])->name('reviews.store');

/* ---------- Cart / wishlist ---------- */
Route::get('/cart', [CartController::class, 'index'])->name('cart');
Route::get('/cart/drawer', [CartController::class, 'drawer'])->name('cart.drawer');   // HTML fragment for the slide-in bag
Route::post('/cart/items', [CartController::class, 'store'])->middleware('throttle:cart-add')->name('cart.add');
Route::patch('/cart/items/{key}', [CartController::class, 'update'])->middleware('throttle:cart-write')->name('cart.update');
Route::delete('/cart/items/{key}', [CartController::class, 'destroy'])->middleware('throttle:cart-write')->name('cart.remove');
Route::post('/cart/coupon', [CartController::class, 'applyCoupon'])->middleware('throttle:coupon')->name('cart.coupon');
Route::delete('/cart/coupon', [CartController::class, 'removeCoupon'])->middleware('throttle:cart-write')->name('cart.coupon.remove');

Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist');
Route::post('/wishlist/{product:slug}/toggle', [WishlistController::class, 'toggle'])->middleware('throttle:cart-write')->name('wishlist.toggle');
Route::post('/wishlist/{product:slug}/move-to-cart', [WishlistController::class, 'moveToCart'])->middleware('throttle:cart-write')->name('wishlist.move');

/* ---------- Checkout ---------- */
Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout');
Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:checkout')->name('checkout.store');
Route::get('/order/{order:number}/thank-you', [CheckoutController::class, 'confirmation'])->name('order.confirmation');
Route::post('/order/{order:number}/payment-reference', [CheckoutController::class, 'paymentReference'])->middleware('throttle:payment-reference')->name('order.payment-reference');
Route::post('/order/{order:number}/pay', [CheckoutController::class, 'retryPayment'])->middleware('throttle:payment-reference')->name('order.pay');

Route::get('/track-order', [TrackOrderController::class, 'form'])->name('track');
Route::post('/track-order', [TrackOrderController::class, 'lookup'])->middleware('throttle:track-order')->name('track.lookup');

Route::post('/newsletter', NewsletterController::class)->middleware('throttle:newsletter')->name('newsletter');
Route::get('/shipping-returns', [PageController::class, 'shippingReturns'])->name('page.shipping');
Route::get('/contact', [PageController::class, 'contact'])->name('contact');
Route::post('/contact', [PageController::class, 'sendContact'])->middleware('throttle:contact')->name('contact.send');
Route::get('/pages/{page:slug}', [PageController::class, 'show'])->name('page');

/* ---------- Auth ---------- */
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:register');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:forgot-password')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'form'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:password-reset')->name('password.update');
});
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

/* ---------- Customer account ---------- */
Route::middleware(['auth', 'throttle:account'])->prefix('account')->name('account.')->group(function () {
    Route::get('/', [AccountController::class, 'dashboard'])->name('dashboard');
    Route::get('/profile', [AccountController::class, 'profile'])->name('profile');
    Route::put('/profile', [AccountController::class, 'updateProfile'])->name('profile.update');
    Route::put('/password', [AccountController::class, 'updatePassword'])->name('password.update');

    Route::get('/addresses', [AddressController::class, 'index'])->name('addresses');
    Route::post('/addresses', [AddressController::class, 'store'])->name('addresses.store');
    Route::put('/addresses/{address}', [AddressController::class, 'update'])->name('addresses.update');
    Route::delete('/addresses/{address}', [AddressController::class, 'destroy'])->name('addresses.destroy');

    Route::get('/orders', [AccountOrderController::class, 'index'])->name('orders');
    Route::get('/orders/{order:number}', [AccountOrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order:number}/cancel', [AccountOrderController::class, 'cancel'])->name('orders.cancel');
});

/* ---------- Payment provider webhooks (CSRF-exempt, see bootstrap/app.php) ---------- */
Route::post('/webhooks/payments/{provider}', PaymentWebhookController::class)->middleware('throttle:webhooks')->name('webhooks.payments');

/* Internal design-system reference, never exposed in production */
if (! app()->isProduction()) {
    Route::view('/design-system', 'design-system')->name('design-system');
}
