<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sale pricing in bulk. A sale = price lowered, original price kept in compare_price (shown struck through).
 * Applying never compounds: products already on sale are skipped. Ending a sale restores the original price.
 */
class DiscountController extends Controller
{
    public function index(): View
    {
        return view('admin.discounts', [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'onSale' => Product::query()->whereColumn('compare_price', '>', 'price')->count(),
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $percent = (int) $data['percent'];

        $result = DB::transaction(function () use ($data, $percent) {
            $changed = $skipped = 0;
            foreach ($this->scope($data)->with('allVariants')->lockForUpdate()->get() as $p) {
                if ($p->compare_price !== null && $p->compare_price > $p->price) {
                    $skipped++;                                           // already on sale: never compound
                    continue;
                }
                $p->update(['compare_price' => $p->price, 'price' => $this->reduce($p->price, $percent)]);
                foreach ($p->allVariants->whereNotNull('price') as $v) {   // only variants with their own price; others follow the product
                    if ($v->compare_price === null || $v->compare_price <= $v->price) {
                        $v->update(['compare_price' => $v->price, 'price' => $this->reduce($v->price, $percent)]);
                    }
                }
                $changed++;
            }

            return [$changed, $skipped];
        });

        ActivityLogger::log('discount.applied', null, ['percent' => $percent, 'scope' => $data['scope'], 'products' => $result[0], 'skipped' => $result[1]]);

        return back()->with('status', "{$percent}% sale applied to {$result[0]} products".($result[1] ? " ({$result[1]} already on sale were left alone)" : '').'.');
    }

    public function clear(Request $request): RedirectResponse
    {
        $data = $this->validated($request, false);

        $count = DB::transaction(function () use ($data) {
            $n = 0;
            foreach ($this->scope($data)->whereColumn('compare_price', '>', 'price')->with('allVariants')->lockForUpdate()->get() as $p) {
                $p->update(['price' => $p->compare_price, 'compare_price' => null]);
                foreach ($p->allVariants->whereNotNull('price')->whereNotNull('compare_price') as $v) {
                    if ($v->compare_price > $v->price) {
                        $v->update(['price' => $v->compare_price, 'compare_price' => null]);
                    }
                }
                $n++;
            }

            return $n;
        });

        ActivityLogger::log('discount.cleared', null, ['scope' => $data['scope'], 'products' => $count]);

        return back()->with('status', "Sale ended on {$count} products. Original prices restored.");
    }

    private function validated(Request $request, bool $needsPercent = true): array
    {
        return $request->validate([
            'scope' => ['required', 'in:all,category,featured'],
            'category_id' => ['required_if:scope,category', 'nullable', 'integer', 'exists:categories,id'],
            'percent' => [$needsPercent ? 'required' : 'nullable', 'integer', 'between:1,90'],
        ]);
    }

    private function scope(array $data): Builder
    {
        return Product::query()
            ->when($data['scope'] === 'category', fn ($q) => $q->whereIn('category_id', Category::findOrFail($data['category_id'])->descendantIds()))
            ->when($data['scope'] === 'featured', fn ($q) => $q->where('is_featured', true));
    }

    /** Discounted whole-PKR price, never below 1. */
    private function reduce(int $price, int $percent): int
    {
        return max(1, (int) round($price * (100 - $percent) / 100));
    }
}
