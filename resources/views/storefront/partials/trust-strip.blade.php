@php $trust = \App\Support\TrustStrip::items(); @endphp
@if(count($trust) >= 2)
    <section class="home-trust" aria-label="Shopping with us">
        <div class="container">
            <ul class="home-trust__card home-trust__card--{{ min(count($trust), 4) }}">
                @foreach($trust as $t)
                    <li class="contents">
                        @if($t['href'])<a href="{{ $t['href'] }}" class="home-trust__item">@else<div class="home-trust__item">@endif
                            <span class="home-trust__icon"><x-icon :name="$t['icon']" :size="22" /></span>
                            <span class="min-w-0"><span class="block text-sm font-semibold">{{ $t['title'] }}</span><span class="block text-xs text-grey-text">{{ $t['text'] }}</span></span>
                        @if($t['href'])</a>@else</div>@endif
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif
