@extends('layouts.app')
@section('title', 'Create account')
@section('noindex', true)

@section('content')
<div class="container py-12 lg:py-20 max-w-md">
    <h1 class="!text-3xl text-center">Create your account</h1>
    <form method="POST" action="{{ route('register') }}" class="card mt-6 space-y-4" novalidate data-validate>
        @csrf
        <x-form-errors />
        <x-field name="name" label="Full name" required autocomplete="name" autofocus />
        <x-field name="email" label="Email" type="email" required autocomplete="email" />
        <x-field name="phone" label="Phone (optional)" type="tel" autocomplete="tel" />
        <x-field name="password" label="Password" type="password" required autocomplete="new-password" help="At least 8 characters, with letters and numbers." />
        <x-field name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />
        <button class="btn btn--block">Create account</button>
    </form>
    <p class="text-center text-sm mt-6">Already registered? <a class="link" href="{{ route('login') }}">Sign in</a></p>
</div>
@endsection
