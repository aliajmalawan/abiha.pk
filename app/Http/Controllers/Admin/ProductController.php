<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products)
    {
    }

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive,low,out'],
        ]);

        $query = Product::query()->with(['category:id,name', 'images', 'allVariants'])
            ->search($filters['q'] ?? null)
            ->when($filters['category'] ?? null, fn ($q, $id) => $q->whereIn('category_id', Category::find($id)?->descendantIds() ?? [$id]))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when(($filters['status'] ?? null) === 'out', fn ($q) => $q->outOfStock())
            ->when(($filters['status'] ?? null) === 'low', fn ($q) => $q->lowStock())
            ->latest('id');

        return view('admin.products.index', [
            'products' => $query->paginate(config('abiha.pagination.admin'))->withQueryString(),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('admin.products.form', $this->formData(new Product(['is_active' => true, 'stock' => 0, 'low_stock_threshold' => 3])));
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = $this->products->save(null, $request->validated(), $request->validated('variants', []), $request->file('images', []), [], $request->user());

        return redirect()->route('admin.products.edit', $product)->with('status', 'Product created.');
    }

    public function edit(Product $product): View
    {
        return view('admin.products.form', $this->formData($product->load(['images', 'allVariants'])));
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->products->save($product, $request->validated(), $request->validated('variants', []), $request->file('images', []), $request->validated('remove_images', []), $request->user(), $request->validated('image_order', []));

        return back()->with('status', 'Product saved.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->products->delete($product);

        return redirect()->route('admin.products.index')->with('status', 'Product deleted. Existing orders keep their details.');
    }

    private function formData(Product $product): array
    {
        $attributes = Attribute::query()->with('values')->orderBy('sort_order')->get();

        return [
            'product' => $product,
            'categories' => Category::orderBy('name')->get(['id', 'name', 'parent_id']),
            'attributes' => $attributes,
            // Variant editor seed (also used to repopulate after a validation error)
            'variantRows' => old('variants', $product->exists ? $product->allVariants->map(fn ($v) => [
                'id' => $v->id, 'sku' => $v->sku, 'price' => $v->price, 'compare_price' => $v->compare_price, 'stock' => $v->stock,
                'is_active' => $v->is_active, 'options' => $v->attributeValues->pluck('id')->all(),
            ])->all() : []),
        ];
    }
}
