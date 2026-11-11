@extends('layouts.app')
@section('title', $page->meta_title ?: 'FAQ: Frequently asked questions')
@section('description', $page->meta_description ?: 'Answers about ordering, payment, delivery, returns and your account at '.config('abiha.name').'.')

@php
    $seo->breadcrumbs([['Home', route('home')], ['FAQ', '']]);
    if ($page->is_sample && config('abiha.show_sample_labels')) { $seo->noindex(); }
    $seo->schema([
        '@context' => 'https://schema.org', '@type' => 'FAQPage',
        'mainEntity' => collect($groups)->flatten(1)->map(fn ($i) => [
            '@type' => 'Question', 'name' => $i['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($i['a']))))],
        ])->values()->all(),
    ]);
    $phone = trim((string) \App\Models\Setting::get('contact.phone', config('abiha.contact.phone')));
    $email = trim((string) \App\Models\Setting::get('contact.email', config('abiha.contact.email')));
@endphp

@section('content')
<div x-data="faq()">
    <section class="faq-hero">
        <div class="container">
            <x-breadcrumbs class="mb-6" />
            <p class="eyebrow">Help centre</p>
            <h1>Frequently asked questions</h1>
            <p class="faq-hero__lead">Quick answers about ordering, payment, delivery and returns. Cannot find yours? We are happy to help.</p>
            <div class="faq-search">
                <label for="faq-q" class="sr-only">Search the questions</label>
                <x-icon name="search" :size="20" />
                <input id="faq-q" type="search" x-model.debounce.150ms="q" placeholder="Search, for example “delivery” or “refund”" autocomplete="off">
            </div>
        </div>
    </section>

    <div class="container faq">
        <nav class="faq-nav" aria-label="Question topics">
            @foreach($groups as $title => $items)
                <a href="#faq-{{ Str::slug($title) }}" class="faq-pill">{{ $title }}</a>
            @endforeach
        </nav>

        <div class="faq-body">
            @foreach($groups as $title => $items)
                <section id="faq-{{ Str::slug($title) }}" class="faq-group" x-show="groupVisible($el)" aria-labelledby="faq-h-{{ Str::slug($title) }}">
                    <h2 id="faq-h-{{ Str::slug($title) }}" class="faq-group__h">{{ $title }}</h2>
                    @foreach($items as $i)
                        <details class="faq-item" data-faq x-show="match($el)" :open="q.trim() !== '' && match($el)">
                            <summary><span>{{ $i['q'] }}</span><x-icon name="plus" :size="20" class="faq-item__plus" /></summary>
                            <div class="faq-item__a">{!! $i['a'] !!}</div>
                        </details>
                    @endforeach
                </section>
            @endforeach

            <p class="faq-empty" x-show="q.trim() !== '' && none()" x-cloak role="status">
                Nothing matches “<span x-text="q"></span>”. Try another word, or <a href="{{ route('contact') }}">send us a message</a>.
            </p>
        </div>

        <aside class="faq-help" aria-labelledby="faq-help-h">
            <h2 id="faq-help-h">Still need help?</h2>
            <p>Tell us your order number and what you need, and we will get back to you.</p>
            <div class="faq-help__actions">
                <a href="{{ route('contact') }}" class="btn">Contact us</a>
                <a href="{{ route('track') }}" class="btn btn--outline">Track your order</a>
            </div>
            @if($phone !== '' || $email !== '')
                <ul class="faq-help__lines">
                    @if($phone !== '')<li><x-icon name="phone" :size="16" /><a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}">{{ $phone }}</a></li>@endif
                    @if($email !== '')<li><x-icon name="mail" :size="16" /><a href="mailto:{{ $email }}">{{ $email }}</a></li>@endif
                </ul>
            @endif
        </aside>
    </div>
</div>

@endsection
