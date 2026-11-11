<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Per-request SEO state, bound as a singleton in the container (never a static, so nothing leaks between requests).
 * Pages describe themselves (`seo()->breadcrumbs(...)`, `->schema(...)`, `->image(...)`); the layout renders it all.
 *
 * Rules enforced here, in one place:
 *  - canonical URL = the page without query strings, except `?page=N` (N > 1), so tracking/sort/filter parameters
 *    never create duplicate pages;
 *  - filtered, sorted or searched listings are `noindex,follow` (their canonical points at the clean listing);
 *  - account, cart, checkout, auth and order pages are `noindex,nofollow`;
 *  - nothing is indexable outside production or when the owner has switched indexing off in the admin.
 */
class Seo
{
    /** Path prefixes that are private or transactional: never for a search index. */
    public const PRIVATE_PREFIXES = ['admin', 'account', 'cart', 'checkout', 'order', 'login', 'register', 'forgot-password', 'reset-password', 'wishlist', 'track-order', 'search', 'webhooks'];

    /** Query parameters that only change how a listing is shown. */
    public const VIEW_PARAMS = ['q', 'sort', 'min', 'max', 'availability', 'color', 'size', 'sale', 'category'];

    private array $schemas = [];
    private array $crumbs = [];
    private ?string $image = null;
    private ?string $type = null;
    private ?string $canonical = null;
    private bool $noindex = false;

    /** Start of every request: the instance is shared with views, so clear what a previous request set (matters under Octane and in tests). */
    public function reset(): static
    {
        $this->schemas = [];
        $this->crumbs = [];
        $this->image = $this->type = $this->canonical = null;
        $this->noindex = false;

        return $this;
    }

    /** @var array<int, array{name:string,url:string}> */
    public function breadcrumbs(array $items): static
    {
        $this->crumbs = collect($items)->map(fn ($i) => ['name' => (string) $i[0], 'url' => (string) ($i[1] ?? '')])->values()->all();

        return $this;
    }

    public function crumbs(): array
    {
        return $this->crumbs;
    }

    public function schema(array $data): static
    {
        $this->schemas[] = $data;

        return $this;
    }

    /** Social preview image: a storage path or an absolute/relative URL. */
    public function image(?string $pathOrUrl): static
    {
        $this->image = $pathOrUrl;

        return $this;
    }

    public function type(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function canonical(string $url): static
    {
        $this->canonical = $url;

        return $this;
    }

    public function noindex(bool $on = true): static
    {
        $this->noindex = $on;

        return $this;
    }

    public function ogType(): string
    {
        return $this->type ?? 'website';
    }

    public function hasCustomImage(): bool
    {
        return (bool) $this->image;
    }

    public function imageUrl(): ?string
    {
        $img = $this->image;
        if (! $img) {
            return asset('assets/brand/abiha-logo-full-600.png');          // the supplied artwork; no invented share image
        }

        return preg_match('#^https?://#i', $img) ? $img : (str_starts_with($img, '/') ? url($img) : self::absolute(Storage::disk('public')->url($img)));
    }

    public function canonicalUrl(?Request $request = null): string
    {
        if ($this->canonical) {
            return $this->canonical;
        }
        $request ??= request();
        $page = (int) $request->query('page', 1);
        $base = url($request->path() === '/' ? '/' : '/'.ltrim($request->path(), '/'));

        return $page > 1 && ! $this->hasViewParams($request) ? $base.'?page='.$page : $base;
    }

    /** Meta robots value, or null when the page may be indexed. */
    public function robots(?Request $request = null): ?string
    {
        $request ??= request();
        if (self::indexingDisabled()) {
            return 'noindex,nofollow';
        }
        if (self::isPrivatePath($request)) {
            return 'noindex,nofollow';
        }
        if ($this->noindex || $this->hasViewParams($request)) {
            return 'noindex,follow';
        }

        return null;
    }

    public function jsonLd(): string
    {
        $blocks = $this->schemas;
        if (count($this->crumbs) > 1) {
            $blocks[] = [
                '@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
                'itemListElement' => collect($this->crumbs)->values()->map(fn ($c, $i) => array_filter([
                    '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'],
                    'item' => $c['url'] ?: null,                       // the last crumb (current page) may omit its URL
                ]))->all(),
            ];
        }

        return collect($blocks)
            ->map(fn ($b) => '<script type="application/ld+json">'.json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP).'</script>')
            ->implode("\n");
    }

    public static function indexingDisabled(): bool
    {
        return ! app()->isProduction() || ! Setting::get('seo.allow_indexing', true);
    }

    public static function isPrivatePath(Request $request): bool
    {
        $first = explode('/', trim($request->path(), '/'))[0] ?? '';

        return in_array($first, self::PRIVATE_PREFIXES, true);
    }

    public function hasViewParams(Request $request): bool
    {
        return $request->query->count() > 0 && collect(self::VIEW_PARAMS)->contains(fn ($k) => $request->query->has($k));
    }

    /** Storage::url() is already absolute when APP_URL is set, but be sure: structured data and OG tags must be absolute. */
    public static function absolute(string $url): string
    {
        return preg_match('#^https?://#i', $url) ? $url : url($url);
    }

    public static function plain(?string $html, int $limit = 160): string
    {
        // Text may arrive HTML-escaped (Blade escapes @section values) and may contain markup: unescape, drop script/style
        // bodies and tags, unescape the remaining entities, then collapse whitespace.
        $raw = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5);
        $raw = preg_replace('#<(script|style)\b.*?</>#is', '', $raw);
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5)));

        return \Illuminate\Support\Str::limit($text, $limit, '…');
    }
}
