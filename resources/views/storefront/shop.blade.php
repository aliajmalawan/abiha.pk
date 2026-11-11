@extends('layouts.app')

@php
    // Filtered / sorted / searched views are noindex,follow with the clean listing as canonical (App\Support\Seo decides);
    // plain paginated pages stay indexable so every product can be discovered.
    $crumbs = [['Home', route('home')]];
    if ($collection) { $crumbs[] = ['Shop', route('shop')]; $crumbs[] = [$collection->name, '']; }
    elseif ($category) { $crumbs = array_merge($crumbs, \App\Support\SchemaBuilder::categoryTrail($category)); $crumbs[array_key_last($crumbs)][1] = ''; }
    else { $crumbs[] = [$title, '']; }
    $seo->breadcrumbs($crumbs);
    if ($category?->image) { $seo->image($category->image); } elseif ($collection?->image) { $seo->image($collection->image); }
    if ($products->count()) {
        $seo->schema(['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $title, 'url' => $seo->canonicalUrl(request()),
            'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $products->values()->map(fn ($p, $i) => ['@type' => 'ListItem', 'position' => ($products->currentPage() - 1) * $products->perPage() + $i + 1, 'url' => route('product', $p)])->all()]]);
    }
@endphp
@section('title', $title.'')
@section('description', $category?->meta_description ?: ($category?->description ?: $collection?->description ?: 'Browse the '.config('abiha.name').'® collection.'))

@section('content')
<div class="container py-8 lg:py-12" x-data="{ filtersOpen: false }" @keydown.escape.window="filtersOpen = false">
    <x-breadcrumbs class="mb-4" />

    <header class="mb-8">
        <h1 class="!text-3xl lg:!text-4xl !mb-2">{{ $title }}</h1>
        @if($q)<p class="muted">Results for “{{ $q }}”</p>
        @elseif($category?->description || $collection?->description)<p class="lead">{{ $category?->description ?? $collection?->description }}</p>@endif
    </header>

    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-y border-line py-3 mb-8">
        <button type="button" class="btn btn--outline btn--sm lg:hidden" @click="filtersOpen = true"><x-icon name="sliders-horizontal" :size="16" /> Filters @if($activeCount)({{ $activeCount }})@endif</button>
        <p class="text-sm muted" role="status">{{ $products->total() }} {{ Str::plural('product', $products->total()) }}</p>
        <div class="flex items-center gap-2">
            <label for="sort" class="text-sm hidden sm:block">Sort by</label>
            <select id="sort" name="sort" form="filters" class="select !min-h-[40px] !w-auto max-w-[10.5rem] text-sm" @change="$el.form.requestSubmit()">
                @foreach($sorts as $key => $label)<option value="{{ $key }}" @selected(($filters['sort'] ?? 'featured') === $key)>{{ $label }}</option>@endforeach
            </select>
        </div>
    </div>

    <div class="grid gap-10 lg:grid-cols-[260px_1fr]">
        {{-- Filters: sidebar on desktop, slide-over drawer on mobile (single form) --}}
        <div class="lg:block" :class="filtersOpen ? 'fixed inset-0 z-[220]' : 'hidden'">
            <div class="absolute inset-0 bg-ink/50 lg:hidden" @click="filtersOpen = false"></div>
            <form id="filters" method="GET" action="{{ route('shop') }}" aria-label="Product filters"
                  class="max-lg:absolute max-lg:inset-y-0 max-lg:left-0 max-lg:w-[88%] max-lg:max-w-sm max-lg:bg-white max-lg:overflow-y-auto max-lg:p-6 space-y-7">
                <div class="flex items-center justify-between lg:hidden">
                    <h2 class="!text-lg !mb-0">Filters</h2>
                    <button type="button" class="btn btn--ghost !px-2" @click="filtersOpen = false" aria-label="Close filters"><x-icon name="x" :size="22" /></button>
                </div>
                @if($q)<input type="hidden" name="q" value="{{ $q }}">@endif

                <fieldset>
                    <legend class="eyebrow mb-3">Category</legend>
                    <div class="space-y-2">
                        <label class="check"><input type="radio" name="category" value="" @checked(! $category)> All</label>
                        @foreach($categories as $c)
                            <label class="check"><input type="radio" name="category" value="{{ $c->slug }}" @checked($category?->id === $c->id)> {{ $c->name }}</label>
                            @foreach($c->children as $child)
                                <label class="check pl-6"><input type="radio" name="category" value="{{ $child->slug }}" @checked($category?->id === $child->id)> <span class="text-grey-text">{{ $child->name }}</span></label>
                            @endforeach
                        @endforeach
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="eyebrow mb-3">Price ({{ config('abiha.currency.symbol') }})</legend>
                    <div class="flex items-center gap-2">
                        <label class="sr-only" for="min">Minimum price</label><input id="min" class="input !min-h-[44px]" type="number" min="0" inputmode="numeric" name="min" placeholder="Min" value="{{ $filters['min'] ?? '' }}">
                        <span aria-hidden="true">–</span>
                        <label class="sr-only" for="max">Maximum price</label><input id="max" class="input !min-h-[44px]" type="number" min="0" inputmode="numeric" name="max" placeholder="{{ $priceCeiling ?: 'Max' }}" value="{{ $filters['max'] ?? '' }}">
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="eyebrow mb-3">Availability</legend>
                    <label class="check"><input type="checkbox" name="availability" value="in_stock" @checked(($filters['availability'] ?? null) === 'in_stock')> In stock only</label>
                    <label class="check mt-2"><input type="checkbox" name="sale" value="1" @checked(! empty($filters['sale']))> On sale</label>
                </fieldset>

                @if($colors->isNotEmpty())
                    <fieldset>
                        <legend class="eyebrow mb-3">Colour</legend>
                        <div class="flex flex-wrap gap-3">
                            @foreach($colors as $c)
                                <label class="cursor-pointer" title="{{ $c->value }}">
                                    <input class="peer sr-only" type="checkbox" name="color[]" value="{{ $c->value }}" @checked(in_array($c->value, $filters['color'] ?? [], true))>
                                    <span class="block size-9 rounded-full border border-input peer-checked:outline peer-checked:outline-2 peer-checked:outline-offset-2 peer-checked:outline-ink peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-gold" style="background:{{ $c->color_hex ?? '#ccc' }}"></span>
                                    <span class="sr-only">{{ $c->value }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                @if($sizes->isNotEmpty())
                    <fieldset>
                        <legend class="eyebrow mb-3">Size</legend>
                        <div class="flex flex-wrap gap-2">
                            @foreach($sizes as $s)
                                <label>
                                    <input class="peer sr-only" type="checkbox" name="size[]" value="{{ $s }}" @checked(in_array($s, $filters['size'] ?? [], true))>
                                    <span class="chip grid place-content-center cursor-pointer peer-checked:bg-ink peer-checked:text-white peer-checked:border-ink peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-gold">{{ $s }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                <div class="flex gap-3 max-lg:sticky max-lg:bottom-0 max-lg:bg-white max-lg:py-3">
                    <button class="btn btn--sm flex-1">Apply</button>
                    <a href="{{ route('shop') }}" class="btn btn--sm btn--outline">Clear</a>
                </div>
            </form>
        </div>

        <section aria-label="Products">
            @if($products->isEmpty())
                @if($activeCount || $q || $category || $collection)
                    <x-empty-state icon="search" title="No products found" text="Try removing a filter or searching for something else." action="View all products" :href="route('shop')" />
                @else
                    <x-empty-state icon="shopping-bag" title="No products yet" text="Our collection is being prepared. Please check back soon." action="Back to home" :href="route('home')" />
                @endif
            @else
                <div class="grid grid--3 grid--products" style="--gap:1.75rem 1.25rem" data-reveal-group>
                    @foreach($products as $product)<x-product-card :product="$product" :eager="$loop->index < 4" data-reveal />@endforeach
                </div>
                <div class="mt-12">{{ $products->links() }}</div>
            @endif
        </section>
    </div>
</div>
@endsection
