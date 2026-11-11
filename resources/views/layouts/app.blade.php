<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $siteName = config('abiha.name').'®';
        $pageTitle = trim($__env->yieldContent('title'));
        $defaultTitle = \App\Models\Setting::get('seo.title') ?: $siteName.' | Premium Ecommerce';
        $defaultDesc = \App\Models\Setting::get('seo.description') ?: $siteName.' premium online store.';
        $favicon = \App\Support\SiteSettings::asset('site.favicon');

        // One place decides what search engines may do with this URL (see App\Support\Seo): non-production and
        // owner-disabled indexing, private pages and filtered listings are all handled there.
        if (trim($__env->yieldContent('noindex')) !== '') { $seo->noindex(); }
        $robots = $seo->robots();
        $pageNo = max(1, (int) request()->query('page', 1));
        $suffix = $pageNo > 1 && ! $seo->hasViewParams(request()) ? ' (Page '.$pageNo.')' : '';   // unique titles for paginated listings
        $fullTitle = ($pageTitle !== '' ? $pageTitle.$suffix.' | '.$siteName : $defaultTitle.$suffix);
        $metaDesc = \App\Support\Seo::plain(trim($__env->yieldContent('description')) ?: $defaultDesc, 300);
        $canonical = $seo->canonicalUrl(request());
        $ogImage = $seo->imageUrl();
    @endphp
    <title>{{ $fullTitle }}</title>
    <meta name="description" content="{{ $metaDesc }}">
    <link rel="canonical" href="{{ $canonical }}">
    @if($robots)<meta name="robots" content="{{ $robots }}">@endif
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:type" content="{{ $seo->ogType() }}">
    <meta property="og:title" content="{{ $pageTitle !== '' ? $pageTitle.$suffix : $defaultTitle }}">
    <meta property="og:description" content="{{ $metaDesc }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta name="twitter:card" content="{{ $seo->hasCustomImage() ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $pageTitle !== '' ? $pageTitle.$suffix : $defaultTitle }}">
    <meta name="twitter:description" content="{{ $metaDesc }}">
    <meta name="twitter:image" content="{{ $ogImage }}">
    {{-- Self-hosted variable fonts, fetched at once instead of after the CSS is parsed (faster first text paint, no third-party requests). --}}
    <link rel="preload" href="{{ \Illuminate\Support\Facades\Vite::asset('resources/fonts/inter-latin-var.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ \Illuminate\Support\Facades\Vite::asset('resources/fonts/cormorant-garamond-latin-var.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="icon" @unless($favicon) type="image/png" sizes="32x32" @endunless href="{{ $favicon ?? asset('assets/brand/favicon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ $favicon ?? asset('assets/brand/apple-touch-icon.png') }}">
    <meta name="theme-color" content="#101010">
    @stack('meta')
    {!! $seo->jsonLd() !!}
    @php
        $clientConfig = [
            'currency' => config('abiha.currency.symbol'),
            'cartCount' => $cartCount,
            'wishlistIds' => array_values($wishlistIds),
            'cartDrawerUrl' => route('cart.drawer'),
        ];
    @endphp
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        document.documentElement.classList.add('js');   // lets CSS hide scroll-reveal items only when JS can reveal them
        window.ABIHA = @json($clientConfig);
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex flex-col">
    <a class="skip-link" href="#main">Skip to content</a>
    @include('partials.header')
    <main id="main" class="flex-1">
        @include('partials.flash')
        @yield('content')
    </main>
    @include('partials.footer')

    {{-- Quick view dialog --}}
    <div x-data x-cloak x-show="$store.quick.open" class="fixed inset-0 z-[300] grid place-items-center p-4"
         role="dialog" aria-modal="true" aria-labelledby="quick-title" @keydown.escape.window="$store.quick.close()"
         x-transition:enter="transition-opacity duration-200" x-transition:enter-start="opacity-0" x-transition:leave="transition-opacity duration-150" x-transition:leave-end="opacity-0">
        <div class="absolute inset-0 dialog-scrim" @click="$store.quick.close()"></div>
        <div class="dialog-surface relative rounded-lg shadow-lift w-full max-w-4xl max-h-[92vh] overflow-y-auto" tabindex="-1" x-trap="$store.quick.open">
            <h2 id="quick-title" class="sr-only">Product quick view</h2>
            <button class="absolute right-3 top-3 z-10 btn btn--ghost !px-2" @click="$store.quick.close()" aria-label="Close quick view"><x-icon name="x" :size="22" /></button>
            <div x-show="$store.quick.loading" class="grid gap-8 p-6 md:grid-cols-2 md:p-8" aria-busy="true" aria-label="Loading product">
                <span class="skeleton skeleton--thumb"></span>
                <div class="space-y-4"><span class="skeleton skeleton--line w-1/3"></span><span class="skeleton skeleton--line w-3/4 !h-6"></span><span class="skeleton skeleton--line w-1/2"></span><span class="skeleton skeleton--line w-full"></span><span class="skeleton skeleton--line w-5/6"></span><span class="skeleton !h-12 w-full mt-6"></span></div>
            </div>
            <div x-html="$store.quick.html"></div>
        </div>
    </div>

    {{-- Cart drawer --}}
    <div x-data x-cloak x-show="$store.drawer.open" class="fixed inset-0 z-[310]" role="dialog" aria-modal="true" aria-labelledby="drawer-title"
         @keydown.escape.window="$store.drawer.close()">
        <div class="absolute inset-0 dialog-scrim" @click="$store.drawer.close()"
             x-show="$store.drawer.open" x-transition:enter="transition-opacity duration-200" x-transition:enter-start="opacity-0" x-transition:leave="transition-opacity duration-150" x-transition:leave-end="opacity-0"></div>
        <aside class="dialog-surface absolute inset-y-0 right-0 flex w-full max-w-md flex-col shadow-lift" tabindex="-1" x-trap="$store.drawer.open"
               x-show="$store.drawer.open" x-transition:enter="transition-transform duration-300 ease-out" x-transition:enter-start="translate-x-full" x-transition:leave="transition-transform duration-200 ease-in" x-transition:leave-end="translate-x-full">
            <header class="flex items-center justify-between border-b border-line px-5 py-4">
                <h2 id="drawer-title" class="!text-lg !mb-0">Your bag <span class="text-grey-text font-normal" x-show="$store.cart.count > 0" x-text="`(${$store.cart.count})`"></span></h2>
                <button class="btn btn--ghost !px-2" @click="$store.drawer.close()" aria-label="Close bag"><x-icon name="x" :size="22" /></button>
            </header>
            <div class="flex min-h-0 flex-1 flex-col" aria-live="polite">
                <div x-show="$store.drawer.loading" class="space-y-5 p-5" aria-busy="true" aria-label="Loading your bag">
                    @for($i = 0; $i < 3; $i++)<div class="flex gap-4"><span class="skeleton !w-20 shrink-0 skeleton--thumb"></span><div class="flex-1 space-y-3 pt-1"><span class="skeleton skeleton--line w-3/4"></span><span class="skeleton skeleton--line w-1/3"></span></div></div>@endfor
                </div>
                <div class="flex min-h-0 flex-1 flex-col" x-show="!$store.drawer.loading" x-html="$store.drawer.html"></div>
            </div>
        </aside>
    </div>

    @include('partials.confirm-dialog')

    {{-- Toast: polite live region, dismissible, pauses on hover/focus --}}
    <div x-data x-cloak class="pointer-events-none fixed bottom-4 left-1/2 z-[400] w-[calc(100%-2rem)] max-w-md -translate-x-1/2" role="status" aria-live="polite" aria-atomic="true">
        <div x-show="$store.ui.toast" class="toast pointer-events-auto relative" :class="$store.ui.toast?.type === 'error' && 'toast--error'"
             @mouseenter="$store.ui.pause()" @mouseleave="$store.ui.start()" @focusin="$store.ui.pause()" @focusout="$store.ui.start()"
             x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="opacity-0 translate-y-2" x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="opacity-0">
            <span x-text="$store.ui.toast?.message"></span>
            <template x-if="$store.ui.toast?.link"><a :href="$store.ui.toast.link.href" x-text="$store.ui.toast.link.label"></a></template>
            <button type="button" @click="$store.ui.dismiss()" aria-label="Dismiss notification"><x-icon name="x" :size="16" /></button>
            <template x-if="$store.ui.toast"><span class="toast-bar" :style="`--toast-ms:${$store.ui.ms}ms`" aria-hidden="true"></span></template>
        </div>
    </div>
    @stack('scripts')
</body>
</html>
