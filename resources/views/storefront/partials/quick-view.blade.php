{{-- Loaded into the quick-view modal via fetch; Alpine initialises it inside x-html --}}
<div class="grid gap-8 p-6 md:grid-cols-2 md:p-8" x-data="gallery(@js($product->images->map(fn ($i) => \Illuminate\Support\Facades\Storage::disk('public')->url($i->path))->values()))">
    <div>
        <div class="relative aspect-[4/3] md:aspect-[4/5] overflow-hidden rounded-md bg-ivory border border-line">
            <template x-if="images.length"><img :src="images[active]" alt="{{ $product->name }}" class="size-full object-contain"></template>
            @if($product->images->isEmpty())<x-placeholder />@endif
        </div>
        @if($product->images->count() > 1)
            <div class="mt-3 flex gap-2 overflow-x-auto">
                <template x-for="(src, i) in images" :key="i"><button type="button" class="size-16 shrink-0 rounded border" :class="i === active ? 'border-ink' : 'border-line'" @click="active = i" :aria-label="'Image ' + (i + 1)"><img :src="src" alt="" class="size-full object-contain rounded"></button></template>
            </div>
        @endif
    </div>
    <div class="space-y-5">
        @if($product->category)<p class="eyebrow">{{ $product->category->name }}</p>@endif
        <h2 class="!text-2xl !mb-0">{{ $product->name }}</h2>
        @if($product->review_count)<p class="flex items-center gap-2 text-sm"><x-stars :rating="$product->average_rating" /> <span class="muted">({{ $product->review_count }})</span></p>@endif
        @if($product->short_description)<p class="muted">{{ $product->short_description }}</p>@endif
        <x-product-form :product="$product" :compact="true" />
        <a href="{{ route('product', $product) }}" class="link text-sm inline-block">View full details</a>
    </div>
</div>
