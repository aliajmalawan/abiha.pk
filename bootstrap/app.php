<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Providers POST here server-to-server; they authenticate via signature, not CSRF.
        $middleware->validateCsrfTokens(except: ['webhooks/payments/*']);

        // Host-header attacks (poisoned password-reset links, cache poisoning): in production only our own domain is served.
        $middleware->trustHosts(
            // Symfony treats each entry as a REGEX, so a bare host would be unanchored and its dot a wildcard:
            // "abiha.pk" would also trust "evil-abiha.pk" and "abiha.pk.evil.example". Anchor and escape it.
            at: fn () => app()->isProduction() && ($host = parse_url((string) config('app.url'), PHP_URL_HOST)) ? ['^'.preg_quote($host, '/').'$'] : [],
            subdomains: true,
        );

        $middleware->web(append: [
            \App\Http\Middleware\ScalarOldInput::class,
            EnsureAccountActive::class,                      // blocked accounts lose access on their very next request
            AuthenticateSession::class,                      // changing a password signs out every other device
            SecurityHeaders::class,                          // CSP, frame/nosniff/referrer/permissions, HSTS, no-store on private pages
            ThrottleRequests::class.':web-general',          // broad per-visitor ceiling; sensitive routes add tighter named limiters
        ]);

        $middleware->alias(['admin' => EnsureAdminAccess::class]);

        // Staff areas send guests to the staff sign-in; everything else to the customer sign-in.
        $middleware->redirectGuestsTo(fn (Request $r) => $r->is('admin', 'admin/*') ? route('admin.login') : route('login'));
        $middleware->redirectUsersTo(fn (Request $r) => $r->user()?->isAdmin() ? route('admin.dashboard') : route('account.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
