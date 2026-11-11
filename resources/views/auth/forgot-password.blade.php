@extends('layouts.app')
@section('title', 'Forgot password')
@section('noindex', true)

@section('content')
<div class="container py-12 lg:py-20 max-w-md">
    <h1 class="!text-3xl text-center">Forgot your password?</h1>
    <p class="lead mx-auto text-center">Enter your email and we'll send you a link to reset it.</p>
    @if(session('status'))<div class="alert alert--success mt-4" role="status">{{ session('status') }}</div>@endif
    <form method="POST" action="{{ route('password.email') }}" class="card mt-6 space-y-4" novalidate data-validate>
        @csrf
        <x-form-errors />
        <x-field name="email" label="Email" type="email" required autocomplete="email" autofocus />
        <button class="btn btn--block">Send reset link</button>
    </form>
    <p class="text-center text-sm mt-6"><a class="link" href="{{ route('login') }}">Back to sign in</a></p>
</div>
@endsection
