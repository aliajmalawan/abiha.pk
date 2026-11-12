<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Services\Cart;
use App\Services\Navigation;
use App\Services\Wishlist;
use App\Support\Seo;
use App\Support\Settings;

/**
 * Base controller. Handlers receive the Request and return a Response (or a string = HTML 200).
 *
 *   return $this->view('store/shop', ['products' => $p], ['title' => 'Shop', 'description' => '...']);
 *   return $this->adminView('admin/orders/index', ['orders' => $o], 'Orders');
 *   return $this->partial('store/partials/cart-drawer', $data);        // HTML fragment (drawer, quick view)
 *   return $this->json(['ok' => true, 'count' => 3]);
 *   $data = $this->validate($request, ['email' => 'required|email'], $messages, $attributes);   // redirects back on failure
 *   return $this->redirect(route('account.dashboard'), status: 'Your profile was updated.');
 *   return $this->back(status: 'Saved.');   return $this->intended(route('account.dashboard'));
 *
 * $meta keys for view(): title (page title without site name; the layout adds " | ABIHA®"), description, noindex (bool),
 * canonical, image (public-disk path or URL), type (og:type), breadcrumbs ([[name, url], ...]), jsonld (list of arrays).
 * Templates may also call App\Support\Seo::title()/noindex()/schema()... themselves (they render before the layout).
 *
 * Views get: $errors (field => message), $user (Auth::user() or null), $cartCount, $wishlistIds, $navCategories,
 * $announcement (['text', 'url']) plus everything in $data. Flash: flash('status'), flash('error').
 */
abstract class Controller
{
    protected function view(string $template, array $data = [], array $meta = [], int $status = 200): Response
    {
        $this->applyMeta($meta);
        $this->shareChrome();
        return Response::html(View::render($template, $data, 'layouts/store'), $status);
    }

    protected function adminView(string $template, array $data = [], ?string $title = null, int $status = 200): Response
    {
        View::share('errors', errors());
        View::share('user', Auth::user());
        return Response::html(View::render($template, $data + ['title' => $title ?? ($data['title'] ?? 'Admin')], 'layouts/admin'), $status);
    }

    /** HTML fragment without a layout (cart drawer, quick view, search results). */
    protected function partial(string $template, array $data = [], int $status = 200): Response
    {
        View::share('errors', errors());
        View::share('user', Auth::user());
        return Response::html(View::partial($template, $data), $status);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /** Validates request input (or an array); on failure throws -> redirect back with errors + old input (or 422 JSON). */
    protected function validate(Request|array $input, array $rules, array $messages = [], array $attributes = []): array
    {
        $data = $input instanceof Request ? $input->post + $input->query : $input;
        return Validator::make($data, $rules, $messages, $attributes)->validated();
    }

    protected function redirect(string $to, ?string $status = null, ?string $error = null, int $code = 303): Response
    {
        if ($status !== null) {
            Session::flash('status', $status);
        }
        if ($error !== null) {
            Session::flash('error', $error);
        }
        return Response::redirect($to, $code);
    }

    /** Redirect to the previous page (same-site Referer only), else $fallback. */
    protected function back(string $fallback = '/', ?string $status = null, ?string $error = null): Response
    {
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $request = View::shared('request');
        if ($request instanceof Request) {
            $ref = (string) $request->header('Referer');
        }
        $base = (string) Config::get('url');
        return $this->redirect($ref !== '' && str_starts_with($ref, $base) ? $ref : $fallback, $status, $error);
    }

    /** Laravel's redirect()->intended(): the page a guest was sent away from (saved by the auth middleware), else $default. */
    protected function intended(string $default, ?string $status = null): Response
    {
        $to = Session::pull('intended');
        return $this->redirect(is_string($to) ? $this->safeNext($to, $default) : $default, $status);
    }

    /** Whitelists an internal redirect target (prevents open redirects). */
    protected function safeNext(?string $next, string $fallback): string
    {
        if (!$next || !preg_match('#^/[A-Za-z0-9/_\-?=&.%+,~]*$#', $next) || str_starts_with($next, '//')) {
            return $fallback;
        }
        return $next;
    }

    /** Applies the $meta array of view() to the per-request SEO state. */
    protected function applyMeta(array $meta): void
    {
        if (isset($meta['title'])) {
            Seo::title((string) $meta['title']);
        }
        if (isset($meta['description'])) {
            Seo::description((string) $meta['description']);
        }
        if (!empty($meta['noindex'])) {
            Seo::noindex();
        }
        if (!empty($meta['canonical'])) {
            Seo::canonical((string) $meta['canonical']);
        }
        if (!empty($meta['image'])) {
            Seo::image((string) $meta['image']);
        }
        if (!empty($meta['type'])) {
            Seo::type((string) $meta['type']);
        }
        if (!empty($meta['breadcrumbs'])) {
            Seo::breadcrumbs($meta['breadcrumbs']);
        }
        foreach ($meta['jsonld'] ?? [] as $block) {
            Seo::schema($block);
        }
    }

    /**
     * Data every storefront page needs (header, mega menu, badges, layout config), computed once per request.
     * The old AppServiceProvider view composer for layouts.app / partials.header.
     */
    protected function shareChrome(): void
    {
        if (View::shared('__chrome')) {
            return;
        }
        View::share('__chrome', true);
        View::share('errors', errors());
        View::share('user', Auth::user());
        View::share('navCategories', Navigation::categories());
        View::share('cartCount', Cart::count());
        View::share('wishlistIds', Wishlist::ids());
        View::share('announcement', ['text' => Settings::get('announcement.text'), 'url' => Settings::get('announcement.url')]);
    }
}
