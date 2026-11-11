<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After a failed form, Laravel flashes everything that was submitted so the form can be re-filled with old().
 * A visitor can submit `name[]=x`: the flashed value is then an array, and the form page crashes when it prints it.
 * No public form has array fields, so outside the staff area only plain text values are kept for re-filling.
 * (Staff forms legitimately repeat fields, e.g. product variants, so they keep their arrays.)
 */
class ScalarOldInput
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('admin', 'admin/*') && $request->hasSession() && is_array($old = $request->session()->get('_old_input'))) {
            $request->session()->flash('_old_input', array_filter($old, fn ($v) => is_scalar($v) || $v === null));
        }

        return $response;
    }
}
