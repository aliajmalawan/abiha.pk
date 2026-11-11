<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Bulk toggle of the merchandising flags that feed homepage rows. */
class FeaturedController extends Controller
{
    public const FLAGS = ['is_featured' => 'Featured', 'is_new' => 'New arrival', 'is_bestseller' => 'Best seller', 'is_trending' => 'Trending'];

    public function index(Request $request): View
    {
        $q = trim(is_string($request->query("q")) ? $request->query("q") : "");

        return view('admin.featured', [
            'products' => Product::query()->with('images')->search($q)->orderByDesc('is_featured')->orderBy('name')->paginate(30)->withQueryString(),
            'flags' => self::FLAGS, 'q' => $q,
        ]);
    }

    /** Saves the flags for the products on the submitted page only (ids[] lists which rows were shown). */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:100'], 'ids.*' => ['integer', 'exists:products,id'], 'flags' => ['nullable', 'array']]);

        foreach ($data['ids'] as $id) {
            $values = [];
            foreach (array_keys(self::FLAGS) as $flag) {
                $values[$flag] = ! empty($data['flags'][$id][$flag]);
            }
            Product::query()->whereKey($id)->update($values);
        }
        \App\Support\CatalogCache::bump();     // bulk updates fire no model events: without this the homepage rows stay stale for minutes
        ActivityLogger::log('products.flags_updated', null, ['count' => count($data['ids'])]);

        return back()->with('status', 'Merchandising flags saved.');
    }
}
