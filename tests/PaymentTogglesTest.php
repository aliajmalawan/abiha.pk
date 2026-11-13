<?php

declare(strict_types=1);

/*
 * Admin -> Payment methods: every method is one list with an on/off tick. On = offered at checkout and named on the bag page.
 * Off = gone from both. Methods that cannot work yet (no bank details, no card keys) cannot be turned on.
 */

use App\Auth\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Services\Payments\CardSettings;
use App\Services\Payments\PaymentManager;
use App\Support\Settings;

T::$current = 'Payment method switches';
reset_db();
$admin = Fx::user('admin');
$origCard = Config::get('payments.card');
Config::set('payments.card', ['driver' => '', 'key' => '', 'secret' => '', 'webhook_secret' => '', 'environment' => 'sandbox']);
Settings::put('payments.enabled', ['cod']);
Settings::put('bank.details', 'Bank: Test Bank, IBAN: PK00TEST0000000000000000');
CardSettings::save(['key' => 'sec_test', 'secret' => 'secret-test', 'webhook_secret' => 'webhook-test-0123456789', 'environment' => 'sandbox']);
App\Support\SiteSettings::apply();

function pt(string $method, array $input, array|false $user): App\Core\Response
{
    Database::get()->exec('DELETE FROM rate_limits');
    return http($method, '/admin/payments', $input, $user);
}
function bag_and_checkout(): array
{
    $p = Fx::product(['price' => 1000, 'stock' => 10]);
    http('POST', '/cart/items', ['product_id' => $p['id'], 'variant_id' => null, 'quantity' => 1], null, ['json' => true]);
    return [http('GET', '/cart')->body, http('GET', '/checkout')->body];
}

$page = pt('GET', [], $admin)->body;
foreach (['Cash on Delivery', 'Bank Transfer', 'Debit / Credit Card'] as $label) {
    T::contains($label, substr($page, 0, (int) strpos($page, 'Cash on Delivery</h2>') ?: 4000), "the list shows {$label}");
}
T::eq(3, substr_count($page, 'name="enabled[]"'), 'one tick per method, including bank transfer');
T::contains('Off: hidden', $page, 'a method that is off says so');

// everything on
pt('PUT', ['enabled' => ['cod', 'bank_transfer', 'card'], 'bank_details' => (string) Settings::get('bank.details')], $admin);
T::eq(['cod', 'bank_transfer', 'card'], array_values(Settings::get('payments.enabled')), 'all three are on');
T::contains('On: shown to customers', pt('GET', [], $admin)->body, 'the page says On');
Auth::actingAs(null);
[$bag, $checkout] = bag_and_checkout();
foreach (['Cash on Delivery', 'Bank Transfer', 'Debit / Credit Card'] as $label) {
    T::contains($label, $checkout, "checkout offers {$label}");
}
T::contains('Pay by Cash on Delivery, Bank Transfer or Debit / Credit Card', $bag, 'the bag page names all three');

// turn bank transfer and card off
pt('PUT', ['enabled' => ['cod'], 'bank_details' => (string) Settings::get('bank.details')], $admin);
Auth::actingAs(null);
[$bag, $checkout] = bag_and_checkout();
T::ok(!str_contains($checkout, 'Bank Transfer') && !str_contains($checkout, 'value="card"'), 'off methods are hidden at checkout');
T::ok(!str_contains($bag, 'Bank Transfer') && !str_contains($bag, 'Debit / Credit Card'), 'and on the bag page');
T::contains('Cash on Delivery', $checkout, 'the one still on stays');
T::contains('Bank: Test Bank', (string) Settings::get('bank.details'), 'turning bank transfer off keeps its details');
T::eq(['cod'], array_keys(PaymentManager::enabled()), 'only cash on delivery is active');
T::eq(null, PaymentManager::find('bank_transfer'), 'an off method cannot be used to place an order');

// cannot turn on what cannot work
pt('PUT', ['enabled' => ['cod', 'bank_transfer'], 'bank_details' => ''], $admin);
T::ok(!in_array('bank_transfer', (array) Settings::get('payments.enabled'), true), 'bank transfer cannot be turned on without account details');
pt('PUT', ['enabled' => ['cod'], 'bank_details' => (string) Settings::get('bank.details'), 'card_clear' => '1'], $admin);
Config::set('payments.card', ['driver' => '', 'key' => '', 'secret' => '', 'webhook_secret' => '', 'environment' => 'sandbox']);
pt('PUT', ['enabled' => ['cod', 'card'], 'bank_details' => (string) Settings::get('bank.details')], $admin);
T::ok(!in_array('card', (array) Settings::get('payments.enabled'), true), 'card cannot be turned on without keys');

Auth::actingAs(null);
Config::set('payments.card', $origCard);
Settings::forget('payments.enabled');
Settings::forget('payments.card');
Settings::forget('bank.details');
