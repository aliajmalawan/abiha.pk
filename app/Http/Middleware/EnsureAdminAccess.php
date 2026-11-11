<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only active staff accounts may enter /admin, and an unattended admin session expires.
 * Per-feature access is enforced separately by `can:<permission>` on each route.
 */
class EnsureAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->is_active && $user->isAdmin(), 403, 'You do not have access to the admin area.');

        // Idle timeout: a laptop left open on a shared desk must not stay signed in to the back office.
        $idle = (int) config('abiha.admin_idle_minutes', 30) * 60;
        $last = (int) $request->session()->get('admin.last_activity', 0);
        if ($last > 0 && time() - $last > $idle) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->guest(route('admin.login'))->withErrors(['email' => 'You were signed out after '.config('abiha.admin_idle_minutes', 30).' minutes of inactivity. Please sign in again.']);
        }
        $request->session()->put('admin.last_activity', time());

        return $next($request);
    }
}
