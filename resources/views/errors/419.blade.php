@extends('layouts.app')
@section('title', 'Your session expired')
@section('noindex', true)

@section('content')
<div class="container py-20 lg:py-28 max-w-2xl text-center">
    <p class="eyebrow">Error 419</p>
    <h1 class="!text-3xl lg:!text-4xl !mt-3">Your session expired</h1>
    <p class="lead mx-auto mt-4">For your security the page timed out. Please go back, refresh it and try again.</p>
    <div class="mt-8 flex flex-wrap justify-center gap-3">
        <a href="{{ route('home') }}" class="btn">Back to home</a>
        <a href="{{ route('shop') }}" class="btn btn--outline">Continue shopping</a>
    </div>
</div>
@endsection
