@extends('layouts.app')
@section('title', 'Page not found')
@section('noindex', true)

@section('content')
<div class="container py-20 lg:py-28 max-w-2xl text-center">
    <p class="eyebrow">Error 404</p>
    <h1 class="!text-3xl lg:!text-4xl !mt-3">Page not found</h1>
    <p class="lead mx-auto mt-4">We could not find the page you were looking for. It may have moved, or the link may be mistyped.</p>
    <div class="mt-8 flex flex-wrap justify-center gap-3">
        <a href="{{ route('home') }}" class="btn">Back to home</a>
        <a href="{{ route('shop') }}" class="btn btn--outline">Continue shopping</a>
    </div>
</div>
@endsection
