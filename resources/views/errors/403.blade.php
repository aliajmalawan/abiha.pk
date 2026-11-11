@extends('layouts.app')
@section('title', 'You do not have access')
@section('noindex', true)

@section('content')
<div class="container py-20 lg:py-28 max-w-2xl text-center">
    <p class="eyebrow">Error 403</p>
    <h1 class="!text-3xl lg:!text-4xl !mt-3">You do not have access</h1>
    <p class="lead mx-auto mt-4">Your account is not allowed to open this page. If you think this is a mistake, please contact us.</p>
    <div class="mt-8 flex flex-wrap justify-center gap-3">
        <a href="{{ route('home') }}" class="btn">Back to home</a>
        <a href="{{ route('shop') }}" class="btn btn--outline">Continue shopping</a>
    </div>
</div>
@endsection
