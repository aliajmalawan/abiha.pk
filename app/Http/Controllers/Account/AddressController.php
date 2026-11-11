<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddressRequest;
use App\Models\Address;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AddressController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.addresses', ['addresses' => $request->user()->addresses]);
    }

    public function store(AddressRequest $request): RedirectResponse
    {
        $user = $request->user();
        $makeDefault = $request->boolean('is_default') || ! $user->addresses()->exists();
        if ($makeDefault) {
            $user->addresses()->update(['is_default' => false]);
        }
        $user->addresses()->create([...$request->safe()->except('is_default'), 'is_default' => $makeDefault]);

        return back()->with('status', 'Address saved.');
    }

    public function update(AddressRequest $request, Address $address): RedirectResponse
    {
        Gate::authorize('manage', $address);
        if ($request->boolean('is_default')) {
            $request->user()->addresses()->update(['is_default' => false]);
        }
        $address->update([...$request->safe()->except('is_default'), 'is_default' => $request->boolean('is_default') || $address->is_default]);

        return back()->with('status', 'Address updated.');
    }

    public function destroy(Address $address): RedirectResponse
    {
        Gate::authorize('manage', $address);
        $wasDefault = $address->is_default;
        $address->delete();
        if ($wasDefault) {
            auth()->user()->addresses()->first()?->update(['is_default' => true]);
        }

        return back()->with('status', 'Address removed.');
    }
}
