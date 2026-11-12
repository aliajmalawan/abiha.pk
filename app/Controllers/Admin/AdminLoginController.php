<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Controllers\Auth\LoginController;
use App\Controllers\Controller;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\View;

/** Staff-only sign-in at /admin/login (separate from the customer login). Port of the old Admin\AdminLoginController. */
final class AdminLoginController extends Controller
{
    public function show(Request $request): Response
    {
        View::share('errors', errors());
        return Response::html(View::partial('admin/login'));
    }

    public function store(Request $request): Response
    {
        $credentials = $this->validate($request, ['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        $key = 'admin|' . mb_strtolower((string) $credentials['email']) . '|' . $request->ip();
        if (RateLimiter::tooMany($key, 5, 60)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in ' . RateLimiter::availableIn($key, 60) . ' seconds.']);
        }

        $row = Auth::attempt((string) $credentials['email'], (string) ($request->post['password'] ?? ''));
        // One message for every failure (wrong password, customer account, blocked account): valid customer
        // credentials must not open the admin area, and nobody learns which accounts are staff.
        if (!$row || !Auth::present($row)['is_staff']) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'These credentials do not match a staff account.']);
        }

        RateLimiter::clear($key, 60);
        Auth::login($row);                                  // new session id, last_login_at
        LoginController::mergeGuestData((int) $row['id']);

        // Back to the staff page they were after, but never to a leftover storefront URL (e.g. /account from an earlier visit).
        $intended = Session::pull('intended');
        $intended = is_string($intended) ? $intended : '';
        $inAdmin = $intended === '/admin' || str_starts_with($intended, '/admin/');
        return $this->redirect($inAdmin ? $this->safeNext($intended, route('admin.dashboard')) : route('admin.dashboard'));
    }
}
