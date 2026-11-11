<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Cart\CartService;
use App\Services\WishlistService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Staff-only sign-in at /admin/login (separate from the customer login). */
class AdminLoginController extends Controller
{
    public function show(): View
    {
        return view('admin.login');
    }

    public function store(Request $request, CartService $cart, WishlistService $wishlist): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        $key = 'admin|'.Str::lower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $ok = Auth::attempt([...$credentials, 'is_active' => true]);
        if (! $ok || ! Auth::user()->isAdmin()) {
            if ($ok) {
                Auth::logout();               // valid customer credentials must not open the admin area
            }
            RateLimiter::hit($key, 60);
            // One message for every failure (wrong password, customer account, blocked account).
            throw ValidationException::withMessages(['email' => 'These credentials do not match a staff account.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();
        $wishlist->mergeIntoUser($user);
        $cart->mergeGuestInto($user);

        // Go back to the staff page they were after, but never to a leftover storefront URL (e.g. /account from an earlier visit).
        $intended = (string) $request->session()->pull('url.intended', '');
        $inAdmin = str_starts_with($intended, url('/admin/')) || $intended === url('/admin');

        return redirect()->to($inAdmin ? $intended : route('admin.dashboard'));
    }
}
