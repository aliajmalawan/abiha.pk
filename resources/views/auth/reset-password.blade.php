@extends('layouts.app')
@section('title', 'Reset password')
@section('noindex', true)

@section('content')
<div class="container py-12 lg:py-20 max-w-md">
    <h1 class="!text-3xl text-center">Choose a new password</h1>
    <form method="POST" action="{{ route('password.update') }}" class="card mt-6 space-y-4" novalidate data-validate>
        @csrf
        <x-form-errors />
        <input type="hidden" name="token" value="{{ $token }}">
        <x-field name="email" label="Email" type="email" :value="$email" required autocomplete="email" />
        <x-field name="password" label="New password" type="password" required autocomplete="new-password" help="At least 8 characters, with letters and numbers." />
        <x-field name="password_confirmation" label="Confirm new password" type="password" required autocomplete="new-password" />
        <button class="btn btn--block">Reset password</button>
    </form>
</div>
@endsection
