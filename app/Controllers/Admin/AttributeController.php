<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;
use App\Support\CatalogCache;

/** Variant vocabulary: attributes (Size, Colour…) and their allowed values. */
final class AttributeController extends AdminController
{
    public function index(Request $request): Response
    {
        $db = Database::get();
        $attributes = $db->all('SELECT * FROM attributes ORDER BY sort_order, id');
        $values = $db->all('SELECT * FROM attribute_values ORDER BY sort_order, id');
        foreach ($attributes as &$a) {
            $a['values'] = array_values(array_filter($values, static fn ($v) => (int) $v['attribute_id'] === (int) $a['id']));
        }
        unset($a);
        return $this->adminView('admin/attributes/index', ['attributes' => $attributes], 'Attributes');
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request->post, [
            'name' => 'required|string|max:60',
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/', 'unique:attributes,slug'],
            'type' => 'required|in:select,color',
        ]);
        $db = Database::get();
        $id = $db->insert('INSERT INTO attributes (name, slug, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [
            $data['name'], $data['slug'], $data['type'], (int) $db->value('SELECT COALESCE(MAX(sort_order), 0) FROM attributes') + 1, now(), now(),
        ]);
        ActivityLogger::log('attribute.created', 'Attribute', $id);
        CatalogCache::bump();
        return $this->back(route('admin.attributes.index'), 'Attribute added.');
    }

    public function storeValue(Request $request): Response
    {
        $attribute = $this->attribute((int) $request->params['id']);
        $data = $this->validate($request->post, [
            'value' => 'required|string|max:60',
            'color_hex' => [$attribute['type'] === 'color' ? 'required' : 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
        $db = Database::get();
        if ((int) $db->value('SELECT COUNT(*) FROM attribute_values WHERE attribute_id = ? AND value = ?', [(int) $attribute['id'], $data['value']]) > 0) {
            return $this->backWithErrors(['value' => 'The value has already been taken.'], route('admin.attributes.index'), true, $request->post);
        }
        $db->insert('INSERT INTO attribute_values (attribute_id, value, color_hex, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [
            (int) $attribute['id'], $data['value'], $data['color_hex'] ?? null,
            (int) $db->value('SELECT COALESCE(MAX(sort_order), 0) FROM attribute_values WHERE attribute_id = ?', [(int) $attribute['id']]) + 1, now(), now(),
        ]);
        ActivityLogger::log('attribute.value_added', 'Attribute', (int) $attribute['id'], ['value' => $data['value']]);
        CatalogCache::bump();
        return $this->back(route('admin.attributes.index'), 'Value added.');
    }

    public function destroyValue(Request $request): Response
    {
        $db = Database::get();
        $value = $db->one('SELECT * FROM attribute_values WHERE id = ?', [(int) $request->params['id']]);
        if ($value === null) {
            throw new HttpException(404);
        }
        if ((int) $db->value('SELECT COUNT(*) FROM attribute_value_product_variant WHERE attribute_value_id = ?', [(int) $value['id']]) > 0) {
            return $this->backWithErrors(['value' => "\"{$value['value']}\" is used by product variants. Remove it from those variants first."], route('admin.attributes.index'));
        }
        $db->exec('DELETE FROM attribute_values WHERE id = ?', [(int) $value['id']]);
        CatalogCache::bump();
        return $this->back(route('admin.attributes.index'), 'Value removed.');
    }

    public function destroy(Request $request): Response
    {
        $attribute = $this->attribute((int) $request->params['id']);
        if (in_array($attribute['slug'], ['size', 'color'], true)) {
            return $this->backWithErrors(['value' => 'Size and Colour power the shop filters and cannot be deleted.'], route('admin.attributes.index'));
        }
        try {
            Database::get()->exec('DELETE FROM attributes WHERE id = ?', [(int) $attribute['id']]);
        } catch (\mysqli_sql_exception) {
            return $this->backWithErrors(['value' => 'This attribute has values in use by variants.'], route('admin.attributes.index'));
        }
        CatalogCache::bump();
        return $this->back(route('admin.attributes.index'), 'Attribute deleted.');
    }

    private function attribute(int $id): array
    {
        $a = Database::get()->one('SELECT * FROM attributes WHERE id = ?', [$id]);
        if ($a === null) {
            throw new HttpException(404);
        }
        return $a;
    }
}
