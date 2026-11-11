<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'user' => ['nullable', 'integer']]);

        return view('admin.logs', [
            'logs' => ActivityLog::query()->with('user:id,name')
                ->when($f['q'] ?? null, fn ($q, $t) => $q->where('action', 'like', '%'.addcslashes($t, '%_\\').'%'))
                ->when($f['user'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
                ->latest('id')->paginate(40)->withQueryString(),
            'filters' => $f,
        ]);
    }
}
