@extends('layouts.app')
@section('noindex', true)

@section('content')
@php
    $links = [
        ['account.dashboard', 'Overview', 'layout-dashboard'], ['account.orders', 'Orders', 'package'], ['account.addresses', 'Addresses', 'map-pin'],
        ['account.profile', 'Profile & password', 'user'], ['wishlist', 'Wishlist', 'heart'],
    ];
@endphp
<div class="container py-10 lg:py-14">
    <h1 class="!text-3xl lg:!text-4xl">@yield('heading', 'My account')</h1>
    <div class="grid gap-10 lg:grid-cols-[240px_1fr] mt-8">
        <nav aria-label="Account" class="flex lg:flex-col gap-1 overflow-x-auto lg:overflow-visible border-b lg:border-b-0 border-line pb-2 lg:pb-0">
            @foreach($links as [$route, $label, $icon])
                @php $active = request()->routeIs($route) || ($route === 'account.orders' && request()->routeIs('account.orders.*')); @endphp
                <a href="{{ route($route) }}" @if($active) aria-current="page" @endif class="flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-semibold whitespace-nowrap no-underline {{ $active ? 'bg-ink text-white' : 'hover:bg-ivory' }}"><x-icon :name="$icon" :size="18" /> {{ $label }}</a>
            @endforeach
            <form method="POST" action="{{ route('logout') }}" class="lg:mt-4">@csrf
                <button class="flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-sm font-semibold text-grey-text hover:bg-ivory whitespace-nowrap"><x-icon name="log-out" :size="18" /> Sign out</button>
            </form>
        </nav>
        <div class="min-w-0">@yield('account')</div>
    </div>
</div>
@endsection
