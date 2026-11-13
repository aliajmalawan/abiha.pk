<?php

declare(strict_types=1);

/*
 * Card keys entered in Admin -> Payment methods: saved encrypted, applied on the next request, never shown again,
 * never put back into a form, removable, and staff-only.
 */

use App\Auth\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Services\Payments\CardGateway;
use App\Services\Payments\CardSettings;
use App\Services\Payments\Drivers\SafepayDriver;
use App\Services\Payments\PaymentManager;
use App\Support\Secrets;
use App\Support\Settings;

T::$current = 'Card keys in the admin';
reset_db();
$db = Database::get();
$admin = Fx::user('admin');
$cust = Fx::user('customer');
$origCard = Config::get('payments.card');
$blank = ['driver' => '', 'key' => '', 'secret' => '', 'webhook_secret' => '', 'environment' => 'sandbox'];
Config::set('payments.card', $blank);

function cs(string $method, string $path, array $input, array|false $user): App\Core\Response
{
    Database::get()->exec('DELETE FROM rate_limits');
    return http($method, $path, $input, $user);
}

// ---- the helper
$enc = Secrets::encrypt('my-secret-value');
T::ok($enc !== 'my-secret-value' && !str_contains($enc, 'my-secret-value') && str_starts_with($enc, 'v1:'), 'a secret is stored scrambled');
T::eq('my-secret-value', Secrets::decrypt($enc), 'and reads back');
T::eq('', Secrets::decrypt(substr($enc, 0, -3) . 'AAA'), 'a damaged value is never accepted');
T::eq('', Secrets::decrypt('plain text'), 'plain text is not accepted as a secret');
T::ok(Secrets::encrypt('x') !== Secrets::encrypt('x'), 'the same secret is scrambled differently each time');

// ---- the page
$page = cs('GET', '/admin/payments', [], $admin);
T::eq(200, $page->status, 'admin opens Payment methods');
foreach (['Debit / Credit Card (Safepay)', 'name="card_api_key"', 'name="card_api_secret"', 'name="card_webhook_secret"', 'name="card_environment"', 'Not connected', '/webhooks/payments/card'] as $needle) {
    T::contains($needle, $page->body, "the page shows: {$needle}");
}
T::ok(!(new CardGateway())->isAvailable(), 'card is off before any key is saved');

// ---- saving
$r = cs('PUT', '/admin/payments', ['enabled' => ['cod'], 'card_environment' => 'sandbox', 'card_api_key' => 'sec_public_123', 'card_api_secret' => 'SECRET-ABC-999', 'card_webhook_secret' => 'HOOK-SECRET-0123456789'], $admin);
T::ok(in_array($r->status, [302, 303], true), 'saving redirects back');
$rawStored = (string) $db->value("SELECT value FROM settings WHERE `key` = 'payments.card'");
T::ok($rawStored !== '' && str_contains($rawStored, 'sec_public_123'), 'the API key is saved');
T::ok(!str_contains($rawStored, 'SECRET-ABC-999') && !str_contains($rawStored, 'HOOK-SECRET-0123456789'), 'the secret key and webhook secret are NOT in the database as text');
$s = CardSettings::stored();
T::eq(['sec_public_123', 'SECRET-ABC-999', 'HOOK-SECRET-0123456789', 'sandbox'], [$s['key'], $s['secret'], $s['webhook_secret'], $s['environment']], 'they decrypt back to what was typed');
$page = cs('GET', '/admin/payments', [], $admin);
T::contains('Connected', $page->body, 'the page says the card is connected');
T::ok(!str_contains($page->body, 'SECRET-ABC-999') && !str_contains($page->body, 'HOOK-SECRET-0123456789'), 'the secrets are never shown again');
T::contains('sec_public_123', $page->body, 'the public API key is shown');
T::contains('Leave empty to keep it', $page->body, 'and the secret fields say they are saved');
T::eq(SafepayDriver::class, Config::get('payments.card.driver'), 'Safepay becomes the provider without any .env line');
T::ok((new CardGateway())->isAvailable() && (new CardGateway())->missing() === [], 'the card gateway is now available');

// ---- switch it on; blank secret fields keep what is saved
$r = cs('PUT', '/admin/payments', ['enabled' => ['cod', 'card'], 'card_environment' => 'production', 'card_api_key' => '', 'card_api_secret' => '', 'card_webhook_secret' => ''], $admin);
T::ok(in_array($r->status, [302, 303], true), 'enabling card saves');
T::eq(['cod', 'card'], Settings::get('payments.enabled'), 'card is enabled');
$s = CardSettings::stored();
T::eq(['sec_public_123', 'SECRET-ABC-999', 'production'], [$s['key'], $s['secret'], $s['environment']], 'blank fields keep the saved keys, the mode changed');
http('GET', '/');
T::ok(isset(PaymentManager::enabled()['card']), 'card is offered at checkout');

// ---- a rejected save never puts a secret back
cs('PUT', '/admin/payments', ['enabled' => ['cod'], 'card_environment' => 'bogus', 'card_api_secret' => 'LEAKY-SECRET-777', 'card_webhook_secret' => 'LEAKY-HOOK-888'], $admin);
$after = cs('GET', '/admin/payments', [], $admin)->body;
T::ok(!str_contains($after, 'LEAKY-SECRET-777') && !str_contains($after, 'LEAKY-HOOK-888'), 'a rejected form never puts a typed secret back on the page');
T::eq('SECRET-ABC-999', CardSettings::stored()['secret'], 'and the saved secret is unchanged');

// ---- removing, and enabling without keys
cs('PUT', '/admin/payments', ['enabled' => ['cod'], 'card_clear' => '1'], $admin);
$gone = CardSettings::stored();
T::eq(['', '', ''], [$gone['key'], $gone['secret'], $gone['webhook_secret']], 'Remove clears every saved key');
Config::set('payments.card', $blank);
cs('PUT', '/admin/payments', ['enabled' => ['cod', 'card']], $admin);
T::ok(!in_array('card', (array) Settings::get('payments.enabled'), true), 'card cannot be switched on without keys');

// ---- who may use it
T::eq(403, cs('PUT', '/admin/payments', ['enabled' => ['cod'], 'card_api_key' => 'hack'], $cust)->status, 'a customer cannot save card keys');
T::ok(in_array(cs('PUT', '/admin/payments', ['enabled' => ['cod'], 'card_api_key' => 'hack'], false)->status, [302, 303, 401, 403], true), 'a guest cannot either');
T::eq('', CardSettings::stored()['key'], 'nothing was saved by them');

Auth::actingAs(null);
Config::set('payments.card', $origCard);
Settings::forget('payments.enabled');
Settings::forget('payments.card');
