@extends('layouts.app')
@section('title', 'Wishlist')
@section('noindex', true)

@section('content')
<div class="container py-10 lg:py-14">
    <h1 class="!text-3xl lg:!text-4xl">Wishlist</h1>
    @guest<p class="muted">Sign in to keep your wishlist across devices. <a class="link" href="{{ route('login') }}">Sign in</a></p>@endguest

    @if($products->isEmpty())
        <x-empty-state icon="heart" title="Nothing saved yet" text="Tap the heart on any product to keep it here for later." action="Explore the shop" :href="route('shop')" />
    @else
        <div class="grid grid--4 grid--products mt-8">
            @foreach($products as $product)<x-product-card :product="$product" :wishlist-remove="true" />@endforeach
        </div>
    @endif
</div>
@endsection
