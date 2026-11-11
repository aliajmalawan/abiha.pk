@php
    $simpleLinks = \App\Support\Menus::get('header');       // editable under Admin → Menus
@endphp

@php
    $topPhone = trim((string) \App\Models\Setting::get('contact.phone', config('abiha.contact.phone')));
    $topEmail = trim((string) \App\Models\Setting::get('contact.email', config('abiha.contact.email')));
@endphp
@if(!empty($announcement['text']) || $topPhone !== '' || $topEmail !== '')
    {{-- Top bar: the shop's phone and email (Admin → Settings → Contact) beside the optional announcement (Admin → Settings → Announcement). --}}
    <div class="announce">
        <div class="announce__in">
            @if($topPhone !== '' || $topEmail !== '')
                <p class="announce__contact">
                    @if($topPhone !== '')<a href="tel:{{ preg_replace('/[^\d+]/', '', $topPhone) }}" class="announce__link"><x-icon name="phone" :size="14" /><span>{{ $topPhone }}</span></a>@endif
                    @if($topEmail !== '')<a href="mailto:{{ $topEmail }}" class="announce__link"><x-icon name="mail" :size="14" /><span>{{ $topEmail }}</span></a>@endif
                </p>
            @endif
            @if(!empty($announcement['text']))
                <p class="announce__text">@if(!empty($announcement['url']))<a href="{{ $announcement['url'] }}" class="hover:underline">{{ $announcement['text'] }}</a>@else{{ $announcement['text'] }}@endif</p>
            @endif
        </div>
    </div>
@endif
<header class="sticky top-0 z-[100] border-b border-line bg-ivory"
        x-data="{ open: false, mega: false, searchOpen: false, mobileCat: null, megaCat: {{ $navCategories->first()?->id ?? 'null' }} }"
        @keydown.escape.window="open = false; mega = false"
        @click.outside="mega = false"
        @mouseleave="mega = false">
    <div class="container flex items-center justify-between gap-4" style="min-height:64px">
        {{-- Mobile menu button --}}
        <button class="btn btn--ghost lg:hidden !px-2" @click="open = true" aria-label="Open menu" :aria-expanded="open">
            <x-icon name="menu" :size="24" />
        </button>

        <a href="{{ route('home') }}" aria-label="ABIHA home" class="shrink-0 -my-2">
            <x-logo :width="76" />
        </a>

        {{-- Desktop nav --}}
        <nav class="hidden lg:flex items-center gap-8" aria-label="Primary">
            <div class="relative" @mouseenter="mega = true">
                <button type="button" class="nav-link inline-flex items-center gap-1" @click="mega = ! mega" :aria-expanded="mega" aria-controls="mega-menu" @if(request()->routeIs('shop', 'category', 'collection')) aria-current="page" @endif>
                    Shop <x-icon name="chevron-down" :size="14" />
                </button>
            </div>
            @foreach($simpleLinks as $l)
                <a class="nav-link" href="{{ $l['href'] }}" @mouseenter="mega = false" @if(rtrim(request()->fullUrl(), '/') === rtrim(url($l['href']), '/')) aria-current="page" @endif>{{ $l['label'] }}</a>
            @endforeach
        </nav>

        <div class="flex items-center gap-0.5 sm:gap-1">
            <button class="btn btn--ghost !px-2.5" @click="searchOpen = true; $nextTick(() => document.getElementById('site-search')?.focus())" aria-label="Search"><x-icon name="search" /></button>
            <a class="btn btn--ghost !px-2.5 relative hidden sm:inline-flex" href="{{ route('wishlist') }}" aria-label="Wishlist">
                <x-icon name="heart" />
                <span x-data x-cloak x-show="$store.wish.ids.length" x-text="$store.wish.ids.length" class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-gold text-ink text-[11px] font-bold grid place-content-center"></span>
            </a>
            <a class="btn btn--ghost !px-2.5" href="{{ auth()->check() ? route('account.dashboard') : route('login') }}" aria-label="{{ auth()->check() ? 'My account' : 'Sign in' }}"><x-icon name="user" /></a>
            <a class="btn btn--ghost !px-2.5 relative" href="{{ route('cart') }}" aria-label="Shopping bag" aria-haspopup="dialog" @click.prevent="$store.drawer.show()">
                <x-icon name="shopping-bag" />
                <span x-data x-text="$store.cart.count" x-show="$store.cart.count > 0" @if($cartCount < 1) x-cloak @endif class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-ink text-white text-[11px] font-bold grid place-content-center"></span>
            </a>
        </div>
    </div>

    {{-- Mega menu: categories on the left, the chosen category's sub-categories on the right, a "New arrivals" card at the end --}}
    <div id="mega-menu" x-cloak x-show="mega" x-transition.opacity.duration.150ms class="mega hidden lg:block absolute inset-x-0 top-full">
        <div class="container mega__in">
            <ul class="mega__cats" aria-label="Categories">
                @foreach($navCategories as $cat)
                    <li>
                        <a href="{{ route('category', $cat) }}" class="mega__cat" :class="{ 'is-on': megaCat === {{ $cat->id }} }"
                           @mouseenter="megaCat = {{ $cat->id }}" @focus="megaCat = {{ $cat->id }}" @click="mega = false">
                            <span>{{ $cat->name }}</span><x-icon name="chevron-right" :size="16" />
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="mega__panel">
                @foreach($navCategories as $cat)
                    <div x-show="megaCat === {{ $cat->id }}" @if(! $loop->first) x-cloak @endif>
                        <p class="mega__title">{{ $cat->name }}</p>
                        <hr class="gold-rule !my-3">
                        @if($cat->children->isNotEmpty())
                            <ul class="mega__cols">
                                @foreach($cat->children as $child)
                                    <li><a href="{{ route('category', $child) }}" @click="mega = false">{{ $child->name }}</a></li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mega__note">Browse everything in {{ $cat->name }}.</p>
                        @endif
                        <a href="{{ route('category', $cat) }}" class="mega__all" @click="mega = false">View all {{ $cat->name }} <x-icon name="arrow-right" :size="16" /></a>
                    </div>
                @endforeach
            </div>

            <a href="{{ route('shop', ['sort' => 'newest']) }}" @click="mega = false" class="mega__promo group">
                <span class="eyebrow">Just in</span>
                <span class="mega__promo-title">New arrivals</span>
                <span class="mega__promo-text">See what's new this season.</span>
                <span class="mega__promo-link">Shop now <x-icon name="arrow-right" :size="16" class="transition group-hover:translate-x-1" /></span>
            </a>
        </div>
    </div>

    {{-- Search overlay --}}
    <div x-cloak x-show="searchOpen" class="fixed inset-0 z-[250]" role="dialog" aria-modal="true" aria-label="Search" x-transition:enter="transition-opacity duration-150" x-transition:enter-start="opacity-0" x-transition:leave="transition-opacity duration-100" x-transition:leave-end="opacity-0"
         x-data="searchBox('{{ route('search.suggest') }}')" @keydown.escape.window="searchOpen = false">
        <div class="absolute inset-0 dialog-scrim" @click="searchOpen = false"></div>
        <div class="relative bg-white" tabindex="-1" x-trap="searchOpen">
            <div class="container py-6">
                <form action="{{ route('shop') }}" method="GET" role="search" class="flex items-center gap-3">
                    <x-icon name="search" :size="22" />
                    <input id="site-search" x-model="q" @input="lookup()" name="q" type="search" placeholder="Search products" autocomplete="off"
                           class="flex-1 min-h-[52px] text-lg bg-transparent outline-none placeholder:text-grey-text" aria-label="Search products">
                    <button type="button" class="btn btn--ghost !px-2" @click="searchOpen = false" aria-label="Close search"><x-icon name="x" :size="22" /></button>
                </form>
                <div class="mt-4 border-t border-line pt-4" x-show="q.trim().length >= 2">
                    <div x-show="loading && results.length === 0" class="grid gap-3 md:grid-cols-2" aria-busy="true" aria-label="Searching">@for($i = 0; $i < 4; $i++)<div class="flex items-center gap-4 p-2"><span class="skeleton size-14"></span><span class="flex-1 space-y-2"><span class="skeleton skeleton--line w-2/3"></span><span class="skeleton skeleton--line w-1/4"></span></span></div>@endfor</div>
                    <p x-show="!loading && results.length === 0" class="muted text-sm" role="status">No matches yet. Press Enter to search the whole shop.</p>
                    <ul class="grid gap-1 md:grid-cols-2">
                        <template x-for="r in results" :key="r.url">
                            <li><a :href="r.url" class="flex items-center gap-4 rounded-md p-2 no-underline hover:bg-ivory">
                                <span class="size-14 shrink-0 rounded bg-ivory border border-line overflow-hidden grid place-content-center"><img x-show="r.image" :src="r.image" alt="" class="size-full object-cover"></span>
                                <span><span class="block font-semibold text-sm" x-text="r.name"></span><span class="block text-xs text-grey-text" x-text="r.price"></span></span>
                            </a></li>
                        </template>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- Mobile drawer --}}
    <div x-cloak x-show="open" class="fixed inset-0 z-[200] lg:hidden" role="dialog" aria-modal="true" aria-label="Menu">
        <div class="absolute inset-0 dialog-scrim" @click="open = false" x-show="open" x-transition:enter="transition-opacity duration-200" x-transition:enter-start="opacity-0" x-transition:leave="transition-opacity duration-150" x-transition:leave-end="opacity-0"></div>
        <div class="absolute inset-y-0 left-0 w-[88%] max-w-sm bg-ivory flex flex-col overflow-y-auto" tabindex="-1" x-trap="open" x-show="open"
             x-transition:enter="transition-transform duration-300 ease-out" x-transition:enter-start="-translate-x-full" x-transition:leave="transition-transform duration-200 ease-in" x-transition:leave-end="-translate-x-full">
            <div class="flex items-center justify-between p-4 border-b border-line">
                <x-logo :width="72" :lazy="true" />
                <button class="btn btn--ghost !px-2" @click="open = false" aria-label="Close menu"><x-icon name="x" :size="24" /></button>
            </div>
            <nav class="flex-1 p-4" aria-label="Mobile">
                @foreach($navCategories as $cat)
                    <div class="border-b border-line">
                        <button type="button" class="w-full flex items-center justify-between py-4 font-display font-semibold uppercase tracking-wider text-sm"
                                @click="mobileCat = mobileCat === {{ $cat->id }} ? null : {{ $cat->id }}" :aria-expanded="mobileCat === {{ $cat->id }}">
                            {{ $cat->name }} <x-icon name="chevron-down" :size="18" class="transition-transform" x-bind:class="mobileCat === {{ $cat->id }} && 'rotate-180'" />
                        </button>
                        <ul x-show="mobileCat === {{ $cat->id }}" class="pb-3 pl-3 space-y-1 text-sm">
                            <li><a class="block py-2 font-semibold" href="{{ route('category', $cat) }}">All {{ $cat->name }}</a></li>
                            @foreach($cat->children as $child)<li><a class="block py-2 text-grey-text" href="{{ route('category', $child) }}">{{ $child->name }}</a></li>@endforeach
                        </ul>
                    </div>
                @endforeach
                @foreach($simpleLinks as $l)
                    <a href="{{ $l['href'] }}" class="block py-4 border-b border-line font-display font-semibold uppercase tracking-wider text-sm">{{ $l['label'] }}</a>
                @endforeach
                <a href="{{ route('wishlist') }}" class="block py-4 border-b border-line font-display font-semibold uppercase tracking-wider text-sm">Wishlist</a>
            </nav>
            <div class="p-4 border-t border-line">
                @auth
                    <a href="{{ route('account.dashboard') }}" class="btn btn--outline btn--block">My account</a>
                @else
                    <a href="{{ route('login') }}" class="btn btn--block">Sign in</a>
                    <p class="text-center text-sm mt-3 muted">New here? <a class="link" href="{{ route('register') }}">Create an account</a></p>
                @endauth
            </div>
        </div>
    </div>
</header>
