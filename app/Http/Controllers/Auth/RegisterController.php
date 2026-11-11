<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Models\Role;
use App\Services\Cart\CartService;
use App\Services\WishlistService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class RegisterController extends Controller
{
    public function show(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request, WishlistService $wishlist, CartService $cart): RedirectResponse
    {
        // The role is never taken from input: every self-registration is a customer.
        $user = new User($request->safe()->only(['name', 'email', 'phone', 'password']));
        $user->role_id = Role::query()->where('slug', Role::CUSTOMER)->value('id');
        $user->save();

        Auth::login($user);
        $request->session()->regenerate();
        $wishlist->mergeIntoUser($user);
        $cart->mergeGuestInto($user);

        return redirect()->intended(route('account.dashboard'))->with('status', 'Welcome to ABIHA®. Your account is ready.');
    }
}
