{{-- A product block: centred heading, optional collection tabs, then the products in the chosen layout. --}}
@php $layout = $section->setting('layout', 'carousel'); @endphp
<section class="section {{ $toneClass ?? '' }}">
    <div class="container">
        <div class="home-head">
            @if($section->subtitle)<p class="eyebrow !m-0">{{ $section->subtitle }}</p>@endif
            <h2>{{ $section->title }}</h2>
            <hr class="gold-rule">
        </div>

        @if(!empty($tabs))
            <div x-data="{ tab: 0 }">
                <div class="home-tabs" role="tablist" aria-label="{{ $section->title }}">
                    @foreach($tabs as $k => $t)
                        <button type="button" role="tab" class="home-tab" :aria-selected="tab === {{ $k }}" @click="tab = {{ $k }}; $nextTick(() => window.dispatchEvent(new Event('resize')))">{{ $t['label'] }}</button>
                    @endforeach
                </div>
                @foreach($tabs as $k => $t)
                    <div role="tabpanel" x-show="tab === {{ $k }}" @if($k > 0) x-cloak @endif>
                        @include('storefront.partials.product-panel', ['products' => $t['products'], 'layout' => $layout, 'link' => $t['link'] ?? null, 'label' => $t['label']])
                    </div>
                @endforeach
            </div>
        @else
            @include('storefront.partials.product-panel', ['products' => $products, 'layout' => $layout, 'link' => $section->setting('link'), 'label' => $section->title])
        @endif
    </div>
</section>
