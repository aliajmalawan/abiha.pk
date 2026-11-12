<?php

declare(strict_types=1);

/*
 * Global helpers available in every controller, service and view.
 *
 *   e($v)                        HTML-escape (text and attribute context)
 *   env('KEY', $default)         raw .env value (only app/Config/*.php should need it)
 *   cfg('abiha.name')            configuration value (App\Core\Config::get)
 *   setting('contact.phone')     admin-edited setting (App\Support\Settings::get)
 *   url('/shop', ['q' => 'x'])   absolute URL inside the app;  path_url('/shop') root-relative
 *   route('category', $cat)      URL of a named route;  route_is('shop', 'category')  current page check
 *   asset('assets/abiha/abiha.css')  versioned static file URL (asset($p, true) = absolute)
 *   media($path, $width)         public URL of an uploaded image (public disk), WebP variant when it exists
 *   media_srcset($path)          "url 320w, url 480w, ..." for the WebP variants (or '')
 *   icon('heart', 20, 'class')   inline SVG icon from public/assets/icons (aria-hidden)
 *   logo(76, $lazy)              the ABIHA logo (owner upload or the supplied artwork), like the old <x-logo>
 *   money(1200)                  "PKR 1,200"
 *   csrf_token() / csrf_field() / method_field('PATCH')
 *   old('email') / errors() / error('email') / has_error('email') / flash('status')
 *   now() / dt($datetime) / str_limit($s, 120) / slugify($s)
 */

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Money;
use App\Core\Session;

/** HTML-escape for text and attribute contexts. */
function e(mixed $value): string
{
    if (is_bool($value)) {
        $value = $value ? '1' : '';
    }
    // Same flags as Laravel's e() / Blade {{ }} (quotes as &quot; and &#039;).
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Raw environment value (real environment variables win over .env). */
function env(string $key, mixed $default = null): mixed
{
    $v = App\Core\Env::get($key);
    return $v === null ? $default : $v;
}

/** Configuration value with dot access: cfg('abiha.currency.symbol'). */
function cfg(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** Admin-edited setting (settings table, JSON values): setting('contact.email'). */
function setting(string $key, mixed $default = null): mixed
{
    return App\Support\Settings::get($key, $default);
}

/** Absolute URL for an app path ("/shop" -> "http://localhost/abiha.pk/shop"). Full http(s) URLs pass through. */
function url(string $path = '/', array $query = []): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $p = '/' . ltrim($path, '/');
    $u = Config::get('url') . ($p === '/' ? '' : $p);      // like Laravel: url('/') has no trailing slash
    if ($query) {
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '' && $v !== []);
        if ($query) {
            $u .= (str_contains($u, '?') ? '&' : '?') . http_build_query($query);
        }
    }
    return $u;
}

/** Root-relative URL (no scheme/host), including the base path of a sub-folder install. */
function path_url(string $path = '/'): string
{
    return (Config::get('base_path') ?: '') . '/' . ltrim($path, '/');
}

/** Static file under public/ with a cache-busting version: asset('assets/abiha/abiha.css'). */
function asset(string $path, bool $absolute = false): string
{
    $path = ltrim($path, '/');
    $file = PUBLIC_PATH . '/' . $path;
    $v = is_file($file) ? substr((string) filemtime($file), -8) : '1';
    return ($absolute ? url($path) : path_url($path)) . '?v=' . $v;
}

/** Public URL of an uploaded image at about $width pixels (WebP variant when one exists). See App\Support\Img. */
function media(?string $path, ?int $width = null): string
{
    return App\Support\Img::src($path, $width);
}

/** srcset of the WebP variants of an uploaded image, '' when there are none. */
function media_srcset(?string $path): string
{
    return $path ? (string) App\Support\Img::srcset($path) : '';
}

/** "PKR 1,200": whole rupees, the shop's currency label. */
function money(int|float|string|null $amount): string
{
    return Money::format($amount);
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '" autocomplete="off">';
}

/** Hidden _method field for PUT/PATCH/DELETE forms. */
function method_field(string $method): string
{
    return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
}

/**
 * Previously submitted value after a validation redirect (dot notation for arrays: old('items.0.qty')).
 * Password fields are never kept.
 */
function old(string $key, mixed $default = ''): mixed
{
    $v = Session::getFlash('old', []);
    foreach (explode('.', $key) as $seg) {
        if (!is_array($v) || !array_key_exists($seg, $v)) {
            return $default;
        }
        $v = $v[$seg];
    }
    return $v;
}

/** All validation errors of the last redirect: field => message. */
function errors(): array
{
    $e = Session::getFlash('errors', []);
    return is_array($e) ? $e : [];
}

/** First error message for a field, or null (Blade's @error / $errors->first()). */
function error(string $field): ?string
{
    $e = errors()[$field] ?? null;
    return is_array($e) ? (string) reset($e) : ($e === null ? null : (string) $e);
}

function has_error(string $field): bool
{
    return error($field) !== null;
}

/** Flash value from the previous request (Session::flash), e.g. flash('status'). */
function flash(string $key, mixed $default = null): mixed
{
    return Session::getFlash($key, $default);
}

/** Current business time (Asia/Karachi) as "Y-m-d H:i:s", the format every timestamp column uses. */
function now(): string
{
    return date('Y-m-d H:i:s');
}

/** Formats a stored datetime (business time). */
function dt(?string $datetime, string $format = 'j M Y, g:i a'): string
{
    if (!$datetime) {
        return '';
    }
    try {
        return (new DateTimeImmutable($datetime))->format($format);
    } catch (\Throwable) {
        return '';
    }
}

/** Shortens plain text (tags stripped) to $n characters, ending with "...". Like Str::limit. */
function str_limit(?string $s, int $n = 100, string $end = '...'): string
{
    $s = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $s)));
    return mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n)) . $end : $s;
}

/** Laravel's Str::slug. */
function slugify(string $s): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = mb_strtolower($ascii !== false ? $ascii : $s);
    // Same result as Laravel's Str::slug: "@" becomes "at", every other symbol (including "&") is dropped.
    $s = (string) preg_replace('/[^a-z0-9\s-]+/', '', str_replace('@', '-at-', $s));
    $s = (string) preg_replace('/[\s_-]+/', '-', $s);
    return trim($s, '-');
}

/**
 * Inline SVG icon (port of the old <x-icon> component): the Lucide file from public/assets/icons/<name>.svg with its
 * opening tag rewritten to width/height = $size, stroke-width 1.75, aria-hidden="true" focusable="false".
 * $attrs is either a class string or an array of extra attributes (['class' => 'x', 'x-bind:class' => '...']).
 */
function icon(string $name, int $size = 20, string|array $attrs = ''): string
{
    static $cache = [];
    $name = (string) preg_replace('/[^a-z0-9-]/', '', $name);
    if (!array_key_exists($name, $cache)) {
        $file = PUBLIC_PATH . '/assets/icons/' . $name . '.svg';
        $svg = is_file($file) ? (string) file_get_contents($file) : '';
        $svg = trim((string) preg_replace('/<!--.*?-->/s', '', $svg));
        $svg = (string) preg_replace('/\s*\n\s*/', ' ', $svg);
        $cache[$name] = $svg;
    }
    $svg = $cache[$name];
    if ($svg === '') {
        return '';
    }
    $extra = '';
    foreach (is_array($attrs) ? $attrs : ($attrs !== '' ? ['class' => $attrs] : []) as $k => $v) {
        $extra .= ' ' . preg_replace('/[^A-Za-z0-9_:.@-]/', '', (string) $k) . '="' . e($v) . '"';
    }
    return (string) preg_replace_callback('/<svg\b[^>]*>/s', static function (array $m) use ($size, $extra): string {
        $open = (string) preg_replace('/\s(class|width|height|stroke-width)="[^"]*"/', '', $m[0]);
        return (string) preg_replace('/<svg/', '<svg width="' . $size . '" height="' . $size . '" stroke-width="1.75" aria-hidden="true" focusable="false"' . $extra, $open, 1);
    }, $svg, 1);
}

/**
 * The ABIHA logo (port of the old <x-logo> component). An owner-uploaded logo (Admin -> Settings, setting "site.logo")
 * is served through the responsive image pipeline with its own aspect ratio; otherwise the supplied artwork
 * (public/assets/brand/abiha-logo-full-*). Never redrawn or retyped. $mono = the monogram artwork.
 */
function logo(int $width = 160, bool $lazy = false, bool $priority = false, bool $mono = false, string $class = ''): string
{
    $custom = $mono ? null : App\Support\SiteSettings::logoPath();
    $alt = (string) Config::get('abiha.name') . '®';
    $classes = trim('logo' . ($mono ? ' logo--mono' : '') . ($custom ? ' logo--own' : '') . ($class !== '' ? ' ' . $class : ''));
    $dim = $custom ? App\Support\Img::dimensions($custom) : null;
    $style = '--logo-w: ' . $width . 'px;' . ($dim ? ' --logo-ar: ' . $dim[0] . ' / ' . $dim[1] . ';' : '');
    $html = '<span class="' . e($classes) . '" style="' . e($style) . '">';
    if ($custom) {
        $html .= App\Support\Img::picture($custom, $alt, $width . 'px', !$lazy, $priority);
    } else {
        $brand = $mono ? 'abiha-monogram' : 'abiha-logo-full';
        $widths = $mono ? [96, 192, 320] : [240, 360, 480, 640];
        $fallback = $mono ? 'assets/brand/abiha-monogram-512.png' : ($width <= 150 ? 'assets/brand/abiha-logo-full-300.png' : 'assets/brand/abiha-logo-full-600.png');
        $height = $mono ? $width : (int) round($width * 836 / 1160);
        $srcset = implode(', ', array_map(static fn (int $w): string => asset("assets/brand/{$brand}-{$w}.webp") . ' ' . $w . 'w', $widths));
        $html .= '<picture><source type="image/webp" sizes="' . $width . 'px" srcset="' . e($srcset) . '">'
            . '<img src="' . e(asset($fallback)) . '" alt="' . e($alt) . '" width="' . $width . '" height="' . $height . '" decoding="async" loading="' . ($lazy ? 'lazy' : 'eager') . '"'
            . ($priority ? ' fetchpriority="high"' : '') . '></picture>';
    }
    return $html . '</span>';
}

/** Courier logo tile (port of <x-carrier-logo>): dhl, fedex or ups from public/images/carriers. */
function carrier_logo(string $code, int $height = 28): string
{
    $file = 'images/carriers/' . $code;
    if (!in_array($code, ['dhl', 'fedex', 'ups'], true) || !is_file(PUBLIC_PATH . '/' . $file . '.png')) {
        return '';
    }
    return '<picture class="carrier-logo"><source srcset="' . e(asset($file . '.webp')) . '" type="image/webp"><img src="' . e(asset($file . '.png'))
        . '" alt="' . e(strtoupper($code === 'fedex' ? 'FedEx' : $code)) . '" style="height:' . $height . 'px;width:auto" loading="lazy" decoding="async"></picture>';
}

/**
 * URL for a named route (Laravel's route()):
 *   route('category', ['slug' => 'shirts'])     placeholder by name
 *   route('category', 'shirts')                 a single value for the first placeholder
 *   route('category', $categoryRow)             a database row (has 'id' + columns): the placeholder's column
 *                                               ({slug} -> 'slug', {number} -> 'number', {id} -> 'id')
 *   route('shop', ['sort' => 'newest'])         keys that are not placeholders become the query string
 */
function route(string $name, array|object|string|int $params = [], bool $absolute = true): string
{
    $pattern = App\Core\Router::pattern($name);
    if ($pattern === null) {
        throw new InvalidArgumentException("Unknown route: {$name}");
    }
    preg_match_all('#\{([a-z_]+)\}#', $pattern, $keys);
    $keys = $keys[1];
    $isRecord = is_array($params) && $keys && array_key_exists('id', $params)
        && (array_key_exists('slug', $params) || array_key_exists('number', $params) || array_key_exists('name', $params) || array_key_exists('created_at', $params));
    if (!is_array($params) || $isRecord) {
        $params = $keys ? [$keys[0] => $params] : [];
    }
    $path = preg_replace_callback('#\{([a-z_]+)\}#', static function ($m) use (&$params) {
        $v = $params[$m[1]] ?? null;
        unset($params[$m[1]]);
        if (is_array($v)) {
            $v = $v[$m[1]] ?? $v['slug'] ?? $v['number'] ?? $v['id'] ?? '';
        } elseif (is_object($v)) {
            $v = $v->{$m[1]} ?? $v->slug ?? $v->number ?? $v->id ?? '';
        }
        return rawurlencode((string) $v);
    }, $pattern);
    $query = array_filter($params, static fn ($v) => $v !== null && $v !== '' && $v !== []);
    return $absolute ? url((string) $path, $query) : path_url((string) $path) . ($query ? '?' . http_build_query($query) : '');
}

/** True when a route with this name is registered (layouts skip links to pages a slice has not built yet). */
function route_exists(string $name): bool
{
    return App\Core\Router::pattern($name) !== null;
}

/**
 * Current request path matches one of these route names (Laravel's request()->routeIs()).
 * A trailing ".*" matches every route whose name starts with the prefix: route_is('admin.orders.*').
 */
function route_is(string ...$names): bool
{
    $current = (string) App\Core\View::shared('currentPath', '/');
    foreach ($names as $n) {
        $patterns = [];
        if (str_ends_with($n, '.*')) {
            foreach (App\Core\Router::names() as $rn => $p) {
                if (str_starts_with($rn, substr($n, 0, -1))) {
                    $patterns[] = $p;
                }
            }
        } elseif (($p = App\Core\Router::pattern($n)) !== null) {
            $patterns[] = $p;
        }
        foreach ($patterns as $p) {
            if (preg_match('#^' . preg_replace('#\\\\\{[a-z_]+\\\\\}#', '[^/]+', preg_quote($p, '#')) . '$#', $current)) {
                return true;
            }
        }
    }
    return false;
}

/** Outputs stored (already sanitised) rich HTML, rewriting root-relative links ("/contact") to the app base path. */
function rich(?string $html): string
{
    $base = (string) Config::get('base_path');
    $html = (string) $html;
    return $base === '' ? $html : (string) preg_replace('#\b(href|src)="/(?!/)#', '$1="' . $base . '/', $html);
}
