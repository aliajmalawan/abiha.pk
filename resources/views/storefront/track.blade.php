@extends('layouts.app')
@section('title', 'Track your order')
@section('noindex', true)

@section('content')
<div class="container py-12 lg:py-16 max-w-2xl">
    <h1 class="!text-3xl lg:!text-4xl">Track your order</h1>
    <p class="lead">Enter your order number and the email you used at checkout.</p>

    <form method="POST" action="{{ route('track.lookup') }}" class="card mt-6 space-y-4" novalidate data-validate>
        @csrf
        <x-form-errors />
        <x-field name="number" label="Order number" required placeholder="ABH-000000-XXXXX" :value="is_string(request('number')) ? request('number') : null" />
        <x-field name="email" label="Email" type="email" required autocomplete="email" />
        <button class="btn">Track order</button>
    </form>

    @if($order)
        @include('partials.order-tracking', ['order' => $order])
    @endif
</div>
@endsection
