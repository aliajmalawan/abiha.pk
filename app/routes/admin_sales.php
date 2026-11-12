<?php

declare(strict_types=1);

use App\Controllers\Admin\ActivityLogController;
use App\Controllers\Admin\AdminLoginController;
use App\Controllers\Admin\ContactMessageController;
use App\Controllers\Admin\CouponController;
use App\Controllers\Admin\CustomerController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\NewsletterController;
use App\Controllers\Admin\NotificationController;
use App\Controllers\Admin\OrderController;
use App\Controllers\Admin\PaymentSettingsController;
use App\Controllers\Admin\PaymentTransactionController;
use App\Controllers\Admin\ReportController;
use App\Controllers\Admin\ShipmentController;
use App\Controllers\Admin\ShippingMethodController;
use App\Controllers\Admin\ShippingRuleController;
use App\Controllers\Admin\StaffController;

/** @var App\Core\Router $router */

// Slice M2: admin sales, operations, staff and sign-in (same URLs, names and permissions as the old routes/admin.php).
$mw = static fn (string $perm): array => ['auth', 'staff', 'perm:' . $perm];

// Staff sign-in: its own page, outside the protected area (sign out is the shared POST /logout).
$router->get('/admin/login', [AdminLoginController::class, 'show'], ['guest'], 'admin.login');
$router->post('/admin/login', [AdminLoginController::class, 'store'], ['guest', 'throttle:admin-login'], 'admin.login.store');

// dashboard.view (every staff role) + each person's own alerts
$router->get('/admin', [DashboardController::class, 'index'], $mw('dashboard.view'), 'admin.dashboard');
$router->get('/admin/notifications', [NotificationController::class, 'index'], $mw('dashboard.view'), 'admin.notifications.index');
$router->post('/admin/notifications/read-all', [NotificationController::class, 'readAll'], $mw('dashboard.view'), 'admin.notifications.read-all');
$router->post('/admin/notifications/{id}/read', [NotificationController::class, 'read'], $mw('dashboard.view'), 'admin.notifications.read');

// orders.view
$router->get('/admin/orders', [OrderController::class, 'index'], $mw('orders.view'), 'admin.orders.index');
$router->get('/admin/orders/export', [OrderController::class, 'export'], $mw('orders.view'), 'admin.orders.export');   // before {number}
$router->get('/admin/orders/{number}', [OrderController::class, 'show'], $mw('orders.view'), 'admin.orders.show');
$router->get('/admin/orders/{number}/invoice', [OrderController::class, 'invoice'], $mw('orders.view'), 'admin.orders.invoice');
// orders.manage
$router->patch('/admin/orders/{number}/status', [OrderController::class, 'updateStatus'], $mw('orders.manage'), 'admin.orders.status');
$router->patch('/admin/orders/{number}/tracking', [OrderController::class, 'updateTracking'], $mw('orders.manage'), 'admin.orders.tracking');
$router->post('/admin/orders/{number}/shipment', [OrderController::class, 'bookShipment'], $mw('orders.manage'), 'admin.orders.shipment');
// payments.verify
$router->get('/admin/orders/{number}/payment/{payment:\d+}/proof', [OrderController::class, 'paymentProof'], $mw('payments.verify'), 'admin.orders.payment.proof');
$router->post('/admin/orders/{number}/payment/verify', [OrderController::class, 'verifyPayment'], $mw('payments.verify'), 'admin.orders.payment.verify');
$router->post('/admin/orders/{number}/payment/reject', [OrderController::class, 'rejectPayment'], $mw('payments.verify'), 'admin.orders.payment.reject');
$router->post('/admin/orders/{number}/payment/refund', [OrderController::class, 'recordRefund'], $mw('payments.verify'), 'admin.orders.payment.refund');

// coupons.manage (sale pricing is in admin_catalog.php)
CouponController::routes($router, 'coupons', 'admin.coupons', $mw('coupons.manage'));

// shipping.manage
ShippingMethodController::routes($router, 'shipping', 'admin.shipping', $mw('shipping.manage'));
$router->post('/admin/shipping/{id:\d+}/rules', [ShippingRuleController::class, 'store'], $mw('shipping.manage'), 'admin.shipping.rules.store');
$router->delete('/admin/shipping-rules/{id:\d+}', [ShippingRuleController::class, 'destroy'], $mw('shipping.manage'), 'admin.shipping.rules.destroy');

// reports.view
$router->get('/admin/reports', [ReportController::class, 'index'], $mw('reports.view'), 'admin.reports.index');

// customers.manage
$router->get('/admin/customers', [CustomerController::class, 'index'], $mw('customers.manage'), 'admin.customers.index');
$router->get('/admin/customers/export', [CustomerController::class, 'export'], $mw('customers.manage'), 'admin.customers.export');
$router->get('/admin/customers/{id:\d+}', [CustomerController::class, 'show'], $mw('customers.manage'), 'admin.customers.show');
$router->patch('/admin/customers/{id:\d+}/active', [CustomerController::class, 'toggleActive'], $mw('customers.manage'), 'admin.customers.active');

// messages.manage
$router->get('/admin/messages', [ContactMessageController::class, 'index'], $mw('messages.manage'), 'admin.messages.index');
$router->get('/admin/messages/{id:\d+}', [ContactMessageController::class, 'show'], $mw('messages.manage'), 'admin.messages.show');
$router->patch('/admin/messages/{id:\d+}', [ContactMessageController::class, 'update'], $mw('messages.manage'), 'admin.messages.update');
$router->delete('/admin/messages/{id:\d+}', [ContactMessageController::class, 'destroy'], $mw('messages.manage'), 'admin.messages.destroy');
$router->get('/admin/newsletter', [NewsletterController::class, 'index'], $mw('messages.manage'), 'admin.newsletter.index');
$router->get('/admin/newsletter/export', [NewsletterController::class, 'export'], $mw('messages.manage'), 'admin.newsletter.export');
$router->delete('/admin/newsletter/{id:\d+}', [NewsletterController::class, 'destroy'], $mw('messages.manage'), 'admin.newsletter.destroy');

// orders.view / payments.verify lists
$router->get('/admin/shipments', [ShipmentController::class, 'index'], $mw('orders.view'), 'admin.shipments.index');
$router->get('/admin/payment-history', [PaymentTransactionController::class, 'index'], $mw('payments.verify'), 'admin.payments.transactions');

// settings.manage (general settings are in admin_catalog.php)
$router->get('/admin/payments', [PaymentSettingsController::class, 'edit'], $mw('settings.manage'), 'admin.payments.edit');
$router->put('/admin/payments', [PaymentSettingsController::class, 'update'], $mw('settings.manage'), 'admin.payments.update');

// staff.manage
$router->get('/admin/staff', [StaffController::class, 'index'], $mw('staff.manage'), 'admin.staff.index');
$router->post('/admin/staff', [StaffController::class, 'grant'], $mw('staff.manage'), 'admin.staff.grant');
$router->patch('/admin/staff/{id:\d+}', [StaffController::class, 'update'], $mw('staff.manage'), 'admin.staff.update');

// logs.view
$router->get('/admin/activity', [ActivityLogController::class, 'index'], $mw('logs.view'), 'admin.logs.index');

unset($mw);
