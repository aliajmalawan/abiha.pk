<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;
use App\Services\Admin\Rules;
use App\Support\CatalogCache;
use App\Support\Menus;
use App\Support\Settings;

/** Edit the header and footer link lists. Links must be site paths or http(s) addresses. */
final class MenuController extends AdminController
{
    public function edit(Request $request): Response
    {
        $rows = [];
        foreach (array_keys(Menus::LISTS) as $k) {
            $rows[$k] = Menus::rows($k);
        }
        return $this->adminView('admin/menus', ['lists' => Menus::LISTS, 'rows' => $rows], 'Menus');
    }

    public function update(Request $request): Response
    {
        $back = route('admin.menus.edit');
        if ($request->input('restore')) {                                   // "Restore the default links" for one list
            $key = (string) (is_scalar($request->input('restore')) ? $request->input('restore') : '');
            if (!isset(Menus::LISTS[$key])) {
                throw new HttpException(422);
            }
            Settings::forget('menu.' . $key);
            ActivityLogger::log('menus.restored', null, null, ['list' => $key]);
            CatalogCache::bump();
            return $this->back($back, 'Default links restored.');
        }

        $in = $request->post;
        $rules = [];
        foreach (array_keys(Menus::LISTS) as $key) {
            $rules[$key] = 'nullable|array|max:12';
            $rules["$key.*.label"] = 'required|string|max:40';
            $rules["$key.*.url"] = 'required|string|max:255';
        }
        $errors = Rules::run($in, $rules);
        foreach (array_keys(Menus::LISTS) as $key) {
            foreach (is_array($in[$key] ?? null) ? $in[$key] : [] as $i => $row) {
                $url = is_array($row) && is_string($row['url'] ?? null) ? trim($row['url']) : null;
                if ($url !== null && $url !== '' && !isset($errors["$key.$i.url"]) && !Menus::safe($url)) {
                    $errors["$key.$i.url"] = 'Use a site address such as /shop, or a full https:// address.';
                }
            }
        }
        Rules::throwIf($errors);

        foreach (array_keys(Menus::LISTS) as $key) {
            $rows = [];
            foreach (is_array($in[$key] ?? null) ? $in[$key] : [] as $r) {
                $rows[] = ['label' => trim(strip_tags((string) $r['label'])), 'url' => trim((string) $r['url'])];
            }
            Settings::put('menu.' . $key, $rows);
        }
        ActivityLogger::log('menus.updated', null, null, []);
        CatalogCache::bump();
        return $this->back($back, 'Menus saved.');
    }
}
