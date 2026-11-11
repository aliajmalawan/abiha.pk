<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Generic, permission-guarded CRUD for simple entities. Subclasses only declare config():
 *   model, label, plural, route (route-name prefix), search[], order[col,dir], with[], columns[], fields[], rules(?Model)
 * Field types: text, textarea, number, select, checkbox, datetime, image, multiselect.
 * Access is enforced by `can:<permission>` middleware in routes/admin.php.
 * Hooks: beforeSave(), afterSave(), blockDelete().
 */
abstract class ResourceController extends Controller
{
    abstract protected function config(): array;

    /** @return array<string,mixed> */
    protected function cfg(): array
    {
        return $this->config() + ['search' => [], 'order' => ['id', 'desc'], 'with' => [], 'withCount' => [], 'columns' => [], 'fields' => [], 'imageDir' => 'uploads'];
    }

    public function index(Request $request): View
    {
        $c = $this->cfg();
        $query = $c['model']::query()->with($c['with'])->withCount($c['withCount']);

        $term = trim(is_string($request->query("q")) ? $request->query("q") : "");
        if ($term !== '' && $c['search']) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($w) use ($c, $like) {
                foreach ($c['search'] as $i => $col) {
                    $i === 0 ? $w->where($col, 'like', $like) : $w->orWhere($col, 'like', $like);
                }
            });
        }
        $query->orderBy($c['order'][0], $c['order'][1]);

        return view('admin.resource.index', ['c' => $c, 'rows' => $query->paginate(config('abiha.pagination.admin'))->withQueryString(), 'term' => $term]);
    }

    public function create(): View
    {
        $c = $this->cfg();

        return view('admin.resource.form', ['c' => $c, 'model' => new $c['model']($c['defaults'] ?? []), 'selected' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $c = $this->cfg();

        return $this->persist($request, new $c['model']);
    }

    public function edit(int $id): View
    {
        $c = $this->cfg();
        $model = $c['model']::query()->findOrFail($id);
        $selected = [];
        foreach ($c['fields'] as $f) {
            if (($f['type'] ?? '') === 'multiselect') {
                $selected[$f['name']] = $model->{$f['relation']}()->pluck($model->{$f['relation']}()->getRelated()->getQualifiedKeyName())->all();
            }
        }

        return view('admin.resource.form', ['c' => $c, 'model' => $model, 'selected' => $selected]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $c = $this->cfg();

        return $this->persist($request, $c['model']::query()->findOrFail($id));
    }

    public function destroy(int $id): RedirectResponse
    {
        $c = $this->cfg();
        $model = $c['model']::query()->findOrFail($id);

        if ($reason = $this->blockDelete($model)) {
            return back()->withErrors(['delete' => $reason]);
        }

        foreach ($c['fields'] as $f) {
            if (($f['type'] ?? '') === 'image' && $model->{$f['name']}) {
                \App\Support\Img::delete($model->{$f['name']});
            }
        }
        $model->delete();
        ActivityLogger::log($c['route'].'.deleted', $model, ['id' => $id]);

        return redirect()->route($c['route'].'.index')->with('status', "{$c['label']} deleted.");
    }

    /* ---------------- internals ---------------- */

    protected function persist(Request $request, Model $model): RedirectResponse
    {
        $c = $this->cfg();
        $data = $request->validate($c['rules']($model->exists ? $model : null));

        foreach ($c['fields'] as $f) {
            $name = $f['name'];
            switch ($f['type'] ?? 'text') {
                case 'checkbox':
                    $data[$name] = $request->boolean($name);
                    break;
                case 'image':
                    unset($data[$name]);
                    if ($request->hasFile($name)) {
                        if ($model->{$name}) {
                            \App\Support\Img::delete($model->{$name});
                        }
                        $data[$name] = app(\App\Services\ImageStore::class)->store($request->file($name), $c['imageDir'], $name);   // re-encoded, random name
                    } elseif ($request->boolean('remove_'.$name) && $model->{$name}) {
                        \App\Support\Img::delete($model->{$name});
                        $data[$name] = null;
                    }
                    break;
                case 'multiselect':
                    unset($data[$name]);
                    break;
                default:
                    if (array_key_exists($name, $data) && $data[$name] === '') {
                        $data[$name] = null;       // empty optional inputs become NULL, not ''
                    }
            }
        }

        $this->beforeSave($data, $model);
        $created = ! $model->exists;
        $model->fill($data)->save();

        foreach ($c['fields'] as $f) {
            if (($f['type'] ?? '') === 'multiselect') {
                $model->{$f['relation']}()->sync($request->input($f['name'], []));
            }
        }
        $this->afterSave($model);

        ActivityLogger::log($c['route'].($created ? '.created' : '.updated'), $model, $created ? [] : ActivityLogger::diff($model));

        return redirect()->route($c['route'].'.index')->with('status', "{$c['label']} ".($created ? 'created' : 'saved').'.');
    }

    protected function beforeSave(array &$data, Model $model): void
    {
    }

    protected function afterSave(Model $model): void
    {
    }

    /** Return a message to refuse deletion (e.g. "in use"), or null to allow it. */
    protected function blockDelete(Model $model): ?string
    {
        return null;
    }
}
