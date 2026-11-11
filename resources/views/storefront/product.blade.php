@extends('layouts.app')

@section('title', ($product->meta_title ?: $product->name))
@section('description', $product->meta_description ?: \Illuminate\Support\Str::limit(strip_tags($product->short_description ?: $product->description), 155))

@php
    $trail = array_merge([['Home', route('home')]], \App\Support\SchemaBuilder::categoryTrail($product->category));
    $trail[] = [$product->name, ''];
    $seo->type('product')->breadcrumbs($trail)->image($product->imagePath(0))->schema(\App\Support\SchemaBuilder::product($product));
    // Demo (sample) products must never be indexed, even on a production domain.
    if ($product->is_sample) { $seo->noindex(); }
@endphp

@section('content')
@php $imageUrls = $product->images->map(fn ($i) => \Illuminate\Support\Facades\Storage::disk('public')->url($i->path))->values(); @endphp
<div class="container py-8 lg:py-12">
    <x-breadcrumbs class="mb-6" />

    <div class="grid gap-10 lg:grid-cols-[1.1fr_1fr] lg:gap-16">
        {{-- Gallery with hover zoom --}}
        <div x-data="gallery(@js($imageUrls))" class="lg:sticky lg:top-28 self-start">
            <div class="grid gap-3 {{ $imageUrls->count() > 1 ? 'lg:grid-cols-[84px_1fr]' : '' }}">
                @if($imageUrls->count() > 1)
                    <div class="order-2 lg:order-1 flex lg:flex-col gap-2 overflow-x-auto lg:overflow-visible" role="tablist" aria-label="Product images">
                        @foreach($product->images as $i => $img)
                            <button type="button" role="tab" :aria-selected="active === {{ $i }}" class="size-20 shrink-0 rounded border overflow-hidden bg-ivory" :class="active === {{ $i }} ? 'border-ink' : 'border-line'" @click="active = {{ $i }}" aria-label="Show image {{ $i + 1 }}"><x-picture :path="$img->path" alt="" sizes="80px" class="size-full object-contain" /></button>
                        @endforeach
                    </div>
                @endif
                <div class="order-1 lg:order-2 relative aspect-[4/5] overflow-hidden rounded-lg bg-ivory border border-line"
                     @mouseenter="zoom = images.length > 0 && window.matchMedia('(hover:hover)').matches" @mouseleave="zoom = false" @mousemove="move($event)" :class="zoom && 'cursor-zoom-in'">
                    @if($imageUrls->isNotEmpty())
                        {{-- Rendered by the server (not Alpine) so the main photo is in the HTML for the browser to fetch at once: it is the page's LCP element. --}}
                        @foreach($product->images as $i => $img)
                            <x-picture :path="$img->path" :alt="($img->alt ?: $product->name).($i > 0 ? ' (view '.($i + 1).')' : '')" sizes="(min-width:1024px) 50vw, 100vw" :priority="$i === 0"
                                x-bind:aria-hidden="active !== {{ $i }}"
                                class="absolute inset-0 size-full object-contain transition-opacity duration-300 will-change-transform {{ $i === 0 ? '' : 'opacity-0' }}"
                                x-bind:class="active === {{ $i }} ? 'opacity-100' : 'opacity-0'"
                                x-bind:style="active === {{ $i }} && zoom ? `transform:scale(2);transform-origin:${ox}% ${oy}%;transition:transform .2s` : ''" />
                        @endforeach
                        @if($imageUrls->count() > 1)
                            <button type="button" class="absolute left-2 top-1/2 -translate-y-1/2 btn btn--light !px-2.5 !min-h-10 lg:hidden" @click="prev()" aria-label="Previous image"><x-icon name="chevron-left" /></button>
                            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 btn btn--light !px-2.5 !min-h-10 lg:hidden" @click="next()" aria-label="Next image"><x-icon name="chevron-right" /></button>
                        @endif
                    @else
                        <x-placeholder />
                    @endif
                    @if($label = $product->badge_label)<span class="badge badge--gold absolute left-3 top-3">{{ $label }}</span>@endif
                </div>
            </div>
        </div>

        {{-- Details --}}
        <div class="space-y-6">
            <div>
                @if($product->category)<a href="{{ route('category', $product->category) }}" class="eyebrow hit no-underline hover:underline">{{ $product->category->name }}</a>@endif
                <h1 class="!text-3xl lg:!text-4xl !mt-2 !mb-2">{{ $product->name }}</h1>
                @if($product->review_count)
                    <a href="#reviews" class="inline-flex items-center gap-2 text-sm no-underline hit"><x-stars :rating="$product->average_rating" /> <span class="muted">{{ $product->average_rating }} ({{ $product->review_count }} {{ Str::plural('review', $product->review_count) }})</span></a>
                @endif
                @if($product->is_sample && config('abiha.show_sample_labels'))<div class="mt-2"><span class="sample-flag">SAMPLE PRODUCT</span></div>@endif
            </div>

            @if($product->short_description)<p class="muted">{{ $product->short_description }}</p>@endif

            <x-product-form :product="$product" />

            <div class="border-t border-line divide-y divide-line" x-data="{ open: 'details' }">
                @foreach([
                    'details' => ['Description', $product->description, true],
                    'specs' => ['Specifications', $product->specifications, 'specs'],
                    'delivery' => ['Delivery information', $deliveryInfo, false],
                    'returns' => ['Return policy', $returnPolicy, false],
                ] as $id => [$heading, $content, $isHtml])
                    @continue(! $content)
                    <div>
                        <h2 class="!mb-0 !text-base"><button type="button" class="w-full flex items-center justify-between py-4 text-left font-display font-semibold" @click="open = open === '{{ $id }}' ? null : '{{ $id }}'" :aria-expanded="open === '{{ $id }}'">{{ $heading }} <x-icon name="chevron-down" :size="18" ::class="open === '{{ $id }}' && 'rotate-180'" /></button></h2>
                        @if($isHtml === 'specs')
                            <div x-show="open === '{{ $id }}'" class="pb-5 text-sm"><table class="w-full"><caption class="sr-only">Product specifications</caption><tbody>
                                @foreach($content as $row)<tr class="border-b border-line last:border-0"><th scope="row" class="py-2.5 pr-4 text-left font-semibold align-top w-2/5">{{ $row['label'] }}</th><td class="py-2.5 text-grey-text">{{ $row['value'] }}</td></tr>@endforeach
                            </tbody></table></div>
                        @else
                        <div x-show="open === '{{ $id }}'" class="pb-5 text-sm leading-relaxed prose-abiha">{!! $isHtml ? \App\Support\HtmlSanitizer::clean($content) : nl2br(e($content)) !!}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Reviews --}}
    <section id="reviews" class="mt-20 scroll-mt-28" aria-labelledby="reviews-h">
        <h2 id="reviews-h" class="!text-2xl">Customer reviews</h2>
        <div class="grid gap-10 lg:grid-cols-[280px_1fr]">
            <div>
                @if($product->review_count)
                    <p class="font-display font-extrabold text-5xl leading-none">{{ $product->average_rating }}</p>
                    <x-stars :rating="$product->average_rating" :size="20" class="mt-2" />
                    <p class="muted text-sm mt-1">{{ $product->review_count }} {{ Str::plural('review', $product->review_count) }}</p>
                    <div class="mt-4 space-y-1.5">
                        @foreach([5,4,3,2,1] as $star)
                            @php $c = $ratingCounts[$star] ?? 0; $pct = $product->review_count ? round($c / $product->review_count * 100) : 0; @endphp
                            <div class="flex items-center gap-2 text-xs"><span class="w-3">{{ $star }}</span><div class="flex-1 h-1.5 bg-line rounded"><div class="h-full bg-gold rounded" style="width:{{ $pct }}%"></div></div><span class="w-6 text-right text-grey-text">{{ $c }}</span></div>
                        @endforeach
                    </div>
                @else
                    <p class="muted">No reviews yet.</p>
                @endif
            </div>
            <div class="space-y-8">
                @foreach($reviews as $r)
                    <article class="border-b border-line pb-6" data-review>
                        <div class="flex flex-wrap items-center gap-3"><x-stars :rating="$r->rating" /> @if($r->is_verified_purchase)<span class="badge badge--success">Verified purchase</span>@endif @if($r->is_sample && config('abiha.show_sample_labels'))<span class="sample-flag">SAMPLE</span>@endif</div>
                        @if($r->title)<h3 class="!text-base !mt-2 !mb-1">{{ $r->title }}</h3>@endif
                        <p class="mt-1 text-sm">{{ $r->body }}</p>
                        <p class="mt-2 text-xs text-grey-text">{{ $r->name }} · {{ $r->created_at->format('j M Y') }}</p>
                    </article>
                @endforeach
                {{ $reviews->links() }}

                @auth
                    @if($canReview)
                        <form method="POST" action="{{ route('reviews.store', $product) }}" class="card space-y-4 !bg-ivory !border-transparent">
                            @csrf
                            <h3 class="!text-lg !mb-0">Write a review</h3>
                            <fieldset><legend class="label mb-2">Your rating</legend>
                                <div class="flex gap-4">@foreach([1,2,3,4,5] as $s)<label class="check"><input type="radio" name="rating" value="{{ $s }}" @checked(old('rating') == $s) required> {{ $s }}★</label>@endforeach</div>
                                @error('rating')<span class="error-text">{{ $message }}</span>@enderror
                            </fieldset>
                            <x-field name="title" label="Title (optional)" />
                            <div class="field"><label class="label" for="f-body">Review <span class="req">*</span></label><textarea class="textarea" id="f-body" name="body" required minlength="10">{{ old('body') }}</textarea>@error('body')<span class="error-text">{{ $message }}</span>@enderror</div>
                            <button class="btn">Submit review</button>
                            <p class="help">Reviews are published after moderation.</p>
                        </form>
                    @endif
                @else
                    <p class="text-sm"><a class="link" href="{{ route('login') }}">Sign in</a> to write a review.</p>
                @endauth
            </div>
        </div>
    </section>

    @if($related->isNotEmpty())
        <section class="mt-20" aria-labelledby="rel-h">
            <h2 id="rel-h" class="!text-2xl">You may also like</h2>
            <div class="grid grid--4 grid--products mt-8">@foreach($related as $p)<x-product-card :product="$p" />@endforeach</div>
        </section>
    @endif

    @if($recent->isNotEmpty())
        <section class="mt-20" aria-labelledby="rec-h">
            <h2 id="rec-h" class="!text-2xl">Recently viewed</h2>
            <div class="grid grid--4 grid--products mt-8">@foreach($recent->take(4) as $p)<x-product-card :product="$p" />@endforeach</div>
        </section>
    @endif
</div>

{{-- Size guide: the owner's own measurements (Admin → Settings → Size guide), shown in a modal. Hidden entirely until they write one. --}}
@if(trim((string) \App\Models\Setting::get('size.guide')) !== '')
    <div x-data="{ open: false }" @open-size-guide.window="open = true" @keydown.escape.window="open = false">
        <div x-cloak x-show="open" class="fixed inset-0 z-[300] grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="size-guide-title"
             x-transition:enter="transition-opacity duration-200" x-transition:enter-start="opacity-0" x-transition:leave="transition-opacity duration-150" x-transition:leave-end="opacity-0">
            <div class="absolute inset-0 dialog-scrim" @click="open = false"></div>
            <div class="dialog-surface relative rounded-lg shadow-lift w-full max-w-2xl max-h-[88vh] overflow-y-auto p-6 md:p-8" tabindex="-1" x-trap="open">
                <button type="button" class="absolute right-3 top-3 btn btn--ghost !px-2" @click="open = false" aria-label="Close size guide"><x-icon name="x" :size="22" /></button>
                <h2 id="size-guide-title" class="!text-2xl">Size guide</h2>
                <div class="prose-abiha text-sm overflow-x-auto">{!! \App\Support\HtmlSanitizer::clean(\App\Models\Setting::get('size.guide')) !!}</div>
            </div>
        </div>
    </div>
@endif

@endsection
