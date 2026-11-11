@extends('admin.layout')
@section('title', 'Products')

@section('content')
@php $cur = config('abiha.currency.symbol'); @endphp
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <h1 class="!text-2xl !mb-0">Products</h1>
    <a href="{{ route('admin.products.create') }}" class="btn btn--sm">Add product</a>
</div>

<form method="GET" class="mb-4 flex flex-wrap gap-2" role="search">
    <input name="q" value="{{ $filters['q'] ?? '' }}" class="input !min-h-[40px] max-w-xs" placeholder="Search name or SKU" aria-label="Search">
    <select name="category" class="select !min-h-[40px] !w-auto" aria-label="Category"><option value="">All categories</option>
        @foreach($categories as $c)<option value="{{ $c->id }}" @selected(($filters['category'] ?? null) == $c->id)>{{ $c->name }}</option>@endforeach</select>
    <select name="status" class="select !min-h-[40px] !w-auto" aria-label="Status"><option value="">Any status</option>
        @foreach(['active' => 'Active', 'inactive' => 'Hidden', 'low' => 'Low stock', 'out' => 'Out of stock'] as $k => $l)<option value="{{ $k }}" @selected(($filters['status'] ?? null) === $k)>{{ $l }}</option>@endforeach</select>
    <button class="btn btn--sm">Filter</button>
</form>

<div class="card !p-0 overflow-x-auto">
    <table class="table">
        <thead><tr><th></th><th>Product</th><th>SKU</th><th>Category</th><th class="num">Price</th><th class="num">Stock</th><th>Status</th><th class="num">Actions</th></tr></thead>
        <tbody>
        @forelse($products as $p)
            @php $stock = $p->has_variants ? $p->allVariants->sum('stock') : $p->stock; @endphp
            <tr>
                <td class="w-14"><div class="size-12 rounded bg-ivory border border-line overflow-hidden">@if($p->image_url)<img src="{{ $p->image_url }}" alt="" class="size-full object-cover">@endif</div></td>
                <td><a class="link" href="{{ route('admin.products.edit', $p) }}">{{ $p->name }}</a> @if($p->is_sample)<span class="sample-flag">SAMPLE</span>@endif
                    @if($p->has_variants)<br><span class="text-xs text-grey-text">{{ $p->allVariants->count() }} variants</span>@endif</td>
                <td class="text-grey-text">{{ $p->sku }}</td>
                <td>{{ $p->category?->name ?? '-' }}</td>
                <td class="num">{{ $cur }} {{ number_format($p->price) }}</td>
                <td class="num"><span class="{{ $stock <= 0 ? 'text-danger font-semibold' : (! $p->has_variants && $stock <= $p->low_stock_threshold ? 'text-warning font-semibold' : '') }}">{{ $stock }}</span></td>
                <td><span class="badge {{ $p->is_active ? 'badge--success' : 'badge--ivory' }}">{{ $p->is_active ? 'Active' : 'Hidden' }}</span></td>
                <td class="num whitespace-nowrap"><span class="act-group"><a class="act act--edit" href="{{ route('admin.products.edit', $p) }}"><x-icon name="pencil" :size="14" /> Edit</a>
                    <form method="POST" action="{{ route('admin.products.destroy', $p) }}" class="inline" data-confirm="Delete this product? Existing orders keep their details.">@csrf @method('DELETE')<button type="submit" class="act act--del"><x-icon name="trash-2" :size="14" /> Delete</button></form></span></td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center py-10 muted">No products found.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-6">{{ $products->links() }}</div>
@endsection
