@extends('layouts.app')
@section('title', $page->meta_title ?: $page->title)
@section('description', $page->meta_description ?: \Illuminate\Support\Str::limit(strip_tags((string) $page->body), 155))

@php
    $seo->breadcrumbs([['Home', route('home')], [$page->title, '']]);
    if ($page->is_sample) { $seo->noindex(); }   // placeholder legal/policy text must never be indexed
@endphp

@section('content')
<div class="container py-12 lg:py-16 max-w-3xl">
    <x-breadcrumbs class="mb-6" />
    <h1 class="!text-3xl lg:!text-4xl">{{ $page->title }}</h1>
    @if($page->is_sample && config('abiha.show_sample_labels'))<p><span class="sample-flag">SAMPLE CONTENT: replace before launch</span></p>@endif
    {{-- Admin-authored rich text. Rebuilt through an allow-list sanitizer (no scripts, no event handlers, safe URLs only). --}}
    <div class="prose-abiha mt-6">{!! \App\Support\HtmlSanitizer::clean($page->body) !!}</div>
</div>
@endsection
