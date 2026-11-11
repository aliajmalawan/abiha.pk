@extends('layouts.app')
@section('title', $page->meta_title ?: 'About us')
@section('description', $page->meta_description ?: 'Meet '.config('abiha.name').': performance, comfort and style for an active life. Browse our collections and learn how shopping with us works.')

@php
    $seo->breadcrumbs([['Home', route('home')], ['About us', '']]);
    $name = config('abiha.name');
@endphp

@section('content')
{{-- Hero --}}
<section class="about-hero">
    <div class="container">
        <x-breadcrumbs class="mb-6" />
        <p class="eyebrow">About us</p>
        <h1>{{ $name }}</h1>
        <hr class="gold-rule mx-auto">
        <p class="about-hero__lead">Performance, comfort and style for your active life.</p>
    </div>
</section>

{{-- Three promises --}}
<section class="section">
    <div class="container">
        <div class="about-pillars">
            <article class="about-pillar">
                <span class="about-pillar__icon"><x-icon name="check" :size="26" /></span>
                <h2>Performance</h2>
                <p>Activewear and sportswear made for movement, whether you train, play or work.</p>
            </article>
            <article class="about-pillar">
                <span class="about-pillar__icon"><x-icon name="heart" :size="26" /></span>
                <h2>Comfort</h2>
                <p>Pieces chosen to feel good from the first wear to the last repeat of the day.</p>
            </article>
            <article class="about-pillar">
                <span class="about-pillar__icon"><x-icon name="star" :size="26" /></span>
                <h2>Style</h2>
                <p>Clean looks in black, gold and classic colours that work in the gym and beyond.</p>
            </article>
        </div>
    </div>
</section>

{{-- What we offer: the real top-level categories that have products --}}
@if($collections->isNotEmpty())
    <section class="section section--ivory">
        <div class="container">
            <div class="home-head text-center">
                <p class="eyebrow">What we offer</p>
                <h2>Shop by collection</h2>
                <hr class="gold-rule mx-auto">
            </div>
            <ul class="about-cats">
                @foreach($collections as $c)
                    <li>
                        <a href="{{ route('category', $c['category']) }}" class="about-cat">
                            <span class="about-cat__name">{{ $c['category']->name }}</span>
                            @if($c['subs']->isNotEmpty())<span class="about-cat__subs">{{ $c['subs']->take(3)->implode(' · ') }}@if($c['subs']->count() > 3) · and more @endif</span>@endif
                            <span class="about-cat__go">Shop now <x-icon name="arrow-right" :size="16" /></span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif

{{-- How shopping works --}}
<section class="section">
    <div class="container">
        <div class="home-head text-center">
            <p class="eyebrow">Simple and clear</p>
            <h2>How shopping with us works</h2>
            <hr class="gold-rule mx-auto">
        </div>
        <ol class="about-steps">
            <li><span class="about-steps__n">1</span><x-icon name="search" :size="24" /><h3>Choose</h3><p>Browse the collections, open a product and pick your size and colour.</p></li>
            <li><span class="about-steps__n">2</span><x-icon name="shopping-bag" :size="24" /><h3>Order</h3><p>Add to your bag and check out as a guest or with an account. No account is needed.</p></li>
            <li><span class="about-steps__n">3</span><x-icon name="credit-card" :size="24" /><h3>Pay</h3><p>{{ $paymentLine }}</p></li>
            <li><span class="about-steps__n">4</span><x-icon name="truck" :size="24" /><h3>Track</h3><p>Follow your parcel any time on the <a href="{{ route('track') }}">Track your order</a> page.</p></li>
        </ol>
    </div>
</section>

{{-- Contact --}}
<section class="about-cta">
    <div class="container">
        <h2>We would love to hear from you</h2>
        <p>Questions about an order, a size or a product? Our team is happy to help.</p>
        <div class="about-cta__actions">
            <a href="{{ route('contact') }}" class="btn">Contact us</a>
            <a href="{{ route('page', 'faq') }}" class="btn btn--outline">Read the FAQ</a>
        </div>
        @if($phone !== '' || $email !== '')
            <ul class="about-cta__lines">
                @if($phone !== '')<li><x-icon name="phone" :size="16" /><a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}">{{ $phone }}</a></li>@endif
                @if($email !== '')<li><x-icon name="mail" :size="16" /><a href="mailto:{{ $email }}">{{ $email }}</a></li>@endif
            </ul>
        @endif
    </div>
</section>
@endsection
