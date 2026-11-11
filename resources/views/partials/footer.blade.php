@php
    $toPairs = fn (string $key) => array_map(fn ($l) => [$l['label'], $l['href']], \App\Support\Menus::get($key));
    $cols = array_filter(['Shop' => $toPairs('footer_shop'), 'Help' => $toPairs('footer_help')]);       // editable under Admin → Menus
    $footerPages = \App\Models\Page::published()->where('show_in_footer', true)->orderBy('sort_order')->get(['title', 'slug']);
    if ($footerPages->isNotEmpty()) {
        $cols['Company'] = $footerPages->map(fn ($p) => [$p->title, route('page', $p->slug)])->all();
    }
    $email = \App\Models\Setting::get('contact.email', config('abiha.contact.email'));
    $phone = \App\Models\Setting::get('contact.phone', config('abiha.contact.phone'));
    $address = trim((string) \App\Models\Setting::get('contact.address', config('abiha.contact.address')));
    $hours = trim((string) \App\Models\Setting::get('contact.hours'));
    $social = array_filter([
        'Instagram' => \App\Models\Setting::get('social.instagram'),
        'Facebook' => \App\Models\Setting::get('social.facebook'),
        'TikTok' => \App\Models\Setting::get('social.tiktok'),
        'YouTube' => \App\Models\Setting::get('social.youtube'),
    ]);
    $whatsapp = preg_replace('/\D/', '', (string) \App\Models\Setting::get('contact.whatsapp'));
    if ($whatsapp) {
        $social['WhatsApp'] = 'https://wa.me/'.$whatsapp;
    }
    $footerText = \App\Models\Setting::get('footer.text', config('abiha.tagline'));
@endphp
<footer class="footer mt-auto">
    {{-- Newsletter strip (the home page has its own sign-up block, so it is not repeated there) --}}
    @unless(request()->routeIs('home'))
        <div class="footer__news">
            <div class="container footer__news-in" x-data="newsletter('{{ route('newsletter') }}')">
                <div>
                    <p class="footer__news-title">Join the ABIHA list</p>
                    <p class="footer__news-sub">New arrivals and offers, straight to your inbox.</p>
                </div>
                <form method="POST" action="{{ route('newsletter') }}" @submit.prevent="submit()" novalidate class="footer__news-form">
                    @csrf
                    <label for="ft-nl-email" class="sr-only">Email address</label>
                    <input id="ft-nl-email" type="email" name="email" x-model="email" class="footer__input" placeholder="Your email address" required autocomplete="email">
                    <button class="footer__btn" :class="state === 'busy' && 'is-loading'">Subscribe</button>
                    <p class="footer__news-msg" x-show="message" :class="state === 'error' ? 'is-error' : 'is-ok'" x-text="message" role="status"></p>
                </form>
            </div>
        </div>
    @endunless

    <div class="container footer__grid">
        <div class="footer__brand">
            <span class="footer__logo"><x-logo :width="96" :lazy="true" /></span>
            @if($footerText)<p class="footer__about">{{ $footerText }}</p>@endif
            @if($social)
                <ul class="footer__social" aria-label="Follow us">
                    @foreach($social as $name => $url)<li><a href="{{ $url }}" rel="noopener" target="_blank" aria-label="{{ $name }} (opens in a new tab)">{{ $name }}</a></li>@endforeach
                </ul>
            @endif
        </div>

        @foreach($cols as $title => $links)
            <nav class="footer__col" aria-label="{{ $title }}">
                <h2 class="footer__h">{{ $title }}</h2>
                <ul>
                    @foreach($links as [$label, $href])<li><a href="{{ $href }}">{{ $label }}</a></li>@endforeach
                </ul>
            </nav>
        @endforeach

        <div class="footer__col footer__contact">
            <h2 class="footer__h">Contact</h2>
            <ul>
                @if($address)<li><x-icon name="map-pin" :size="16" /><span>{!! nl2br(e($address)) !!}</span></li>@endif
                @if($phone)<li><x-icon name="phone" :size="16" /><a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}">{{ $phone }}</a></li>@endif
                @if($email)<li><x-icon name="mail" :size="16" /><a href="mailto:{{ $email }}">{{ $email }}</a></li>@endif
                @if($hours)<li><x-icon name="truck" :size="16" /><span>{{ $hours }}</span></li>@endif
                @if(!$email && !$phone && config('abiha.show_sample_labels'))<li class="footer__muted">Contact details to be added <span class="sample-flag">SAMPLE</span></li>@endif
            </ul>
        </div>
    </div>

    <div class="footer__bar">
        <div class="container footer__bar-in">
            <span>© {{ date('Y') }} {{ config('abiha.name') }}®. All rights reserved.</span>
            <span class="footer__ship"><span>We ship with</span>@foreach(['dhl', 'fedex', 'ups'] as $c)<span class="footer__ship-tile"><x-carrier-logo :code="$c" :height="22" /></span>@endforeach</span>
        </div>
    </div>
    {{-- Back to top: appears after scrolling, shows how far down the page you are, and sits above the mobile buy bar on product pages --}}
    <button type="button" class="to-top @if(request()->routeIs('product')) to-top--lifted @endif" aria-label="Back to top" title="Back to top"
            x-data="{ show: false, pct: 0, update() { const max = document.documentElement.scrollHeight - innerHeight; this.pct = max > 0 ? Math.min(1, scrollY / max) : 0; this.show = scrollY > 420; } }"
            x-init="update()" @scroll.window.passive="update()" @resize.window.passive="update()"
            x-show="show" x-cloak x-transition.opacity.duration.200ms
            @click="window.scrollTo({ top: 0, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })">
        <svg class="to-top__ring" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><circle class="to-top__track" cx="24" cy="24" r="22"/><circle class="to-top__bar" cx="24" cy="24" r="22" :style="'stroke-dashoffset:' + (138.23 * (1 - pct))"/></svg>
        <x-icon name="arrow-up" :size="20" />
    </button>
</footer>
