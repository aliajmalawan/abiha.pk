<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Core\Request;

/**
 * Per-request SEO state (port of the old App\Support\Seo + the head of layouts/app.blade.php). Pages describe themselves
 * (Controller::view() $meta, or Seo::title()/breadcrumbs()/schema()/image()/noindex() inside the page template, which is
 * rendered before the layout); the store layout prints it all. Kernel calls Seo::reset() at the start of every request.
 *
 * Rules enforced here, in one place:
 *  - canonical URL = the page without query strings, except "?page=N" (N > 1) on a listing without view parameters;
 *  - filtered, sorted or searched listings (VIEW_PARAMS) are "noindex,follow" (their canonical points at the clean listing);
 *  - account, cart, checkout, auth and order pages (PRIVATE_PREFIXES) are "noindex,nofollow";
 *  - nothing is indexable outside production or when the owner switched indexing off (setting "seo.allow_indexing").
 */
final class Seo
{
    /** Path prefixes that are private or transactional: never for a search index. */
    public const PRIVATE_PREFIXES = ['admin', 'account', 'cart', 'checkout', 'order', 'login', 'register', 'forgot-password', 'reset-password', 'wishlist', 'track-order', 'search', 'webhooks'];

    /** Query parameters that only change how a listing is shown. */
    public const VIEW_PARAMS = ['q', 'sort', 'min', 'max', 'availability', 'color', 'size', 'sale', 'category'];

    private static array $schemas = [];
    private static array $crumbs = [];
    private static ?string $image = null;
    private static ?string $type = null;
    private static ?string $canonical = null;
    private static bool $noindex = false;
    private static ?string $title = null;
    private static ?string $description = null;

    public static function reset(): void
    {
        self::$schemas = [];
        self::$crumbs = [];
        self::$image = self::$type = self::$canonical = self::$title = self::$description = null;
        self::$noindex = false;
    }

    /** Page title without the site name ("Shop"); the layout appends " | ABIHA®". */
    public static function title(?string $title = null): ?string
    {
        if ($title !== null) {
            self::$title = $title;
        }
        return self::$title;
    }

    public static function description(?string $text = null): ?string
    {
        if ($text !== null) {
            self::$description = $text;
        }
        return self::$description;
    }

    /** @param list<array{0:string,1?:string}> $items [name, url] pairs; the last (current page) may have url '' */
    public static function breadcrumbs(array $items): void
    {
        self::$crumbs = array_values(array_map(static fn ($i) => ['name' => (string) $i[0], 'url' => (string) ($i[1] ?? '')], $items));
    }

    public static function crumbs(): array
    {
        return self::$crumbs;
    }

    public static function schema(array $data): void
    {
        self::$schemas[] = $data;
    }

    /** Social preview image: a public-disk path ("products/x.jpg"), a site path ("/assets/...") or an absolute URL. */
    public static function image(?string $pathOrUrl): void
    {
        self::$image = $pathOrUrl;
    }

    public static function type(string $type): void
    {
        self::$type = $type;
    }

    public static function canonical(string $url): void
    {
        self::$canonical = $url;
    }

    public static function noindex(bool $on = true): void
    {
        self::$noindex = $on;
    }

    public static function ogType(): string
    {
        return self::$type ?? 'website';
    }

    public static function hasCustomImage(): bool
    {
        return (bool) self::$image;
    }

    public static function imageUrl(): string
    {
        $img = self::$image;
        if (!$img) {
            return asset('assets/brand/abiha-logo-full-600.png', true);      // the supplied artwork; no invented share image
        }
        if (preg_match('#^https?://#i', $img)) {
            return $img;
        }
        return str_starts_with($img, '/') ? url($img) : self::absolute(Img::url($img));
    }

    public static function canonicalUrl(Request $request): string
    {
        if (self::$canonical) {
            return self::$canonical;
        }
        $page = (int) ($request->query['page'] ?? 1);
        $base = url($request->path === '/' ? '/' : '/' . ltrim($request->path, '/'));
        return $page > 1 && !self::hasViewParams($request->query) ? $base . '?page=' . $page : $base;
    }

    /** Meta robots value, or null when the page may be indexed. */
    public static function robots(Request $request): ?string
    {
        if (self::indexingDisabled() || self::isPrivatePath($request->path)) {
            return 'noindex,nofollow';
        }
        if (self::$noindex || self::hasViewParams($request->query)) {
            return 'noindex,follow';
        }
        return null;
    }

    /** All JSON-LD blocks (registered schemas + BreadcrumbList when there is more than one crumb). */
    public static function jsonLd(): string
    {
        $blocks = self::$schemas;
        if (count(self::$crumbs) > 1) {
            $items = [];
            foreach (self::$crumbs as $i => $c) {
                $items[] = array_filter(['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => $c['url'] ?: null], static fn ($v) => $v !== null);
            }
            $blocks[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
        }
        return implode("\n", array_map(
            static fn ($b) => '<script type="application/ld+json">' . json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>',
            $blocks
        ));
    }

    public static function indexingDisabled(): bool
    {
        return !Config::isProduction() || !Settings::get('seo.allow_indexing', true);
    }

    public static function isPrivatePath(string $path): bool
    {
        $first = explode('/', trim($path, '/'))[0] ?? '';
        return in_array($first, self::PRIVATE_PREFIXES, true);
    }

    /** @param array<string,mixed> $query */
    public static function hasViewParams(array $query): bool
    {
        foreach (self::VIEW_PARAMS as $k) {
            if (array_key_exists($k, $query)) {
                return true;
            }
        }
        return false;
    }

    /** Structured data and OG tags must be absolute. */
    public static function absolute(string $url): string
    {
        return preg_match('#^https?://#i', $url) ? $url : url($url);
    }

    /** Plain text for meta descriptions: unescape, drop script/style bodies and tags, collapse whitespace, limit. */
    public static function plain(?string $html, int $limit = 160): string
    {
        $raw = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5);
        $raw = (string) preg_replace('#<(script|style)\b.*?</\1>#is', '', $raw);
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5)));
        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit)) . '…' : $text;
    }

    /** schema.org Organization: only facts the owner entered (port of SchemaBuilder::organization). */
    public static function organization(): array
    {
        $logo = SiteSettings::asset('site.logo') ?? asset('assets/brand/abiha-logo-full-600.png');
        $sameAs = array_values(array_filter(array_map(static fn ($k) => Settings::get('social.' . $k), ['instagram', 'facebook', 'tiktok', 'youtube'])));
        $email = Settings::get('contact.email', Config::get('abiha.contact.email'));
        $phone = Settings::get('contact.phone', Config::get('abiha.contact.phone'));
        return array_filter([
            '@context' => 'https://schema.org', '@type' => 'Organization',
            'name' => Config::get('abiha.name'), 'url' => url('/'), 'logo' => self::absolute($logo),
            'sameAs' => $sameAs ?: null,
            'contactPoint' => ($email || $phone) ? array_filter(['@type' => 'ContactPoint', 'contactType' => 'customer service', 'email' => $email ?: null, 'telephone' => $phone ?: null]) : null,
        ]);
    }

    /** schema.org WebSite with the shop search action (port of SchemaBuilder::website). */
    public static function website(): array
    {
        $shop = route_exists('shop') ? route('shop') : url('/shop');
        return [
            '@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => Config::get('abiha.name'), 'url' => url('/'),
            'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $shop . '?q={search_term_string}'], 'query-input' => 'required name=search_term_string'],
        ];
    }
}
