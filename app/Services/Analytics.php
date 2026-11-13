<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Support\Settings;

/**
 * First-party traffic analytics. Every counted storefront page view is one row in page_views.
 * Privacy by design: no cookies, no raw IP address and no user id are stored. The "visitor" is a one-way hash of
 * IP + browser + day + the site key, so a person is only recognised within a single day. The signed-in staff are never
 * counted, nor are robots, prefetches, background (ajax) calls, downloads or visitors who send "Do Not Track".
 */
final class Analytics
{
    /** Tests switch tracking on explicitly; in the "testing" environment it is off. */
    public static ?bool $force = null;

    /** When true (the web front controller), track() only remembers the request; runDeferred() writes it after the page was sent. */
    public static bool $deferred = false;
    /** @var list<array{0:Request,1:Response}> */
    private static array $queue = [];

    private const SKIP_PREFIXES = ['/admin', '/webhooks', '/storage', '/assets', '/images', '/cron', '/search/suggest', '/cart/drawer', '/sitemap', '/robots', '/favicon'];
    private const BOT = '/bot|crawl|spider|slurp|bing(preview)?|facebookexternalhit|whatsapp|telegram|discord|preview|monitor|uptime|pingdom|lighthouse|headless|curl|wget|python|php\/|java\/|go-http|axios|node-fetch|scrapy|httpclient|feedfetcher|mediapartners|ahrefs|semrush|mj12/i';
    private const SEARCH = ['google', 'bing', 'yahoo', 'duckduckgo', 'baidu', 'yandex', 'ecosia', 'brave'];
    private const SOCIAL = ['facebook', 'fb.', 'instagram', 'twitter', 'x.com', 't.co', 'whatsapp', 'youtube', 'tiktok', 'pinterest', 'linkedin', 'snapchat', 'reddit'];

    public static function enabled(): bool
    {
        if (self::$force !== null) {
            return self::$force;
        }
        return Config::get('env') !== 'testing' && Settings::get('analytics.enabled', true) !== false;
    }

    /** Called by the Kernel after every response. Never throws and never slows a page down more than one INSERT. */
    public static function track(Request $request, Response $response): void
    {
        if (self::$deferred) {
            if (count(self::$queue) < 5) {
                self::$queue[] = [$request, $response];
            }
            return;
        }
        self::record($request, $response);
    }

    /** Writes the visits remembered while the page was being built (called once the response has been sent). */
    public static function runDeferred(): void
    {
        $queue = self::$queue;
        self::$queue = [];
        foreach ($queue as [$request, $response]) {
            self::record($request, $response);
        }
    }

    private static function record(Request $request, Response $response): void
    {
        try {
            if (!self::enabled() || !self::countable($request, $response)) {
                return;
            }
            [$channel, $source] = self::origin($request);
            $ua = $request->userAgent();
            Database::get()->exec(
                'INSERT INTO page_views (created_at, visitor, path, channel, source, device, browser, os) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [now(), self::visitor($request), self::path($request), $channel, $source, self::device($ua), self::browser($ua), self::os($ua)]
            );
        } catch (\Throwable) {
            // analytics must never break the shop
        }
    }

    private static function countable(Request $request, Response $response): bool
    {
        if ($request->method !== 'GET' || $response->status !== 200) {
            return false;
        }
        if (!str_starts_with((string) ($response->headers['Content-Type'] ?? ''), 'text/html')) {
            return false;
        }
        foreach (self::SKIP_PREFIXES as $p) {
            if ($request->path === $p || str_starts_with($request->path, $p . '/') || str_starts_with($request->path, $p . '.')) {
                return false;
            }
        }
        $ua = $request->userAgent();
        if ($ua === '' || preg_match(self::BOT, $ua) === 1) {
            return false;
        }
        if ($request->header('DNT') === '1' || $request->ajax()) {
            return false;
        }
        $purpose = strtolower((string) ($request->header('Sec-Purpose') ?? $request->header('Purpose') ?? ''));
        if (str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender')) {
            return false;
        }
        return !Auth::isStaff();
    }

    private static function path(Request $request): string
    {
        return mb_substr('/' . trim($request->path, '/'), 0, 255);
    }

    private static function visitor(Request $request): string
    {
        return substr(hash('sha256', $request->ip() . '|' . $request->userAgent() . '|' . date('Y-m-d') . '|' . Config::get('key', 'abiha')), 0, 24);
    }

    /** @return array{0:string,1:?string} channel (Direct, Search, Social, Referral, Campaign) and the source name */
    private static function origin(Request $request): array
    {
        $utm = trim((string) ($request->query['utm_source'] ?? ''));
        if ($utm !== '') {
            return ['Campaign', mb_substr(strtolower($utm), 0, 120)];
        }
        $ref = (string) ($request->header('Referer') ?? '');
        $host = $ref !== '' ? strtolower((string) parse_url($ref, PHP_URL_HOST)) : '';
        $host = preg_replace('/^www\./', '', $host) ?? '';
        $own = preg_replace('/^www\./', '', strtolower((string) parse_url((string) Config::get('url'), PHP_URL_HOST))) ?? '';
        if ($host === '' || $host === $own) {
            return ['Direct', null];
        }
        foreach (self::SEARCH as $s) {
            if (str_contains($host, $s)) {
                return ['Search', mb_substr($host, 0, 120)];
            }
        }
        foreach (self::SOCIAL as $s) {
            if (str_contains($host, $s)) {
                return ['Social', mb_substr($host, 0, 120)];
            }
        }
        return ['Referral', mb_substr($host, 0, 120)];
    }

    public static function device(string $ua): string
    {
        if (preg_match('/ipad|tablet|kindle|silk|playbook/i', $ua) || (preg_match('/android/i', $ua) && !preg_match('/mobile/i', $ua))) {
            return 'tablet';
        }
        return preg_match('/mobi|iphone|ipod|android|blackberry|opera mini|windows phone/i', $ua) ? 'mobile' : 'desktop';
    }

    public static function browser(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/edg(e|a|ios)?\//i', $ua) => 'Edge',
            (bool) preg_match('/opr\/|opera/i', $ua) => 'Opera',
            (bool) preg_match('/samsungbrowser/i', $ua) => 'Samsung',
            (bool) preg_match('/firefox|fxios/i', $ua) => 'Firefox',
            (bool) preg_match('/chrome|crios/i', $ua) => 'Chrome',
            (bool) preg_match('/safari/i', $ua) => 'Safari',
            (bool) preg_match('/msie|trident/i', $ua) => 'IE',
            default => 'Other',
        };
    }

    public static function os(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/iphone|ipad|ipod/i', $ua) => 'iOS',
            (bool) preg_match('/android/i', $ua) => 'Android',
            (bool) preg_match('/windows/i', $ua) => 'Windows',
            (bool) preg_match('/mac os x|macintosh/i', $ua) => 'macOS',
            (bool) preg_match('/cros/i', $ua) => 'ChromeOS',
            (bool) preg_match('/linux|x11/i', $ua) => 'Linux',
            default => 'Other',
        };
    }

    /** Deletes page views older than the retention window (default 400 days). Returns the number of rows removed. */
    public static function prune(int $days = 400): int
    {
        return Database::get()->exec('DELETE FROM page_views WHERE created_at < ?', [date('Y-m-d H:i:s', strtotime("-{$days} days"))]);
    }

    // ───────────────────────────── reports ─────────────────────────────

    /** "Unique visitors per day": a person is recognised within a day only, so the range total is the sum of daily uniques. */
    private const VISITORS = 'COUNT(DISTINCT CONCAT(DATE(created_at), visitor))';

    /**
     * Everything the Analytics page shows for the days $from..$to (inclusive, Y-m-d).
     *
     * @return array<string,mixed>
     */
    public static function report(string $from, string $to): array
    {
        $db = Database::get();
        $a = $from . ' 00:00:00';
        $b = $to . ' 23:59:59';
        $days = max(1, (int) round((strtotime($to) - strtotime($from)) / 86400) + 1);
        $pFrom = date('Y-m-d', strtotime($from . " -{$days} days"));
        $pTo = date('Y-m-d', strtotime($from . ' -1 day'));

        $totals = static function (string $x, string $y) use ($db): array {
            $r = $db->one('SELECT COUNT(*) AS views, ' . self::VISITORS . ' AS visitors FROM page_views WHERE created_at BETWEEN ? AND ?', [$x . ' 00:00:00', $y . ' 23:59:59']) ?: [];
            $orders = (int) $db->value("SELECT COUNT(*) FROM orders WHERE status NOT IN ('cancelled','returned') AND created_at BETWEEN ? AND ?", [$x . ' 00:00:00', $y . ' 23:59:59']);
            $views = (int) ($r['views'] ?? 0);
            $visitors = (int) ($r['visitors'] ?? 0);
            return ['views' => $views, 'visitors' => $visitors, 'orders' => $orders,
                'per_visitor' => $visitors > 0 ? round($views / $visitors, 1) : 0.0,
                'conversion' => $visitors > 0 ? round($orders * 100 / $visitors, 1) : 0.0];
        };
        $cur = $totals($from, $to);
        $prev = $totals($pFrom, $pTo);
        $change = static fn (float|int $now, float|int $before): ?int => $before > 0 ? (int) round((($now - $before) / $before) * 100) : null;

        $byDay = [];
        foreach ($db->all('SELECT DATE(created_at) AS d, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY DATE(created_at)', [$a, $b]) as $r) {
            $byDay[$r['d']] = ['views' => (int) $r['views'], 'visitors' => (int) $r['visitors']];
        }
        $daily = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
            $d = date('Y-m-d', $t);
            $daily[] = ['date' => $d, 'label' => date('j M', $t), 'full' => date('D j M', $t)] + ($byDay[$d] ?? ['views' => 0, 'visitors' => 0]);
        }

        $group = static fn (string $col, int $limit = 8): array => $db->all(
            "SELECT {$col} AS name, COUNT(*) AS views, " . self::VISITORS . " AS visitors FROM page_views WHERE created_at BETWEEN ? AND ? AND {$col} IS NOT NULL AND {$col} <> '' GROUP BY {$col} ORDER BY views DESC LIMIT {$limit}",
            [$a, $b]
        );

        return [
            'from' => $from, 'to' => $to, 'days' => $days,
            'now' => $cur, 'previous' => $prev,
            'change' => ['views' => $change($cur['views'], $prev['views']), 'visitors' => $change($cur['visitors'], $prev['visitors']), 'orders' => $change($cur['orders'], $prev['orders'])],
            'daily' => $daily,
            'pages' => self::labelPages($db->all(
                'SELECT path, COUNT(*) AS views, ' . self::VISITORS . ' AS visitors FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY path ORDER BY views DESC LIMIT 12',
                [$a, $b]
            )),
            'channels' => $group('channel', 6),
            'sources' => $db->all(
                "SELECT source AS name, channel, COUNT(*) AS views, " . self::VISITORS . " AS visitors FROM page_views WHERE created_at BETWEEN ? AND ? AND source IS NOT NULL GROUP BY source, channel ORDER BY views DESC LIMIT 8",
                [$a, $b]
            ),
            'devices' => $group('device', 4), 'browsers' => $group('browser', 6), 'systems' => $group('os', 6),
            'live' => (int) $db->value('SELECT COUNT(DISTINCT visitor) FROM page_views WHERE created_at >= ?', [date('Y-m-d H:i:s', strtotime('-30 minutes'))]),
            'tracking' => self::enabled(),
            'first_view' => $db->value('SELECT MIN(created_at) FROM page_views'),
        ];
    }

    /** Short numbers for the dashboard strip: today, yesterday and the last 7 days. */
    public static function glance(): array
    {
        try {
            $db = Database::get();
            $one = static fn (string $x, string $y): array => $db->one('SELECT COUNT(*) AS views, ' . self::VISITORS . ' AS visitors FROM page_views WHERE created_at BETWEEN ? AND ?', [$x . ' 00:00:00', $y . ' 23:59:59']) ?: ['views' => 0, 'visitors' => 0];
            $today = date('Y-m-d');
            return [
                'today' => $one($today, $today),
                'week' => $one(date('Y-m-d', strtotime('-6 days')), $today),
                'live' => (int) $db->value('SELECT COUNT(DISTINCT visitor) FROM page_views WHERE created_at >= ?', [date('Y-m-d H:i:s', strtotime('-30 minutes'))]),
            ];
        } catch (\Throwable) {
            return [];   // table not created yet (run: php bin/console.php migrate): the dashboard simply hides the strip
        }
    }

    /** Adds a readable name to each path: product and category names, and the fixed pages. */
    private static function labelPages(array $rows): array
    {
        $db = Database::get();
        $fixed = ['/' => 'Home', '/shop' => 'Shop', '/cart' => 'Bag', '/checkout' => 'Checkout', '/wishlist' => 'Wishlist', '/track-order' => 'Track order',
            '/contact' => 'Contact', '/login' => 'Sign in', '/register' => 'Create account', '/shipping-returns' => 'Shipping & returns'];
        foreach ($rows as &$r) {
            $p = (string) $r['path'];
            $name = $fixed[$p] ?? null;
            if ($name === null && preg_match('#^/products/([a-z0-9-]+)$#', $p, $m)) {
                $n = $db->value('SELECT name FROM products WHERE slug = ?', [$m[1]]);
                $name = $n !== null ? (string) $n : null;
            } elseif ($name === null && preg_match('#^/category/([a-z0-9-]+)$#', $p, $m)) {
                $n = $db->value('SELECT name FROM categories WHERE slug = ?', [$m[1]]);
                $name = $n !== null ? 'Category: ' . $n : null;
            } elseif ($name === null && preg_match('#^/collections/([a-z0-9-]+)$#', $p, $m)) {
                $n = $db->value('SELECT name FROM collections WHERE slug = ?', [$m[1]]);
                $name = $n !== null ? 'Collection: ' . $n : null;
            } elseif ($name === null && preg_match('#^/pages/([a-z0-9-]+)$#', $p, $m)) {
                $name = $m[1] === 'faq' ? 'FAQ' : ucwords(str_replace('-', ' ', $m[1]));
            }
            $r['label'] = $name ?? $p;
        }
        unset($r);
        return $rows;
    }
}
