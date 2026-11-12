<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;
use App\Services\Admin\Pager;
use App\Services\Admin\Rules;
use App\Services\ImageStore;
use App\Support\CatalogCache;
use App\Support\Img;

/**
 * Generic, permission-guarded CRUD for simple tables (port of the old Admin\ResourceController). Subclasses declare
 * config():
 *   table, model (short class name for the activity log, e.g. 'Category'), label, plural, route (route-name prefix),
 *   soft (bool, deleted_at), search[], order[col, dir], select[] (extra SQL columns on alias t, e.g.
 *   "(SELECT COUNT(*) ...) AS products_count"), columns[[key, title, type?]] (a dotted key "parent.name" reads the
 *   alias "parent_name"), fields[], rules(?array $row) => Validator rules (wildcards allowed), defaults[], imageDir,
 *   partial (view included under the form when editing).
 * Field types: text, textarea, number, select, checkbox, datetime, image, multiselect (needs 'pivot' => [table, ownKey,
 *   otherKey], 'options' => fn() => [id => label]).
 * Hooks: beforeSave(array &$data, ?array $row), afterSave(array $row), blockDelete(array $row).
 * Routes: index, create, store, edit, update, destroy (register them with ResourceController::routes()).
 */
abstract class ResourceController extends AdminController
{
    abstract protected function config(): array;

    protected function cfg(): array
    {
        return $this->config() + ['soft' => false, 'search' => [], 'order' => ['id', 'desc'], 'select' => [], 'columns' => [], 'fields' => [], 'imageDir' => 'uploads', 'defaults' => [], 'partial' => null];
    }

    /** Registers the six resource routes (Laravel's Route::resource()->except('show')) with the given middleware. */
    public static function routes(\App\Core\Router $router, string $path, string $name, array $mw): void
    {
        $router->get("/admin/{$path}", [static::class, 'index'], $mw, "{$name}.index");
        $router->get("/admin/{$path}/create", [static::class, 'create'], $mw, "{$name}.create");
        $router->post("/admin/{$path}", [static::class, 'store'], $mw, "{$name}.store");
        $router->get("/admin/{$path}/{id:\\d+}/edit", [static::class, 'edit'], $mw, "{$name}.edit");
        $router->put("/admin/{$path}/{id:\\d+}", [static::class, 'update'], $mw, "{$name}.update");
        $router->patch("/admin/{$path}/{id:\\d+}", [static::class, 'update'], $mw);
        $router->delete("/admin/{$path}/{id:\\d+}", [static::class, 'destroy'], $mw, "{$name}.destroy");
    }

    public function index(Request $request): Response
    {
        $c = $this->cfg();
        $term = trim(is_string($request->query['q'] ?? null) ? $request->query['q'] : '');
        $where = $c['soft'] ? ['t.deleted_at IS NULL'] : ['1 = 1'];
        $params = [];
        if ($term !== '' && $c['search']) {
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $where[] = '(' . implode(' OR ', array_map(static fn ($col) => "t.`{$col}` LIKE ?", $c['search'])) . ')';
            $params = array_fill(0, count($c['search']), $like);
        }
        [$col, $dir] = $c['order'];
        $sql = 'SELECT t.*' . ($c['select'] ? ', ' . implode(', ', $c['select']) : '') . " FROM `{$c['table']}` t WHERE " . implode(' AND ', $where)
            . " ORDER BY t.`{$col}` " . (strtolower($dir) === 'desc' ? 'DESC' : 'ASC') . ', t.id ASC';
        $rows = Pager::query($request, $sql, $params, (int) cfg('abiha.pagination.admin', 20));
        return $this->adminView('admin/resource/index', ['c' => $c, 'rows' => $rows, 'term' => $term], $c['plural']);
    }

    public function create(Request $request): Response
    {
        $c = $this->cfg();
        return $this->adminView('admin/resource/form', ['c' => $c, 'model' => null, 'values' => $c['defaults'], 'selected' => []], 'New ' . strtolower($c['label']));
    }

    public function store(Request $request): Response
    {
        return $this->persist($request, null);
    }

    public function edit(Request $request): Response
    {
        $c = $this->cfg();
        $row = $this->find((int) $request->params['id']);
        $selected = [];
        foreach ($c['fields'] as $f) {
            if (($f['type'] ?? '') === 'multiselect') {
                [$pt, $own, $other] = $f['pivot'];
                $selected[$f['name']] = array_map('intval', Database::get()->column("SELECT `{$other}` FROM `{$pt}` WHERE `{$own}` = ?", [(int) $row['id']]));
            }
        }
        return $this->adminView('admin/resource/form', ['c' => $c, 'model' => $row, 'values' => $row, 'selected' => $selected], 'Edit ' . strtolower($c['label']));
    }

    public function update(Request $request): Response
    {
        return $this->persist($request, $this->find((int) $request->params['id']));
    }

    public function destroy(Request $request): Response
    {
        $c = $this->cfg();
        $row = $this->find((int) $request->params['id']);
        if ($reason = $this->blockDelete($row)) {
            return $this->backWithErrors(['delete' => $reason], route($c['route'] . '.index'));
        }
        foreach ($c['fields'] as $f) {
            if (($f['type'] ?? '') === 'image' && !empty($row[$f['name']])) {
                Img::delete((string) $row[$f['name']]);
            }
        }
        $db = Database::get();
        if ($c['soft']) {
            $db->exec("UPDATE `{$c['table']}` SET deleted_at = ?, updated_at = ? WHERE id = ?", [now(), now(), (int) $row['id']]);
        } else {
            $db->exec("DELETE FROM `{$c['table']}` WHERE id = ?", [(int) $row['id']]);
        }
        ActivityLogger::log($c['route'] . '.deleted', $c['model'], (int) $row['id'], ['id' => (int) $row['id']]);
        $this->bump();
        return $this->redirect(route($c['route'] . '.index'), "{$c['label']} deleted.");
    }

    /* ---------------- internals ---------------- */

    protected function find(int $id): array
    {
        $c = $this->cfg();
        $row = Database::get()->one("SELECT * FROM `{$c['table']}` WHERE id = ?" . ($c['soft'] ? ' AND deleted_at IS NULL' : ''), [$id]);
        if ($row === null) {
            throw new HttpException(404);
        }
        return $row;
    }

    protected function persist(Request $request, ?array $model): Response
    {
        $c = $this->cfg();
        $input = $request->post;
        $rules = ($c['rules'])($model);
        $fileFields = [];
        foreach ($c['fields'] as $f) {
            if (($f['type'] ?? '') === 'image') {
                $fileFields[] = $f['name'];
                unset($rules[$f['name']]);                       // file rules are checked below, on the real upload
            }
        }
        $errors = Rules::run($input, $rules);
        foreach ($fileFields as $name) {
            $file = $request->file($name);
            if ($file !== null && !isset($errors[$name]) && ($msg = Rules::photo($file, $name)) !== null) {
                $errors[$name] = $msg;
            }
        }
        Rules::throwIf($errors);
        $data = \App\Core\Validator::make($input, Rules::expand($input, $rules))->validated();
        // keep only top-level validated fields (wildcard rows are read from $input where needed)
        $data = array_filter($data, static fn ($k) => !str_contains((string) $k, '.'), ARRAY_FILTER_USE_KEY);

        $newFiles = [];
        foreach ($c['fields'] as $f) {
            $name = $f['name'];
            switch ($f['type'] ?? 'text') {
                case 'checkbox':
                    $data[$name] = $request->bool($name) ? 1 : 0;
                    break;
                case 'image':
                    unset($data[$name]);
                    if ($file = $request->file($name)) {
                        $data[$name] = ImageStore::store($file, $c['imageDir'], $name);   // re-encoded, random name
                        $newFiles[$name] = $model[$name] ?? null;
                    } elseif ($request->bool('remove_' . $name) && !empty($model[$name])) {
                        $newFiles[$name] = $model[$name];
                        $data[$name] = null;
                    }
                    break;
                case 'multiselect':
                    unset($data[$name]);
                    break;
                default:
                    if (array_key_exists($name, $data) && $data[$name] === '') {
                        $data[$name] = null;
                    }
                    if (is_bool($data[$name] ?? null)) {
                        $data[$name] = $data[$name] ? 1 : 0;
                    }
            }
        }

        $this->beforeSave($data, $model);
        $created = $model === null;
        $db = Database::get();
        $data['updated_at'] = now();
        if ($created) {
            $data['created_at'] = now();
            $cols = array_keys($data);
            $id = $db->insert("INSERT INTO `{$c['table']}` (" . implode(', ', array_map(static fn ($x) => "`{$x}`", $cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($data));
        } else {
            $id = (int) $model['id'];
            $db->exec("UPDATE `{$c['table']}` SET " . implode(', ', array_map(static fn ($x) => "`{$x}` = ?", array_keys($data))) . ' WHERE id = ?', [...array_values($data), $id]);
        }
        foreach ($newFiles as $old) {
            if ($old) {
                Img::delete((string) $old);                      // the replaced or removed picture
            }
        }

        foreach ($c['fields'] as $f) {
            if (($f['type'] ?? '') === 'multiselect') {
                [$pt, $own, $other] = $f['pivot'];
                $wanted = array_values(array_unique(array_map('intval', array_filter((array) ($input[$f['name']] ?? []), 'is_scalar'))));
                $current = array_map('intval', $db->column("SELECT `{$other}` FROM `{$pt}` WHERE `{$own}` = ?", [$id]));
                $drop = array_values(array_diff($current, $wanted));
                if ($drop) {
                    $db->exec("DELETE FROM `{$pt}` WHERE `{$own}` = ? AND `{$other}` IN " . Database::in($drop), [$id, ...$drop]);
                }
                foreach (array_diff($wanted, $current) as $otherId) {
                    $db->exec("INSERT INTO `{$pt}` (`{$own}`, `{$other}`) VALUES (?, ?)", [$id, $otherId]);
                }
            }
        }
        $row = $db->one("SELECT * FROM `{$c['table']}` WHERE id = ?", [$id]);
        $this->afterSave($row);
        ActivityLogger::log($c['route'] . ($created ? '.created' : '.updated'), $c['model'], $id, $created ? [] : ActivityLogger::diff($model, $row));
        $this->bump();

        return $this->redirect(route($c['route'] . '.index'), "{$c['label']} " . ($created ? 'created' : 'saved') . '.');
    }

    /** Storefront caches (the old model saved/deleted hooks). Subclasses for non-catalogue tables may override. */
    protected function bump(): void
    {
        CatalogCache::bump();
    }

    protected function beforeSave(array &$data, ?array $model): void
    {
    }

    protected function afterSave(array $row): void
    {
    }

    /** Return a message to refuse deletion (e.g. "in use"), or null to allow it. */
    protected function blockDelete(array $row): ?string
    {
        return null;
    }

    /** Unique slug from a text, ignoring one row (soft-deleted rows count: the database index includes them). */
    protected function uniqueSlug(string $table, string $text, string $fallback, int $ignoreId): string
    {
        $base = slugify($text) ?: $fallback;
        $slug = $base;
        for ($i = 2; (int) Database::get()->value("SELECT COUNT(*) FROM `{$table}` WHERE slug = ? AND id <> ?", [$slug, $ignoreId]) > 0; $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }
}
