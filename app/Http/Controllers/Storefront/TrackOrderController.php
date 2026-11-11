<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TrackOrderController extends Controller
{
    public function form(): View
    {
        return view('storefront.track', ['order' => null]);
    }

    /** Guests look up an order with number + the email used at checkout (both must match). */
    public function lookup(Request $request): View|RedirectResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:190'],
        ]);

        $order = Order::query()->where('number', strtoupper(trim($data['number'])))
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])
            ->with(['items', 'history'])->first();

        if (! $order) {
            // Same message whether the number or the email was wrong (no order enumeration).
            return back()->withInput($request->only('number', 'email'))->withErrors(['number' => 'We could not find an order matching those details.']);
        }

        return view('storefront.track', ['order' => $order]);
    }
}
