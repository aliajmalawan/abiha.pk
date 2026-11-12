<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Cart;
use App\Services\Catalog;
use App\Services\Wishlist;

/** The wishlist page, heart toggle and "move to bag" (port of the old Storefront\WishlistController). */
final class WishlistController extends Controller
{
    public function index(): Response
    {
        return $this->view('store/wishlist', ['products' => Wishlist::products()], ['title' => 'Wishlist', 'noindex' => true]);
    }

    public function toggle(Request $request): Response
    {
        $product = Catalog::product((string) ($request->params['slug'] ?? ''), false);
        if (!$product || !$product['is_active']) {
            throw new HttpException(404);   // a hidden product cannot be saved (nor discovered by trying its slug)
        }
        $added = Wishlist::toggle((int) $product['id']);
        $message = $added ? 'Saved to your wishlist.' : 'Removed from your wishlist.';
        if ($request->wantsJson() || $request->ajax()) {
            return $this->json(['ok' => true, 'added' => $added, 'count' => count(Wishlist::ids()), 'message' => $message]);
        }
        return $this->back(route('wishlist'), $message);
    }

    /** Move to bag. Products with variants must be configured first, so those go to the product page. */
    public function moveToCart(Request $request): Response
    {
        $product = Catalog::product((string) ($request->params['slug'] ?? ''), false);
        if (!$product) {
            throw new HttpException(404);
        }
        if ($product['has_variants']) {
            return $this->redirect(route('product', $product), 'Choose your size and colour to add this item.');
        }
        try {
            if (!method_exists(Cart::class, 'add')) {
                throw new \App\Services\Cart\CartException('Sorry, the bag is not available right now.');
            }
            Cart::add($product, null, 1);       // slice C: same arguments as the old CartService::add(Product, ?variantId, qty)
        } catch (\App\Services\Cart\CartException $e) {
            Session::flash('errors', ['wishlist' => $e->getMessage()]);
            return $this->back(route('wishlist'));
        }
        Wishlist::remove((int) $product['id']);
        return $this->back(route('wishlist'), 'Moved to your bag.');
    }
}
