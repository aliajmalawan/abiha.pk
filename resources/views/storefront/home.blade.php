@extends('layouts.app')

@php
    // Organization + WebSite (sitelinks search box) describe the brand once, on the home page. Facts come from the admin settings only.
    $seo->schema(\App\Support\SchemaBuilder::organization())->schema(\App\Support\SchemaBuilder::website());
@endphp
@section('description', \App\Models\Setting::get('seo.description') ?: 'Discover '.config('abiha.name').'®: considered pieces, quietly luxurious.')

@section('content')
    {{-- Every page needs exactly one h1; the hero banner supplies it, so only add a hidden one when there is no hero. --}}
    @unless($sections->contains(fn ($r) => $r['section']->type === 'hero'))<h1 class="sr-only">{{ config('abiha.name') }}®</h1>@endunless
    @php
        // A clean boutique rhythm: white and ivory sections take turns; only the campaign banner is black.
        // The admin still chooses WHICH blocks appear and in what order; this only picks the background of each kind.
        $fills = ['categories' => 'white', 'collections' => 'ivory', 'promo' => 'white', 'campaign' => 'dark', 'brand' => 'white',
                  'testimonials' => 'ivory', 'social' => 'white', 'newsletter' => 'ivory', 'account' => 'white'];
        $n = 0;
    @endphp
    @foreach($sections as $row)
        @php
            $type = $row['section']->type;
            if ($type === 'hero') { $tone = 'ivory'; }
            elseif ($type === 'products') { $tone = $n++ % 2 === 0 ? 'white' : 'ivory'; }
            else { $tone = $fills[$type] ?? 'white'; }
            $toneClass = ['white' => '', 'ivory' => 'section--ivory', 'grey' => 'section--grey', 'dark' => 'section--dark on-dark'][$tone];
        @endphp
        {{-- product rows reveal card-by-card inside their own grid; every other section fades up as a whole --}}
        <div @if(! in_array($type, ['hero', 'products'], true)) data-reveal @endif>
            @include('storefront.sections.'.$type, ['section' => $row['section'], 'tone' => $tone, 'toneClass' => $toneClass] + $row['data'])
        </div>
    @endforeach
@endsection
