<?php

declare(strict_types=1);

/*
 * Easy content management: the Content overview (what is done, what needs writing), the FAQ editor and how the FAQ page uses it,
 * the formatting toolbar on long text boxes, pre-filled "write it" links, and who may use these pages.
 */

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Session;
use App\Services\ContentPages;
use App\Support\Settings;

T::$current = 'Content management';
reset_db();
$db = Database::get();
$admin = Fx::user('admin');
$cust = Fx::user('customer');

function cm(string $method, string $path, array $input, array|false $user): App\Core\Response
{
    Database::get()->exec('DELETE FROM rate_limits');
    return http($method, $path, $input, $user);
}

// ---- Content overview
$r = cm('GET', '/admin/content', [], $admin);
T::eq(200, $r->status, 'admin sees the Content overview');
foreach (['Content overview', 'Pages customers read', 'Home page', 'Shop details', 'Selling', 'Privacy policy', 'Terms &amp; conditions', 'Where each kind of content lives', 'things done'] as $needle) {
    T::contains($needle, $r->body, "overview shows: {$needle}");
}
T::contains('Needs attention', $r->body, 'missing essentials are flagged');
T::contains('Write it', $r->body, 'with a button to write them');
T::contains('/admin/pages/create?title=Privacy+policy&amp;slug=privacy-policy&amp;published=1', $r->body, 'the button opens a page form already filled in');
T::contains('role="progressbar"', $r->body, 'a progress bar shows how much is done');
T::contains('Content overview', cm('GET', '/admin', [], $admin)->body, 'the sidebar links to it');

$ov = App\Controllers\Admin\ContentController::overview();
$flat = [];
foreach ($ov['sections'] as $sec) {
    foreach ($sec['items'] as $i) {
        $flat[$i['label']] = $i;
    }
}
T::eq('todo', $flat['Privacy policy']['status'] ?? null, 'no privacy page = needs attention');
T::eq('todo', $flat['Shipping & returns text']['status'] ?? null, 'empty policies = needs attention');
$before = $ov['done'];
Fx::page(['title' => 'Privacy policy', 'slug' => 'privacy-policy', 'body' => '<p>We keep your details private.</p>', 'is_published' => 1]);
Settings::put('delivery.info', 'Delivery in 3-5 days.');
Settings::put('returns.policy', 'Returns within 7 days.');
Settings::put('contact.email', 'hello@example.test');
Settings::put('contact.phone', '+92 300 1234567');
$ov2 = App\Controllers\Admin\ContentController::overview();
$flat2 = [];
foreach ($ov2['sections'] as $sec) {
    foreach ($sec['items'] as $i) {
        $flat2[$i['label']] = $i;
    }
}
T::eq('ok', $flat2['Privacy policy']['status'] ?? null, 'a published privacy page is done');
T::eq('ok', $flat2['Shipping & returns text']['status'] ?? null, 'written policies are done');
T::eq('ok', $flat2['Contact details']['status'] ?? null, 'contact details are done');
T::ok($ov2['done'] > $before && $ov2['total'] === $ov['total'], 'the progress count goes up as content is added');
$ship = $db->insert("INSERT INTO shipping_methods (code, name, cost, is_active, is_sample, sort_order, created_at, updated_at) VALUES ('tst', 'Test courier', 0, 1, 0, 1, ?, ?)", [now(), now()]);
$flat3 = [];
foreach (App\Controllers\Admin\ContentController::overview()['sections'] as $sec) {
    foreach ($sec['items'] as $i) {
        $flat3[$i['label']] = $i;
    }
}
T::eq('todo', $flat3['Shipping methods and rates']['status'] ?? null, 'an active shipping method that costs 0 is flagged');
$db->exec('DELETE FROM shipping_methods WHERE id = ?', [$ship]);

// ---- pre-filled "new page" form
$form = cm('GET', '/admin/pages/create?title=Terms+%26+conditions&slug=terms-and-conditions&published=1', [], $admin)->body;
T::contains('value="Terms &amp; conditions"', $form, 'the new-page form is pre-filled with the title');
T::contains('value="terms-and-conditions"', $form, 'and the address');
T::contains('data-rich', $form, 'the content box has the formatting toolbar hooks');
T::contains('assets/abiha/editor.js', $form, 'the toolbar script is loaded in the admin');
$huge = cm('GET', '/admin/pages/create?title=' . str_repeat('x', 500), [], $admin)->body;
T::ok(!str_contains($huge, str_repeat('x', 300)), 'a very long prefill is ignored');
T::contains('data-rich', cm('GET', '/admin/products/create', [], $admin)->body, 'the product description has the toolbar too');
T::ok(is_file(PUBLIC_PATH . '/assets/abiha/editor.js') && str_contains((string) file_get_contents(PUBLIC_PATH . '/assets/abiha/editor.js'), 'data-rich'), 'editor.js exists');

// ---- FAQ editor
$page = cm('GET', '/admin/faq', [], $admin);
T::eq(200, $page->status, 'admin sees the FAQ editor');
T::contains('built-in', $page->body, 'it says the built-in FAQ is showing');
T::contains('Do I need an account to place an order?', $page->body, 'the built-in questions are there to edit');
T::contains('{payments}', $page->body, 'and the token help is shown');
T::contains('groups[${gi}][items][${ii}][q]', html_entity_decode($page->body), 'fields are submitted as groups');
Fx::page(['title' => 'FAQ', 'slug' => 'faq', 'body' => '', 'is_published' => 1]);
T::contains('Frequently asked questions', cm('GET', '/pages/faq', [], false)->body, 'the public FAQ page works');

$groups = [
    ['title' => 'Delivery', 'items' => [['q' => 'How long does delivery take?', 'a' => 'Usually 3-5 days. See {shipping}.'], ['q' => '', 'a' => 'dropped: no question']]],
    ['title' => '', 'items' => [['q' => 'Do you have a shop?', 'a' => '<b>Yes</b> <script>alert(1)</script> in Karachi.']]],
    ['title' => 'Empty group', 'items' => []],
];
$r = cm('PUT', '/admin/faq', ['groups' => $groups], $admin);
T::ok(in_array($r->status, [302, 303], true), 'saving redirects back');
$saved = Settings::get('faq.groups');
T::eq(2, count($saved), 'empty groups and empty questions are dropped');
T::eq('Delivery', $saved[0]['title'], 'the title is kept');
T::eq('Questions', $saved[1]['title'], 'a missing title becomes "Questions"');
T::ok(!str_contains(json_encode($saved), '<script') && !str_contains(json_encode($saved), '<b>'), 'answers are saved as plain text (no HTML)');
T::contains('Yes alert(1) in Karachi.', $saved[1]['items'][0]['a'], 'the words stay, the tags go');

$public = cm('GET', '/pages/faq', [], false)->body;
T::contains('How long does delivery take?', $public, 'the public FAQ shows the owner\'s question');
T::contains('<a href="' . route('page.shipping') . '">Shipping &amp; returns</a>', $public, 'tokens still become links');
T::ok(!str_contains($public, 'Do I need an account to place an order?'), 'the built-in questions are replaced');
T::ok(!str_contains($public, '<script>alert(1)'), 'no script from an answer reaches the page');
T::contains('your own', cm('GET', '/admin/faq', [], $admin)->body, 'the editor says the owner\'s own FAQ is showing');
T::eq(['Delivery', 'Questions'], array_keys(ContentPages::faqGroups()), 'faqGroups() uses the saved groups');

$before = Settings::get('faq.groups');
$r = cm('PUT', '/admin/faq', ['groups' => [['title' => 'X', 'items' => [['q' => '', 'a' => 'no question']]]]], $admin);
T::eq($before, Settings::get('faq.groups'), 'saving with no real question keeps the current FAQ');
T::ok(in_array($r->status, [302, 303], true), 'and redirects back with a message');
$many = [];
for ($g = 0; $g < 20; $g++) {
    $items = [];
    for ($i = 0; $i < 40; $i++) {
        $items[] = ['q' => "Q{$g}-{$i}", 'a' => 'A'];
    }
    $many[] = ['title' => "G{$g}", 'items' => $items];
}
cm('PUT', '/admin/faq', ['groups' => $many], $admin);
T::eq(12, count(Settings::get('faq.groups')), 'at most 12 groups');
T::eq(30, count(Settings::get('faq.groups')[0]['items']), 'at most 30 questions per group');

$r = cm('POST', '/admin/faq/reset', [], $admin);
T::ok(in_array($r->status, [302, 303], true), 'reset redirects');
T::eq(null, Settings::get('faq.groups'), 'reset removes the custom FAQ');
T::contains('Do I need an account to place an order?', cm('GET', '/pages/faq', [], false)->body, 'the built-in FAQ is back');

// ---- permissions
T::ok(in_array(cm('GET', '/admin/content', [], false)->status, [302, 303], true), 'a guest is sent to sign in');
T::eq(403, cm('GET', '/admin/content', [], $cust)->status, 'a customer cannot open the overview');
T::eq(403, cm('GET', '/admin/faq', [], $cust)->status, 'a customer cannot open the FAQ editor');
T::eq(403, cm('PUT', '/admin/faq', ['groups' => $groups], $cust)->status, 'a customer cannot save the FAQ');
T::eq(null, Settings::get('faq.groups'), 'and nothing was saved');
$sales = Fx::user('inventory_manager');
T::eq(403, cm('GET', '/admin/faq', [], $sales)->status, 'staff without the content permission cannot edit the FAQ');

Auth::actingAs(null);
