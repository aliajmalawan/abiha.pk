<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A staff member's own alerts. Nobody can read or change another person's. */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.notifications', ['notifications' => $request->user()->notifications()->latest()->paginate(20)]);
    }

    /** Opens the alert's page and marks it read. Only a link inside the admin area is followed. */
    public function read(Request $request, string $id): RedirectResponse
    {
        $n = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $n->markAsRead();
        $url = (string) ($n->data['url'] ?? '');

        return str_starts_with($url, '/') && ! str_starts_with($url, '//') ? redirect($url) : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('status', 'All alerts marked as read.');
    }
}
