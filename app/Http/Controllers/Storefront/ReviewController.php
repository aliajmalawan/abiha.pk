<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewRequest;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;

class ReviewController extends Controller
{
    public function store(ReviewRequest $request, Product $product): RedirectResponse
    {
        $user = $request->user();

        $purchased = OrderItem::query()->where('product_id', $product->id)
            ->whereHas('order', fn ($o) => $o->where('user_id', $user->id)->where('status', '!=', 'cancelled'))->exists();

        // One review per customer per product; goes live only after moderation.
        Review::updateOrCreate(
            ['product_id' => $product->id, 'user_id' => $user->id],
            [...$request->validated(), 'name' => $user->name, 'is_verified_purchase' => $purchased, 'is_approved' => false],
        );

        return back()->with('status', 'Thank you. Your review will appear once it has been approved.');
    }
}
