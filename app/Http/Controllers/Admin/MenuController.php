<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Support\CatalogCache;
use App\Support\Menus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Edit the header and footer link lists. Links must be site paths or http(s) addresses. */
class MenuController extends Controller
{
    public function edit(): View
    {
        return view('admin.menus', ['lists' => Menus::LISTS, 'rows' => collect(array_keys(Menus::LISTS))->mapWithKeys(fn ($k) => [$k => Menus::rows($k)])->all()]);
    }

    public function update(Request $request): RedirectResponse
    {
        if ($request->input('restore')) {                                   // "Restore the default links" for one list
            $key = (string) $request->input('restore');
            abort_unless(isset(Menus::LISTS[$key]), 422);
            Setting::query()->where('key', 'menu.'.$key)->delete();
            \Illuminate\Support\Facades\Cache::forget('settings.all');
            ActivityLogger::log('menus.restored', null, ['list' => $key]);
            CatalogCache::bump();

            return back()->with('status', 'Default links restored.');
        }

        $rules = [];
        foreach (array_keys(Menus::LISTS) as $key) {
            $rules[$key] = ['nullable', 'array', 'max:12'];
            $rules["$key.*.label"] = ['required', 'string', 'max:40'];
            $rules["$key.*.url"] = ['required', 'string', 'max:255', function ($attr, $value, $fail) {
                if (! Menus::safe(trim((string) $value))) {
                    $fail('Use a site address such as /shop, or a full https:// address.');
                }
            }];
        }
        $data = $request->validate($rules);

        foreach (array_keys(Menus::LISTS) as $key) {
            $rows = collect($data[$key] ?? [])->map(fn ($r) => ['label' => trim(strip_tags($r['label'])), 'url' => trim($r['url'])])->values()->all();
            Setting::put('menu.'.$key, $rows);
        }
        ActivityLogger::log('menus.updated', null, []);
        CatalogCache::bump();

        return back()->with('status', 'Menus saved.');
    }
}
