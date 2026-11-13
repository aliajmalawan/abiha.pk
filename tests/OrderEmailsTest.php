<?php

declare(strict_types=1);

/* When an order is placed: the customer gets a confirmation, and the owner gets a "new order" alert (admin setting, else MAIL_ADMIN_NOTIFY). */

use App\Core\Config;
use App\Core\Database;
use App\Mail\Mailer;
use App\Services\OrderMailer;
use App\Services\OrderService;
use App\Support\Settings;

T::$current = 'Order emails';
reset_db();
http('GET', '/');                       // loads the routes the email links need
$origNotify = Config::get('mail.admin_notify');

$id = Fx::insert('orders', ['number' => 'ABH-MAIL-0001', 'status' => 'pending', 'payment_status' => 'unpaid', 'payment_method' => 'cod', 'shipping_method_name' => 'Standard', 'email' => 'buyer@example.com', 'subtotal' => 1000, 'total' => 1000, 'access_token' => str_repeat('c', 40), 'created_at' => now()]);
$send = static function () use ($id): array {
    Database::get()->exec('DELETE FROM email_outbox');
    Mailer::$sent = [];
    OrderMailer::placed(OrderService::find($id));
    $to = array_map(static fn ($m) => $m['to'], Mailer::$sent);
    sort($to);
    return $to;
};

// owner address from the admin setting
Settings::put('mail.notify', 'owner@example.com');
Config::set('mail.admin_notify', 'envowner@example.com');
T::eq(['buyer@example.com', 'owner@example.com'], $send(), 'customer and owner both get an email (the admin setting wins)');
T::ok(count(array_filter(Mailer::$sent, static fn ($m) => $m['to'] === 'buyer@example.com' && str_contains($m['subject'], 'received'))) === 1, 'the customer email is the order confirmation');
T::ok(count(array_filter(Mailer::$sent, static fn ($m) => $m['to'] === 'owner@example.com' && str_contains($m['html'], 'ABH-MAIL-0001'))) === 1, 'the owner email names the order');

// no admin setting: MAIL_ADMIN_NOTIFY is used
Settings::forget('mail.notify');
T::eq(['buyer@example.com', 'envowner@example.com'], $send(), 'without the admin setting, the .env address is used');

// nothing set: only the customer
Config::set('mail.admin_notify', '');
T::eq(['buyer@example.com'], $send(), 'with no owner address, only the customer is emailed');

Config::set('mail.admin_notify', $origNotify);
Settings::forget('mail.notify');
