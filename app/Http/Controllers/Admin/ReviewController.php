<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate(['status' => ['nullable', 'in:pending,approved'], 'q' => ['nullable', 'string', 'max:100']]);

        $reviews = Review::query()->with('product:id,name,slug')
            ->when(($f['status'] ?? 'pending') === 'pending', fn ($q) => $q->where('is_approved', false), fn ($q) => $q->where('is_approved', true))
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('body', 'like', '%'.addcslashes($t, '%_\\').'%')->orWhere('name', 'like', '%'.addcslashes($t, '%_\\').'%')))
            ->latest('id')->paginate(config('abiha.pagination.admin'))->withQueryString();

        return view('admin.reviews.index', ['reviews' => $reviews, 'status' => $f['status'] ?? 'pending', 'q' => $f['q'] ?? null]);
    }

    public function approve(Review $review): RedirectResponse
    {
        $review->update(['is_approved' => true, 'is_sample' => false]);
        ActivityLogger::log('review.approved', $review);

        return back()->with('status', 'Review published.');
    }

    public function unapprove(Review $review): RedirectResponse
    {
        $review->update(['is_approved' => false]);
        ActivityLogger::log('review.unpublished', $review);

        return back()->with('status', 'Review hidden.');
    }

    public function destroy(Review $review): RedirectResponse
    {
        ActivityLogger::log('review.deleted', $review, ['product_id' => $review->product_id]);
        $review->delete();

        return back()->with('status', 'Review deleted.');
    }
}
