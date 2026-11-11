<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Cart\CartException;
use App\Services\Cart\CartService;
use App\Services\WishlistService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function __construct(private readonly WishlistService $wishlist)
    {
    }

    public function index(): View
    {
        return view('storefront.wishlist', ['products' => $this->wishlist->products()]);
    }

    public function toggle(Request $request, Product $product): JsonResponse|RedirectResponse
    {
        abort_unless($product->is_active, 404);   // a hidden product cannot be saved (nor discovered by trying its slug)
        $added = $this->wishlist->toggle($product);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'added' => $added, 'count' => count($this->wishlist->ids()),
                'message' => $added ? 'Saved to your wishlist.' : 'Removed from your wishlist.']);
        }

        return back()->with('status', $added ? 'Saved to your wishlist.' : 'Removed from your wishlist.');
    }

    /** Move to bag. Products with variants must be configured first, so those go to the product page. */
    public function moveToCart(Request $request, Product $product, CartService $cart): RedirectResponse
    {
        if ($product->has_variants) {
            return redirect()->route('product', $product)->with('status', 'Choose your size and colour to add this item.');
        }

        try {
            $cart->add($product, null, 1);
        } catch (CartException $e) {
            return back()->withErrors(['wishlist' => $e->getMessage()]);
        }
        $this->wishlist->remove($product);

        return back()->with('status', 'Moved to your bag.');
    }
}
