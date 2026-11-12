<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Auth\Auth;
use App\Controllers\Controller;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;

/** Port of the old Auth\LoginController (sign in / sign out). */
final class LoginController extends Controller
{
    public function show(Request $request): Response
    {
        return $this->view('auth/login', [], ['title' => 'Sign in', 'noindex' => true]);
    }

    public function store(Request $request): Response
    {
        $credentials = $this->validate($request, ['email' => 'required|email', 'password' => 'required|string']);

        // Per email+IP lockout on top of the route throttle (slows credential stuffing).
        $throttleKey = 'login-lock|' . mb_strtolower((string) $credentials['email']) . '|' . $request->ip();
        if (RateLimiter::tooMany($throttleKey, 5, 60)) {
            $seconds = RateLimiter::availableIn($throttleKey, 60);
            throw ValidationException::withMessages(['email' => "Too many attempts. Try again in {$seconds} seconds."]);
        }

        $row = Auth::attempt((string) $credentials['email'], (string) ($request->post['password'] ?? ''));
        if (!$row) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        RateLimiter::clear($throttleKey, 60);
        Auth::login($row);                              // new session id: prevents session fixation
        self::mergeGuestData((int) $row['id']);

        // Staff land in the admin area, customers in their account.
        return $this->intended(Auth::isStaff() ? route('admin.dashboard') : route('account.dashboard'));
    }

    public function destroy(Request $request): Response
    {
        Auth::logout();                                 // whole session cleared, new id and CSRF token
        return $this->redirect(route('home'));
    }

    /**
     * Guest bag and wishlist move into the account (old CartService::mergeGuestInto / WishlistService::mergeIntoUser).
     * Implemented by the cart & orders and storefront slices; skipped while they are not there yet.
     */
    public static function mergeGuestData(int $userId): void
    {
        if (method_exists(\App\Services\Wishlist::class, 'mergeGuest')) {
            \App\Services\Wishlist::mergeGuest($userId);
        }
        if (method_exists(\App\Services\Cart::class, 'mergeGuest')) {
            \App\Services\Cart::mergeGuest($userId);
        }
    }
}
