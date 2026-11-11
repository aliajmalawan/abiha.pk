@extends('admin.layout')
@section('title', 'Featured products')

@section('content')
<h1 class="!text-2xl">Featured products</h1>
<p class="help mb-4">Tick which products appear in each homepage row (Best sellers, New arrivals…). Manage the rows themselves under <a class="link" href="{{ route('admin.home.index') }}">Homepage</a>.</p>
<form method="GET" class="mb-4 flex gap-2 max-w-md" role="search"><input name="q" value="{{ $q }}" class="input !min-h-[40px]" placeholder="Search name or SKU" aria-label="Search"><button class="btn btn--sm">Search</button></form>

<form method="POST" action="{{ route('admin.featured.update') }}">
    @csrf @method('PUT')
    <div class="card !p-0 overflow-x-auto"><table class="table">
        <thead><tr><th>Product</th>@foreach($flags as $label)<th class="text-center">{{ $label }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse($products as $p)
            <tr><td><input type="hidden" name="ids[]" value="{{ $p->id }}"><div class="flex items-center gap-3"><span class="size-10 rounded bg-ivory border border-line overflow-hidden shrink-0">@if($p->image_url)<img src="{{ $p->image_url }}" alt="" class="size-full object-cover">@endif</span>
                <a class="link" href="{{ route('admin.products.edit', $p) }}">{{ $p->name }}</a>@unless($p->is_active)<span class="badge badge--ivory">Hidden</span>@endunless</div></td>
                @foreach($flags as $flag => $label)<td class="text-center"><label class="check justify-center"><input type="checkbox" name="flags[{{ $p->id }}][{{ $flag }}]" value="1" @checked($p->{$flag}) aria-label="{{ $label }}: {{ $p->name }}"></label></td>@endforeach</tr>
        @empty<tr><td colspan="5" class="text-center py-10 muted">No products found.</td></tr>@endforelse
        </tbody></table></div>
    <div class="mt-4 flex items-center gap-4"><button class="btn">Save changes on this page</button><span class="help">Saves only the products shown on this page.</span></div>
</form>
<div class="mt-6">{{ $products->links() }}</div>
@endsection
