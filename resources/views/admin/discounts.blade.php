@extends('admin.layout')
@section('title', 'Discounts')

@section('content')
<h1 class="!text-2xl">Sale pricing</h1>
<p class="help mb-4">Put products on sale in bulk. The original price is kept and shown struck through. <strong>{{ $onSale }}</strong> product(s) currently on sale. For single-use codes customers type at checkout, use <a class="link" href="{{ route('admin.coupons.index') }}">Coupons</a>.</p>

<div class="grid gap-6 lg:grid-cols-2 max-w-4xl" x-data="{ scopeA: 'all', scopeB: 'all' }">
    <form method="POST" action="{{ route('admin.discounts.apply') }}" class="card space-y-4" data-confirm="Apply this sale now? Prices on the website change immediately." novalidate>
        @csrf
        <h2 class="!text-lg !mb-0">Start a sale</h2>
        <div class="field"><label class="label" for="scopeA">Which products</label>
            <select class="select" id="scopeA" name="scope" x-model="scopeA"><option value="all">Every product</option><option value="category">One category (and its sub-categories)</option><option value="featured">Featured products</option></select></div>
        <div class="field" x-show="scopeA === 'category'" x-cloak><label class="label" for="catA">Category</label>
            <select class="select" id="catA" name="category_id"><option value="">Choose…</option>@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>@error('category_id')<span class="error-text">{{ $message }}</span>@enderror</div>
        <x-field name="percent" label="Discount (%)" type="number" min="1" max="90" required help="1 to 90. Prices are rounded to whole rupees." />
        <p class="help">Products already on sale are skipped, so discounts never stack.</p>
        <button class="btn btn--gold">Apply sale</button>
    </form>

    <form method="POST" action="{{ route('admin.discounts.clear') }}" class="card space-y-4" data-confirm="End the sale and restore original prices?" novalidate>
        @csrf
        <h2 class="!text-lg !mb-0">End a sale</h2>
        <div class="field"><label class="label" for="scopeB">Which products</label>
            <select class="select" id="scopeB" name="scope" x-model="scopeB"><option value="all">Every product on sale</option><option value="category">One category</option><option value="featured">Featured products</option></select></div>
        <div class="field" x-show="scopeB === 'category'" x-cloak><label class="label" for="catB">Category</label>
            <select class="select" id="catB" name="category_id"><option value="">Choose…</option>@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
        <p class="help">Each product returns to its original price and the strike-through disappears.</p>
        <button class="btn btn--outline">End sale</button>
    </form>
</div>
@endsection
