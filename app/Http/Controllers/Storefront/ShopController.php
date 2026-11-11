<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    private const SORTS = [
        'featured' => 'Featured',
        'newest' => 'Latest',
        'popular' => 'Popularity',
        'price_asc' => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'name' => 'Name A–Z',
    ];

    public function index(Request $request): View|RedirectResponse
    {
        $slug = $request->query('category');
        $category = is_string($slug) && $slug !== '' ? Category::active()->where('slug', $slug)->first() : null;   // ?category[]=x is not a slug

        // /shop?category=x is the same page as /category/x: send people (and search engines) to the one clean URL.
        if ($category) {
            return redirect()->route('category', ['category' => $category] + $request->except('category'), 301);
        }

        return $this->render($request, $category, null);
    }

    public function category(Request $request, Category $category): View
    {
        abort_unless($category->is_active, 404);

        return $this->render($request, $category, null);
    }

    public function collection(Request $request, Collection $collection): View
    {
        abort_unless($collection->is_active, 404);

        return $this->render($request, null, $collection);
    }

    private function render(Request $request, ?Category $category, ?Collection $collection): View
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'min' => ['nullable', 'integer', 'min:0'],
            'max' => ['nullable', 'integer', 'min:0'],
            'availability' => ['nullable', 'in:in_stock'],
            'color' => ['nullable', 'array'], 'color.*' => ['string', 'max:40'],
            'size' => ['nullable', 'array'], 'size.*' => ['string', 'max:40'],
            'sale' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:'.implode(',', array_keys(self::SORTS))],
        ]);

        $query = Product::query()->active()->forCard()->search($input['q'] ?? null);

        if ($category) {
            $query->whereIn('category_id', $category->descendantIds());
        }
        if ($collection) {
            $query->whereHas('collections', fn ($c) => $c->whereKey($collection->id));
        }

        // Price: matches the product price or any variant price inside the range.
        $min = $input['min'] ?? null;
        $max = $input['max'] ?? null;
        if ($min !== null || $max !== null) {
            $inRange = fn (Builder $b, string $col) => $b->when($min !== null, fn ($x) => $x->where($col, '>=', $min))->when($max !== null, fn ($x) => $x->where($col, '<=', $max));
            $query->where(function (Builder $w) use ($inRange) {
                $w->where(fn ($p) => $inRange($p, 'price'))   // base price (variants inherit it unless overridden)
                    ->orWhereHas('variants', fn ($v) => $v->whereNotNull('price')->where(fn ($x) => $inRange($x, 'price')));
            });
        }

        if (($input['availability'] ?? null) === 'in_stock') {
            $query->where(fn (Builder $w) => $w->where(fn ($p) => $p->where('has_variants', false)->where('stock', '>', 0))
                ->orWhere(fn ($p) => $p->where('has_variants', true)->whereHas('variants', fn ($v) => $v->where('stock', '>', 0))));
        }
        // Variant options live in attribute_values; filter by (attribute slug, value).
        $hasOption = fn (string $slug, array $values) => fn ($v) => $v->where('is_active', true)->whereHas('attributeValues', fn ($a) => $a
            ->whereIn('value', $values)->whereHas('attribute', fn ($x) => $x->where('slug', $slug)));
        if (! empty($input['color'])) {
            $query->whereHas('variants', $hasOption('color', $input['color']));
        }
        if (! empty($input['size'])) {
            $query->whereHas('variants', $hasOption('size', $input['size']));
        }
        if (! empty($input['sale'])) {
            $query->where(fn (Builder $w) => $w->whereColumn('compare_price', '>', 'price')
                ->orWhereHas('variants', fn ($v) => $v->whereColumn('compare_price', '>', 'price')));
        }

        match ($input['sort'] ?? 'featured') {
            'newest' => $query->latest('id'),
            'popular' => $query->orderByDesc('sold_count'),
            'price_asc' => $query->orderBy('price')->orderBy('id'),
            'price_desc' => $query->orderByDesc('price')->orderBy('id'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('is_featured')->latest('id'),
        };

        $products = $query->paginate(config('abiha.pagination.products'))->withQueryString();

        // Filter options = attribute values actually used by an active variant, in the order the admin set.
        $optionsFor = fn (string $slug) => \App\Support\CatalogCache::remember('filter.options.'.$slug, 600, fn () => AttributeValue::query()
            ->whereHas('attribute', fn ($a) => $a->where('slug', $slug))
            ->whereHas('variants', fn ($v) => $v->where('is_active', true))
            ->orderBy('sort_order')->orderBy('id')->get());
        $title = $collection?->name ?? $category?->name ?? (($input['q'] ?? null) ? 'Search results' : 'Shop');

        return view('storefront.shop', [
            'products' => $products,
            'category' => $category,
            'collection' => $collection,
            'title' => $title,
            'q' => $input['q'] ?? null,
            'sorts' => self::SORTS,
            'filters' => $input,
            'categories' => \App\Support\CatalogCache::remember('nav.categories', 600, fn () => Category::active()->roots()->with('children')->orderBy('sort_order')->get()),
            'colors' => $optionsFor('color'),
            'sizes' => $optionsFor('size')->pluck('value'),
            'priceCeiling' => (int) \App\Support\CatalogCache::remember('price.ceiling', 600, fn () => Product::active()->max('price')),
            'activeCount' => collect(['min', 'max', 'availability', 'color', 'size', 'sale'])->filter(fn ($k) => ! empty($input[$k]) || ($input[$k] ?? null) === '0')->count()
                + ($category && $request->filled('category') ? 1 : 0),
        ]);
    }
}
