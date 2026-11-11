<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function dashboard(Request $request): View
    {
        $user = $request->user();

        return view('account.dashboard', [
            'recentOrders' => $user->orders()->with('items')->limit(3)->get(),
            'orderCount' => $user->orders()->count(),
            'wishlistCount' => $user->wishlist()->first()?->items()->count() ?? 0,
            'defaultAddress' => $user->addresses()->first(),
        ]);
    }

    public function profile(): View
    {
        return view('account.profile');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($request->user()->id)],
            'phone' => ['nullable', 'regex:/^[\d+\-\s()]{7,20}$/'],
            // Changing the sign-in email is an account-takeover path, so it needs the current password.
            'email_password' => [Rule::requiredIf(fn () => mb_strtolower(is_string($request->input("email")) ? $request->input("email") : "") !== mb_strtolower($request->user()->email)), 'nullable', 'bail', 'string', 'current_password'],
        ], ['email_password.required' => 'Enter your current password to change your email address.']);
        unset($data['email_password']);
        $request->user()->update($data);

        return back()->with('status', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'bail', 'string', 'current_password'],
            'password' => ['required', 'confirmed', 'max:72', Password::min(8)->letters()->numbers(), 'different:current_password'],
        ]);
        $request->user()->update(['password' => $request->input('password')]);
        \Illuminate\Support\Facades\Auth::logoutOtherDevices($request->input('password'));   // every other session and remember-me cookie dies
        $request->session()->regenerate();

        return back()->with('status', 'Password changed.');
    }
}
