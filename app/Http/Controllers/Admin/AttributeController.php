<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Variant vocabulary: attributes (Size, Colour…) and their allowed values. */
class AttributeController extends Controller
{
    public function index(): View
    {
        return view('admin.attributes.index', ['attributes' => Attribute::query()->with('values')->orderBy('sort_order')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/', 'unique:attributes,slug'],
            'type' => ['required', 'in:select,color'],
        ]);
        $attr = Attribute::create($data + ['sort_order' => (int) Attribute::max('sort_order') + 1]);
        ActivityLogger::log('attribute.created', $attr);

        return back()->with('status', 'Attribute added.');
    }

    public function storeValue(Request $request, Attribute $attribute): RedirectResponse
    {
        $data = $request->validate([
            'value' => ['required', 'string', 'max:60', Rule::unique('attribute_values', 'value')->where('attribute_id', $attribute->id)],
            'color_hex' => [$attribute->type === 'color' ? 'required' : 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
        $attribute->values()->create($data + ['sort_order' => (int) $attribute->values()->max('sort_order') + 1]);
        ActivityLogger::log('attribute.value_added', $attribute, ['value' => $data['value']]);

        return back()->with('status', 'Value added.');
    }

    public function destroyValue(AttributeValue $value): RedirectResponse
    {
        if ($value->variants()->exists()) {
            return back()->withErrors(['value' => "\"{$value->value}\" is used by product variants. Remove it from those variants first."]);
        }
        $value->delete();

        return back()->with('status', 'Value removed.');
    }

    public function destroy(Attribute $attribute): RedirectResponse
    {
        if (in_array($attribute->slug, ['size', 'color'], true)) {
            return back()->withErrors(['value' => 'Size and Colour power the shop filters and cannot be deleted.']);
        }
        try {
            $attribute->delete();
        } catch (QueryException) {
            return back()->withErrors(['value' => 'This attribute has values in use by variants.']);
        }

        return back()->with('status', 'Attribute deleted.');
    }
}
