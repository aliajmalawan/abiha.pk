@extends('admin.layout')
@section('title', 'Reviews')

@section('content')
<h1 class="!text-2xl">Reviews</h1>
<div class="flex gap-2 my-4 text-sm">
    <a href="{{ route('admin.reviews.index', ['status' => 'pending']) }}" class="badge {{ $status === 'pending' ? '' : 'badge--ivory' }} no-underline">Awaiting approval</a>
    <a href="{{ route('admin.reviews.index', ['status' => 'approved']) }}" class="badge {{ $status === 'approved' ? '' : 'badge--ivory' }} no-underline">Published</a>
</div>
<div class="space-y-3">
    @forelse($reviews as $r)
        <article class="card">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-3"><x-stars :rating="$r->rating" /> <strong>{{ $r->name }}</strong>
                    @if($r->is_verified_purchase)<span class="badge badge--success">Verified purchase</span>@endif @if($r->is_sample)<span class="sample-flag">SAMPLE</span>@endif</div>
                <span class="text-xs text-grey-text">{{ $r->created_at->format('j M Y') }} · <a class="link" href="{{ route('product', $r->product) }}" target="_blank" rel="noopener">{{ $r->product?->name }}</a></span>
            </div>
            @if($r->title)<h2 class="!text-base !mt-2 !mb-1">{{ $r->title }}</h2>@endif
            <p class="text-sm mt-1">{{ $r->body }}</p>
            <div class="flex gap-3 mt-3">
                @if(! $r->is_approved)<form method="POST" action="{{ route('admin.reviews.approve', $r) }}">@csrf @method('PATCH')<button class="btn btn--sm">Publish</button></form>
                @else<form method="POST" action="{{ route('admin.reviews.unapprove', $r) }}">@csrf @method('PATCH')<button class="btn btn--sm btn--outline">Hide</button></form>@endif
                <form method="POST" action="{{ route('admin.reviews.destroy', $r) }}" data-confirm="Delete this review permanently?">@csrf @method('DELETE')<button type="submit" class="act act--del"><x-icon name="trash-2" :size="14" /> Delete</button></form>
            </div>
        </article>
    @empty<p class="muted py-10 text-center">Nothing to show.</p>@endforelse
</div>
<div class="mt-6">{{ $reviews->links() }}</div>
@endsection
