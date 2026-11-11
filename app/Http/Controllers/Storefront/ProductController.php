<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    private const RECENT_KEY = 'recently_viewed';

    public function show(Request $request, Product $product): View
    {
        abort_unless($product->is_active, 404);
        $product->load(['images', 'variants', 'category']);

        $related = Product::query()->active()->forCard()->whereKeyNot($product->id)
            ->when($product->category_id, fn ($q) => $q->where('category_id', $product->category_id))
            ->inRandomOrder()->limit(4)->get();

        // Recently viewed (session, most recent first, excluding the current product)
        $ids = collect($request->session()->get(self::RECENT_KEY, []))->reject(fn ($id) => $id === $product->id)->prepend($product->id)->take(9)->all();
        $request->session()->put(self::RECENT_KEY, $ids);
        $recent = Product::query()->active()->forCard()->whereIn('id', array_slice($ids, 1))->get()
            ->sortBy(fn ($p) => array_search($p->id, $ids))->values();

        return view('storefront.product', [
            'product' => $product,
            'related' => $related,
            'recent' => $recent,
            'reviews' => $product->reviews()->paginate(10, ['*'], 'reviews_page')->fragment('reviews'),   // a page of reviews, not all of them
            'ratingCounts' => $product->reviewStats()['stars'],
            'canReview' => $request->user() && ! $product->reviews()->reorder()->where('user_id', $request->user()->id)->exists(),
            'deliveryInfo' => Setting::get('delivery.info'),
            'returnPolicy' => Setting::get('returns.policy'),
        ]);
    }

    public function quickView(Product $product): View
    {
        abort_unless($product->is_active, 404);
        $product->load(['images', 'variants', 'category']);

        return view('storefront.partials.quick-view', ['product' => $product]);
    }

    /** Live search suggestions for the header search overlay. */
    public function suggest(Request $request): JsonResponse
    {
        $q = trim(is_string($request->query("q")) ? $request->query("q") : "");
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $results = Product::query()->active()->forCard()->search($q)->limit(6)->get()->map(fn (Product $p) => [
            'name' => $p->name,
            'url' => route('product', $p),
            'image' => $p->image_url,
            'price' => config('abiha.currency.symbol').' '.number_format($p->display_price),
            'category' => $p->category?->name,
        ]);

        return response()->json(['results' => $results]);
    }
}
