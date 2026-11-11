<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Newsletter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NewsletterController extends Controller
{
    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);
        $row = Newsletter::firstOrCreate(['email' => mb_strtolower($data['email'])]);
        if ($row->unsubscribed_at) {
            $row->update(['unsubscribed_at' => null, 'subscribed_at' => now()]);   // re-subscribe
        }

        // Same response whether new or existing (does not reveal who is subscribed).
        $message = 'Thank you for subscribing.';

        return $request->expectsJson() ? response()->json(['ok' => true, 'message' => $message]) : back()->with('newsletter', $message);
    }
}
