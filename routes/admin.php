<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\PaymentTransactionController;
use App\Http\Controllers\Admin\ShipmentController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\DiscountController;
use App\Http\Controllers\Admin\FeaturedController;
use App\Http\Controllers\Admin\PaymentSettingsController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ShippingRuleController;
use App\Http\Controllers\Admin\AttributeController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CollectionController;
use App\Http\Controllers\Admin\ContactMessageController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HomeSectionController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\NewsletterController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\ShippingMethodController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\TestimonialController;
use Illuminate\Support\Facades\Route;

/*
| Admin area. Two layers of protection on every route:
|   1. `admin` middleware: signed-in, active, staff role.
|   2. `can:<permission>`: the role must hold the specific permission (see RolesAndPermissionsSeeder).
*/
// Staff sign-in: its own page, outside the auth group.
Route::prefix('admin')->name('admin.')->middleware('guest')->group(function () {
    Route::get('login', [AdminLoginController::class, 'show'])->name('login');
    Route::post('login', [AdminLoginController::class, 'store'])->middleware('throttle:admin-login')->name('login.store');
});

Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/', DashboardController::class)->middleware('can:dashboard.view')->name('dashboard');

    // Every staff role holds dashboard.view; each person only ever sees their own alerts.
    Route::middleware('can:dashboard.view')->group(function () {
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
    });

    Route::middleware('can:products.manage')->group(function () {
        Route::resource('products', ProductController::class)->except('show')->parameters(['products' => 'product:id']);
        Route::get('featured', [FeaturedController::class, 'index'])->name('featured.index');
        Route::put('featured', [FeaturedController::class, 'update'])->name('featured.update');
        Route::get('attributes', [AttributeController::class, 'index'])->name('attributes.index');
        Route::post('attributes', [AttributeController::class, 'store'])->name('attributes.store');
        Route::delete('attributes/{attribute}', [AttributeController::class, 'destroy'])->name('attributes.destroy');
        Route::post('attributes/{attribute}/values', [AttributeController::class, 'storeValue'])->name('attributes.values.store');
        Route::delete('attribute-values/{value}', [AttributeController::class, 'destroyValue'])->name('attributes.values.destroy');
    });

    Route::middleware('can:categories.manage')->group(function () {
        Route::resource('categories', CategoryController::class)->except('show');
        Route::resource('collections', CollectionController::class)->except('show');
    });

    Route::middleware('can:inventory.manage')->prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/', [InventoryController::class, 'index'])->name('index');
        Route::post('adjust', [InventoryController::class, 'adjust'])->name('adjust');
        Route::get('ledger', [InventoryController::class, 'ledger'])->name('ledger');
        Route::get('export', [InventoryController::class, 'export'])->name('export');
        Route::get('ledger/export', [InventoryController::class, 'exportLedger'])->name('ledger.export');
    });

    Route::prefix('orders')->name('orders.')->group(function () {
        Route::middleware('can:orders.view')->group(function () {
            Route::get('/', [OrderController::class, 'index'])->name('index');
            Route::get('export', [OrderController::class, 'export'])->name('export');   // before {order}
            Route::get('{order:number}', [OrderController::class, 'show'])->name('show');
            Route::get('{order:number}/invoice', [OrderController::class, 'invoice'])->name('invoice');
        });
        Route::middleware('can:orders.manage')->group(function () {
            Route::patch('{order:number}/status', [OrderController::class, 'updateStatus'])->name('status');
            Route::patch('{order:number}/tracking', [OrderController::class, 'updateTracking'])->name('tracking');
            Route::post('{order:number}/shipment', [OrderController::class, 'bookShipment'])->name('shipment');
        });
        Route::middleware('can:payments.verify')->group(function () {
            Route::get('{order:number}/payment/{payment}/proof', [OrderController::class, 'paymentProof'])->whereNumber('payment')->name('payment.proof');
            Route::post('{order:number}/payment/verify', [OrderController::class, 'verifyPayment'])->name('payment.verify');
            Route::post('{order:number}/payment/reject', [OrderController::class, 'rejectPayment'])->name('payment.reject');
            Route::post('{order:number}/payment/refund', [OrderController::class, 'recordRefund'])->name('payment.refund');
        });
    });

    Route::middleware('can:coupons.manage')->group(function () {
        Route::resource('coupons', CouponController::class)->except('show');
        Route::get('discounts', [DiscountController::class, 'index'])->name('discounts.index');
        Route::post('discounts/apply', [DiscountController::class, 'apply'])->name('discounts.apply');
        Route::post('discounts/clear', [DiscountController::class, 'clear'])->name('discounts.clear');
    });
    Route::middleware('can:shipping.manage')->group(function () {
        Route::resource('shipping', ShippingMethodController::class)->except('show');
        Route::post('shipping/{method}/rules', [ShippingRuleController::class, 'store'])->name('shipping.rules.store');
        Route::delete('shipping-rules/{rule}', [ShippingRuleController::class, 'destroy'])->name('shipping.rules.destroy');
    });
    Route::get('reports', [ReportController::class, 'index'])->middleware('can:reports.view')->name('reports.index');

    Route::middleware('can:customers.manage')->group(function () {
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/export', [CustomerController::class, 'export'])->name('customers.export');
        Route::get('customers/{id}', [CustomerController::class, 'show'])->whereNumber('id')->name('customers.show');
        Route::patch('customers/{id}/active', [CustomerController::class, 'toggleActive'])->whereNumber('id')->name('customers.active');
    });

    Route::middleware('can:reviews.manage')->prefix('reviews')->name('reviews.')->group(function () {
        Route::get('/', [ReviewController::class, 'index'])->name('index');
        Route::patch('{review}/approve', [ReviewController::class, 'approve'])->name('approve');
        Route::patch('{review}/unapprove', [ReviewController::class, 'unapprove'])->name('unapprove');
        Route::delete('{review}', [ReviewController::class, 'destroy'])->name('destroy');
    });

    Route::middleware('can:messages.manage')->group(function () {
        Route::get('messages', [ContactMessageController::class, 'index'])->name('messages.index');
        Route::get('messages/{message}', [ContactMessageController::class, 'show'])->name('messages.show');
        Route::patch('messages/{message}', [ContactMessageController::class, 'update'])->name('messages.update');
        Route::delete('messages/{message}', [ContactMessageController::class, 'destroy'])->name('messages.destroy');
        Route::get('newsletter', [NewsletterController::class, 'index'])->name('newsletter.index');
        Route::get('newsletter/export', [NewsletterController::class, 'export'])->name('newsletter.export');
        Route::delete('newsletter/{newsletter}', [NewsletterController::class, 'destroy'])->name('newsletter.destroy');
    });

    Route::middleware('can:content.manage')->group(function () {
        Route::resource('pages', PageController::class)->except('show');
        Route::resource('banners', BannerController::class)->except('show');
        Route::resource('testimonials', TestimonialController::class)->except('show');
        Route::get('home', [HomeSectionController::class, 'index'])->name('home.index');
        Route::post('home', [HomeSectionController::class, 'store'])->name('home.store');
        Route::patch('home', [HomeSectionController::class, 'reorder'])->name('home.reorder');
        Route::delete('home/{section}', [HomeSectionController::class, 'destroy'])->name('home.destroy');
        Route::get('home/{section}/edit', [HomeSectionController::class, 'edit'])->name('home.edit');
        Route::put('home/{section}', [HomeSectionController::class, 'update'])->name('home.update');
    });

    Route::get('shipments', [ShipmentController::class, 'index'])->middleware('can:orders.view')->name('shipments.index');
    Route::get('payment-history', [PaymentTransactionController::class, 'index'])->middleware('can:payments.verify')->name('payments.transactions');
    Route::get('menus', [MenuController::class, 'edit'])->middleware('can:content.manage')->name('menus.edit');
    Route::put('menus', [MenuController::class, 'update'])->middleware('can:content.manage')->name('menus.update');

    Route::get('payments', [PaymentSettingsController::class, 'edit'])->middleware('can:settings.manage')->name('payments.edit');
    Route::put('payments', [PaymentSettingsController::class, 'update'])->middleware('can:settings.manage')->name('payments.update');
    Route::get('settings', [SettingController::class, 'edit'])->middleware('can:settings.manage')->name('settings.edit');
    Route::put('settings', [SettingController::class, 'update'])->middleware('can:settings.manage')->name('settings.update');

    Route::middleware('can:staff.manage')->prefix('staff')->name('staff.')->group(function () {
        Route::get('/', [StaffController::class, 'index'])->name('index');
        Route::post('/', [StaffController::class, 'grant'])->name('grant');
        Route::patch('{user}', [StaffController::class, 'update'])->name('update');
    });

    Route::get('activity', [ActivityLogController::class, 'index'])->middleware('can:logs.view')->name('logs.index');
});
