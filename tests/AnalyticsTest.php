<?php

declare(strict_types=1);

/*
 * Website traffic analytics: what is counted (and what never is), privacy, the report numbers, the admin page, the CSV,
 * the on/off switch, retention and the dashboard strip.
 */

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Session;
use App\Services\Analytics;
use App\Support\Settings;

T::$current = 'Analytics';
reset_db();
$db = Database::get();
Analytics::$force = true;                       // tracking is off in the "testing" environment unless a test asks for it
$chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
$iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
$count = static fn (): int => (int) Database::get()->value('SELECT COUNT(*) FROM page_views');
$visit = static function (string $path, string $ua, array $headers = []) {
    Auth::actingAs(null);
    Session::resetMemory();
    Database::get()->exec('DELETE FROM rate_limits');
    return http('GET', $path, [], false, ['headers' => ['User-Agent' => $ua] + $headers]);
};
$db->exec('DELETE FROM page_views');

// ---- device / browser / system detection
T::eq('mobile', Analytics::device($iphone), 'iPhone is mobile');
T::eq('tablet', Analytics::device('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1'), 'iPad is a tablet');
T::eq('tablet', Analytics::device('Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 Chrome/120.0 Safari/537.36'), 'Android without "Mobile" is a tablet');
T::eq('desktop', Analytics::device($chrome), 'Windows Chrome is desktop');
T::eq('Edge', Analytics::browser($chrome . ' Edg/120.0'), 'Edge');
T::eq('Chrome', Analytics::browser($chrome), 'Chrome');
T::eq('Safari', Analytics::browser($iphone), 'Safari');
T::eq('iOS', Analytics::os($iphone), 'iOS');
T::eq('Windows', Analytics::os($chrome), 'Windows');

// ---- a normal visit is counted, with no personal data
$r = $visit('/', $chrome);
T::eq(200, $r->status, 'home renders');
T::eq(1, $count(), 'a storefront page view is counted');
$row = $db->one('SELECT * FROM page_views ORDER BY id DESC LIMIT 1');
T::eq('/', $row['path'], 'path stored without query');
T::eq('desktop', $row['device'], 'device');
T::eq('Chrome', $row['browser'], 'browser');
T::eq('Windows', $row['os'], 'os');
T::eq('Direct', $row['channel'], 'no referrer = Direct');
T::eq(24, strlen((string) $row['visitor']), 'visitor is a 24-character hash');
T::ok(!str_contains((string) $row['visitor'], '127.0.0.1') && !str_contains(json_encode($row), '127.0.0.1') && !str_contains(json_encode($row), 'Mozilla'), 'no IP address and no raw browser string is stored');
$visit('/', $chrome);
T::eq(2, $count(), 'a second view is a second row');
T::eq(1, (int) $db->value('SELECT COUNT(DISTINCT visitor) FROM page_views'), 'same person, same day = the same visitor hash');
$visit('/', $iphone);
T::eq(2, (int) $db->value('SELECT COUNT(DISTINCT visitor) FROM page_views'), 'another browser is another visitor');

// ---- where visitors came from
$visit('/shop', $chrome, ['Referer' => 'https://www.google.com/search?q=abiha']);
T::eq(['Search', 'google.com'], array_values($db->one('SELECT channel, source FROM page_views WHERE path = ? ORDER BY id DESC LIMIT 1', ['/shop'])), 'search engine referrer');
$visit('/shop', $chrome, ['Referer' => 'https://www.instagram.com/']);
T::eq('Social', $db->value('SELECT channel FROM page_views WHERE path = ? ORDER BY id DESC LIMIT 1', ['/shop']), 'social referrer');
$visit('/shop', $chrome, ['Referer' => 'https://someblog.example/post']);
T::eq('Referral', $db->value('SELECT channel FROM page_views WHERE path = ? ORDER BY id DESC LIMIT 1', ['/shop']), 'other site = Referral');
$visit('/shop?utm_source=Newsletter', $chrome);
T::eq(['Campaign', 'newsletter'], array_values($db->one('SELECT channel, source FROM page_views WHERE path = ? ORDER BY id DESC LIMIT 1', ['/shop'])), 'utm_source is a campaign');
$own = parse_url((string) App\Core\Config::get('url'), PHP_URL_HOST);
$visit('/shop', $chrome, ['Referer' => 'https://' . $own . '/']);
T::eq('Direct', $db->value('SELECT channel FROM page_views WHERE path = ? ORDER BY id DESC LIMIT 1', ['/shop']), 'a link from the shop itself is not a source');

// ---- what is never counted
$before = $count();
$visit('/', 'Googlebot/2.1 (+http://www.google.com/bot.html)');
$visit('/', 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)');
$visit('/', 'curl/8.4.0');
T::eq($before, $count(), 'robots are not counted');
$visit('/', $chrome, ['DNT' => '1']);
T::eq($before, $count(), 'Do Not Track is respected');
$visit('/', $chrome, ['X-Requested-With' => 'XMLHttpRequest']);
T::eq($before, $count(), 'background (ajax) requests are not counted');
$visit('/', $chrome, ['Sec-Purpose' => 'prefetch']);
T::eq($before, $count(), 'prefetches are not counted');
$visit('/this-page-does-not-exist', $chrome);
T::eq($before, $count(), 'a 404 is not counted');
$visit('/admin/login', $chrome);
T::eq($before, $count(), 'admin pages are not counted');
$visit('/cart/drawer', $chrome);
T::eq($before, $count(), 'the bag drawer fragment is not counted');
http('POST', '/newsletter', ['email' => 'a@example.test'], false, ['headers' => ['User-Agent' => $chrome]]);
T::eq($before, $count(), 'form posts are not counted');
$staff = Fx::user('admin');
Auth::actingAs($staff);
http('GET', '/', [], $staff, ['headers' => ['User-Agent' => $chrome]]);
T::eq($before, $count(), 'signed-in staff are never counted');
$cust = Fx::user('customer');
http('GET', '/shop', [], $cust, ['headers' => ['User-Agent' => $chrome]]);
T::eq($before + 1, $count(), 'a signed-in customer is a visitor');

// ---- the switch: off records nothing, and the setting survives
Settings::put('analytics.enabled', false);
Analytics::$force = null;
Analytics::$force = null;
App\Core\Config::set('env', 'production');
$off = $count();
$visit('/', $chrome);
T::eq($off, $count(), 'tracking switched off records nothing');
Settings::put('analytics.enabled', true);
$visit('/', $chrome);
T::eq($off + 1, $count(), 'switched on again, it records');
App\Core\Config::set('env', 'testing');
$visit('/', $chrome);
T::eq($off + 1, $count(), 'the testing environment never records unless a test asks for it');
Analytics::$force = true;

// ---- the report
$db->exec('DELETE FROM page_views');
$ins = static function (string $when, string $visitor, string $path, string $channel = 'Direct', ?string $source = null, string $device = 'desktop') use ($db): void {
    $db->exec('INSERT INTO page_views (created_at, visitor, path, channel, source, device, browser, os) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$when, $visitor, $path, $channel, $source, $device, 'Chrome', 'Windows']);
};
$p = Fx::product(['name' => 'Silk Scarf', 'slug' => 'silk-scarf']);
$cat = Fx::category(['name' => 'Scarves', 'slug' => 'scarves']);
$d0 = date('Y-m-d', strtotime('-1 day'));
$ins($d0 . ' 10:00:00', 'aaaaaaaaaaaaaaaaaaaaaaaa', '/');
$ins($d0 . ' 10:01:00', 'aaaaaaaaaaaaaaaaaaaaaaaa', '/products/silk-scarf');
$ins($d0 . ' 10:02:00', 'aaaaaaaaaaaaaaaaaaaaaaaa', '/category/scarves');
$ins($d0 . ' 11:00:00', 'bbbbbbbbbbbbbbbbbbbbbbbb', '/products/silk-scarf', 'Search', 'google.com', 'mobile');
$ins(date('Y-m-d') . ' 09:00:00', 'cccccccccccccccccccccccc', '/products/silk-scarf', 'Social', 'instagram.com', 'mobile');
$rep = Analytics::report($d0, date('Y-m-d'));
T::eq(5, $rep['now']['views'], 'report: page views');
T::eq(3, $rep['now']['visitors'], 'report: visitors (unique per day)');
T::eq(1.7, $rep['now']['per_visitor'], 'report: pages per visitor');
T::eq(2, count($rep['daily']), 'report: one row per day');
T::eq([4, 1], [$rep['daily'][0]['views'], $rep['daily'][1]['views']], 'report: views per day');
T::eq('Silk Scarf', $rep['pages'][0]['label'], 'report: product pages get the product name');
T::eq(3, (int) $rep['pages'][0]['views'], 'report: most viewed page first');
T::ok(in_array('Category: Scarves', array_column($rep['pages'], 'label'), true), 'report: category pages get the category name');
T::ok(in_array('Home', array_column($rep['pages'], 'label'), true), 'report: the home page is called Home');
$channels = array_column($rep['channels'], 'views', 'name');
T::eq([3, 1, 1], [$channels['Direct'], $channels['Search'], $channels['Social']], 'report: channels');
$dev = array_column($rep['devices'], 'views', 'name');
T::eq([2, 3], [$dev['mobile'], $dev['desktop']], 'report: devices');
T::eq(1, (int) array_column($rep['sources'], 'views', 'name')['google.com'], 'report: sources');
T::eq(null, $rep['change']['views'], 'no earlier data: no percentage');
$ins(date('Y-m-d', strtotime($d0 . ' -2 days')) . ' 10:00:00', 'dddddddddddddddddddddddd', '/');
$rep = Analytics::report($d0, date('Y-m-d'));
T::eq(400, $rep['change']['views'], 'report: change against the previous period of the same length');
T::eq(0, Analytics::report(date('Y-m-d', strtotime('+5 days')), date('Y-m-d', strtotime('+6 days')))['now']['views'], 'an empty range is all zeros');
$one = Analytics::report(date('Y-m-d'), date('Y-m-d'));
T::eq(1, $one['now']['visitors'], 'a one-day range works');
T::eq(1, count($one['daily']), 'a one-day range has one day');
T::ok($one['live'] >= 0, 'live count is a number');

// ---- retention
$ins(date('Y-m-d', strtotime('-500 days')) . ' 10:00:00', 'eeeeeeeeeeeeeeeeeeeeeeee', '/old');
T::eq(1, Analytics::prune(400), 'pruning removes rows older than the retention window');
T::eq(0, (int) $db->value("SELECT COUNT(*) FROM page_views WHERE path = '/old'"), 'the old row is gone');
T::ok((int) $db->value('SELECT COUNT(*) FROM page_views') > 0, 'recent rows stay');

// ---- the admin page
$admin = Fx::user('admin');
$r = http('GET', '/admin/analytics', [], $admin);
T::eq(200, $r->status, 'admin sees Analytics');
foreach (['Analytics', 'Visitors', 'Page views', 'Most viewed pages', 'Where visitors come from', 'Devices', 'Browsers', 'Visitor tracking', 'Download CSV', 'No cookies are used'] as $needle) {
    T::contains($needle, $r->body, "analytics page shows: {$needle}");
}
T::contains('Silk Scarf', http('GET', '/admin/analytics?range=7', [], $admin)->body, 'a range shows the pages');
T::eq(200, http('GET', '/admin/analytics?range=today', [], $admin)->status, 'today range');
T::eq(200, http('GET', '/admin/analytics?range=custom&from=' . date('Y-m-d', strtotime('-10 days')) . '&to=' . date('Y-m-d'), [], $admin)->status, 'custom dates');
T::ok(http('GET', '/admin/analytics?range=custom&from=2026-02-01&to=2026-01-01', [], $admin)->status !== 200, 'a reversed date range is rejected');
$csv = http('GET', '/admin/analytics?range=7&export=csv', [], $admin);
T::eq(200, $csv->status, 'csv export');
T::contains('Date,Visitors,"Page views"', str_replace("\xEF\xBB\xBF", '', $csv->body), 'csv header');
T::contains('Silk Scarf', $csv->body, 'csv lists the top pages');
T::contains('Analytics', http('GET', '/admin', [], $admin)->body, 'the sidebar has an Analytics link');
$dash = http('GET', '/admin', [], $admin)->body;
T::contains('Website traffic', $dash, 'the dashboard shows the traffic strip');
T::contains('visitors today', $dash, 'strip: visitors today');

// ---- who may see it / switch it
T::ok(in_array(http('GET', '/admin/analytics', [], false)->status, [302, 303], true), 'a guest is sent to sign in');
T::eq(403, http('GET', '/admin/analytics', [], Fx::user('customer'))->status, 'a customer is refused');
T::eq(403, http('POST', '/admin/analytics/tracking', ['enabled' => '0'], Fx::user('customer'))->status, 'a customer cannot switch tracking');
$r = http('POST', '/admin/analytics/tracking', ['enabled' => '0'], $admin);
T::ok(in_array($r->status, [302, 303], true), 'the switch redirects back');
T::eq(false, Settings::get('analytics.enabled'), 'tracking is stored as off');
Analytics::$force = null;
App\Core\Config::set('env', 'production');
T::contains('Visitor tracking is <strong>off</strong>', http('GET', '/admin/analytics', [], $admin)->body, 'the page says tracking is off');
App\Core\Config::set('env', 'testing');
Analytics::$force = true;
http('POST', '/admin/analytics/tracking', ['enabled' => '1'], $admin);
T::eq(true, Settings::get('analytics.enabled'), 'tracking is stored as on');

Analytics::$force = null;
Auth::actingAs(null);

// ---- brand assets served to browsers
T::eq(200, http('GET', '/site.webmanifest', [], false)->status, 'web manifest is served');
$mf = json_decode(http('GET', '/site.webmanifest', [], false)->body, true);
T::eq('#101010', $mf['theme_color'] ?? null, 'manifest uses the brand black');
T::eq('#F8F6F1', $mf['background_color'] ?? null, 'manifest uses the brand ivory');
T::ok(count($mf['icons'] ?? []) === 2 && is_file(PUBLIC_PATH . '/assets/brand/icon-512.png'), 'manifest icons exist');
T::ok(filesize(PUBLIC_PATH . '/favicon.ico') > 1000 && str_starts_with((string) file_get_contents(PUBLIC_PATH . '/favicon.ico', false, null, 0, 4), "\x00\x00\x01\x00"), 'favicon.ico is a real icon file');
T::contains('rel="manifest"', http('GET', '/', [], false)->body, 'pages link the manifest');
