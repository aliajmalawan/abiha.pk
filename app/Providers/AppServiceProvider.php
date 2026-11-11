<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Setting;
use App\Services\Cart\CartService;
use App\Services\WishlistService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Split layout (web root outside the app folder): make artisan agree with the web front controller about where public files live.
        if ($public = config('app.public_path')) {
            $this->app->usePublicPath($public);
        }

        // One cart / wishlist object per request.
        $this->app->scoped(CartService::class);
        $this->app->scoped(WishlistService::class);
        // Per-request SEO state (canonical, robots, breadcrumbs, structured data): a container singleton, never a static.
        $this->app->singleton(\App\Support\Seo::class);
    }

    public function boot(): void
    {
        Paginator::defaultView('vendor.pagination.abiha');

        $this->hardenProduction();
        $this->defineRateLimiters();
        $this->invalidateCatalogCache();
        View::share('seo', $this->app->make(\App\Support\Seo::class));

        // Admin-edited site name, currency and mail sender override the config defaults.
        \App\Support\SiteSettings::apply();

        // Dotted abilities ('products.manage') are permissions granted through the user's role.
        Gate::before(fn ($user, string $ability) => str_contains($ability, '.') ? $user->hasPermission($ability) : null);

        // Data every storefront page needs (header, mega menu, badges).
        View::composer(['layouts.app', 'partials.header'], function ($view) {
            // Computed once per REQUEST (header + layout both ask), never across requests.
            $attrs = request()->attributes;
            if (! $attrs->has('abiha.shared')) {
                $attrs->set('abiha.shared', [
                    'navCategories' => \App\Support\CatalogCache::remember('nav.categories', 600, fn () => Category::query()->active()->roots()->with('children')->orderBy('sort_order')->get()),
                    'cartCount' => app(CartService::class)->count(),
                    'wishlistIds' => app(WishlistService::class)->ids(),
                    'announcement' => ['text' => Setting::get('announcement.text'), 'url' => Setting::get('announcement.url')],
                ]);
            }
            $view->with($attrs->get('abiha.shared'));
        });
    }

    /** Anything that changes what the storefront shows orphans the cached catalogue data at once. */
    private function invalidateCatalogCache(): void
    {
        foreach ([\App\Models\Product::class, \App\Models\ProductVariant::class, \App\Models\ProductImage::class, \App\Models\Category::class, \App\Models\Collection::class,
            \App\Models\Banner::class, \App\Models\HomeSection::class, \App\Models\Testimonial::class, \App\Models\Page::class, \App\Models\Review::class] as $model) {
            $model::saved(fn () => \App\Support\CatalogCache::bump());
            $model::deleted(fn () => \App\Support\CatalogCache::bump());
        }
    }

    /** Production-only guarantees that do not depend on someone remembering the right .env values. */
    private function hardenProduction(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        URL::forceScheme('https');                       // every generated link and redirect is https
        URL::forceRootUrl((string) config('app.url'));   // reset-password and order links never use a client-supplied Host header
        config(['session.secure' => true]);              // session + CSRF cookies are never sent over plain http
    }

    /**
     * Named limiters. Keyed by signed-in user when there is one (so one noisy account cannot lock out a shared IP),
     * otherwise by IP. Applied in routes/web.php; "web-general" wraps every web request as a broad ceiling.
     */
    private function defineRateLimiters(): void
    {
        $key = fn (Request $r) => $r->user()?->id ? 'u'.$r->user()->id : 'ip'.$r->ip();

        RateLimiter::for('web-general', fn (Request $r) => Limit::perMinute(240)->by($key($r)));
        RateLimiter::for('account', fn (Request $r) => Limit::perMinute(60)->by($key($r)));
        RateLimiter::for('cart-write', fn (Request $r) => Limit::perMinute(120)->by($key($r)));
        RateLimiter::for('search', fn (Request $r) => Limit::perMinute(60)->by($key($r)));
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perMinute(5)->by('ip'.$r->ip()));
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(120)->by('ip'.$r->ip()));

        // One limiter PER ACTION. (Laravel's numeric `throttle:10,1` shares a single counter across every route that uses
        // it, so adding items to the bag would have eaten the checkout allowance.)
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(10)->by('ip'.$r->ip()));
        RateLimiter::for('admin-login', fn (Request $r) => Limit::perMinute(10)->by('ip'.$r->ip()));
        RateLimiter::for('register', fn (Request $r) => Limit::perMinute(10)->by('ip'.$r->ip()));
        RateLimiter::for('forgot-password', fn (Request $r) => Limit::perMinute(5)->by('ip'.$r->ip()));
        RateLimiter::for('contact', fn (Request $r) => Limit::perMinute(5)->by('ip'.$r->ip()));
        RateLimiter::for('newsletter', fn (Request $r) => Limit::perMinute(5)->by('ip'.$r->ip()));
        RateLimiter::for('track-order', fn (Request $r) => Limit::perMinute(10)->by('ip'.$r->ip()));
        RateLimiter::for('checkout', fn (Request $r) => Limit::perMinute(10)->by($key($r)));
        RateLimiter::for('coupon', fn (Request $r) => Limit::perMinute(10)->by($key($r)));
        RateLimiter::for('cart-add', fn (Request $r) => Limit::perMinute(60)->by($key($r)));
        RateLimiter::for('review', fn (Request $r) => Limit::perMinute(6)->by($key($r)));
        RateLimiter::for('payment-reference', fn (Request $r) => Limit::perMinute(10)->by($key($r)));
    }
}
