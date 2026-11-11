@extends('layouts.app')
@section('title', 'Sign in')
@section('noindex', true)

@section('content')
<div class="container py-12 lg:py-20 max-w-md">
    <h1 class="!text-3xl text-center">Sign in</h1>
    @if(session('status'))<div class="alert alert--success mt-4" role="status">{{ session('status') }}</div>@endif
    <form method="POST" action="{{ route('login') }}" class="card mt-6 space-y-4" novalidate data-validate>
        @csrf
        <x-form-errors />
        <x-field name="email" label="Email" type="email" required autocomplete="email" autofocus />
        <x-field name="password" label="Password" type="password" required autocomplete="current-password" />
        <div class="flex items-center justify-between text-sm">
            <label class="check"><input type="checkbox" name="remember" value="1"> Remember me</label>
            <a class="link inline-block py-2" href="{{ route('password.request') }}">Forgot password?</a>
        </div>
        <button class="btn btn--block">Sign in</button>
    </form>
    <p class="text-center text-sm mt-6">New to ABIHA®? <a class="link" href="{{ route('register') }}">Create an account</a></p>
</div>
@endsection
