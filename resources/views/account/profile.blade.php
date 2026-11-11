@extends('account.layout')
@section('title', 'Profile')
@section('heading', 'Profile & password')

@section('account')
<div class="grid gap-8 xl:grid-cols-2">
    <form method="POST" action="{{ route('account.profile.update') }}" class="card space-y-4" novalidate data-validate>
        @csrf @method('PUT')
        <x-form-errors />
        <h2 class="!text-xl">Your details</h2>
        <x-field name="name" label="Full name" :value="auth()->user()->name" required autocomplete="name" />
        <x-field name="email" label="Email" type="email" :value="auth()->user()->email" required autocomplete="email" />
        <x-field name="phone" label="Phone" type="tel" :value="auth()->user()->phone" autocomplete="tel" />
        <x-field name="email_password" label="Current password" type="password" autocomplete="current-password" help="Only needed if you change your email address." />
        <button class="btn">Save changes</button>
        @if(session('status') && ! $errors->any())<p class="text-sm text-success" role="status">{{ session('status') }}</p>@endif
    </form>

    <form method="POST" action="{{ route('account.password.update') }}" class="card space-y-4" novalidate data-validate>
        @csrf @method('PUT')
        <h2 class="!text-xl">Change password</h2>
        <x-field name="current_password" label="Current password" type="password" required autocomplete="current-password" />
        <x-field name="password" label="New password" type="password" required autocomplete="new-password" help="At least 8 characters, with letters and numbers." />
        <x-field name="password_confirmation" label="Confirm new password" type="password" required autocomplete="new-password" />
        <button class="btn">Update password</button>
    </form>
</div>
@endsection
