<?php

declare(strict_types=1);

/* The Bank transfer show/hide switch in Admin -> Payment methods: hiding keeps the account details, showing needs them. */

use App\Auth\Auth;
use App\Core\Database;
use App\Services\Payments\PaymentManager;
use App\Support\Settings;

T::$current = 'Bank transfer switch';
reset_db();
$admin = Fx::user('admin');

function bs(string $method, array $input, array|false $user): App\Core\Response
{
    Database::get()->exec('DELETE FROM rate_limits');
    return http($method, '/admin/payments', $input, $user);
}

Settings::put('payments.enabled', ['cod']);
Settings::put('bank.details', null);

$page = bs('GET', [], $admin)->body;
T::contains('name="bank_visible"', $page, 'the page has a Bank transfer switch');
T::contains('Show Bank Transfer at checkout', $page, 'labelled clearly');
T::ok(substr_count($page, 'value="bank_transfer"') === 0, 'bank transfer is no longer repeated in the general list');
T::contains('Hidden', $page, 'it says Hidden while it is off');

// showing it with no account details is refused
bs('PUT', ['enabled' => ['cod'], 'bank_visible' => '1', 'bank_details' => ''], $admin);
T::ok(!in_array('bank_transfer', (array) Settings::get('payments.enabled'), true), 'cannot be shown without account details');

// show it
bs('PUT', ['enabled' => ['cod'], 'bank_visible' => '1', 'bank_details' => "Bank: Test Bank\nIBAN: PK00TEST0000000000000000"], $admin);
T::ok(in_array('bank_transfer', (array) Settings::get('payments.enabled'), true), 'shown after the switch is on');
http('GET', '/');
T::ok(isset(PaymentManager::enabled()['bank_transfer']), 'customers can choose it');
T::contains('Shown', bs('GET', [], $admin)->body, 'the page says Shown');

// hide it: details are kept
bs('PUT', ['enabled' => ['cod'], 'bank_visible' => '0', 'bank_details' => "Bank: Test Bank\nIBAN: PK00TEST0000000000000000"], $admin);
T::ok(!in_array('bank_transfer', (array) Settings::get('payments.enabled'), true), 'hidden after the switch is off');
T::contains('IBAN: PK00TEST0000000000000000', (string) Settings::get('bank.details'), 'the account details are kept');
http('GET', '/');
T::ok(!isset(PaymentManager::enabled()['bank_transfer']), 'customers no longer see it');
T::ok(in_array('cod', (array) Settings::get('payments.enabled'), true), 'other methods are untouched');

// show again, details still there
bs('PUT', ['enabled' => ['cod'], 'bank_visible' => '1', 'bank_details' => (string) Settings::get('bank.details')], $admin);
T::ok(in_array('bank_transfer', (array) Settings::get('payments.enabled'), true), 'can be shown again');

Auth::actingAs(null);
Settings::forget('payments.enabled');
Settings::forget('bank.details');
