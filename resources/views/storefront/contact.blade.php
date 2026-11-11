@extends('layouts.app')
@section('title', 'Contact us')

@section('content')
<div class="container py-12 lg:py-16 max-w-2xl">
    <h1 class="!text-3xl lg:!text-4xl">Contact us</h1>
    <p class="lead">Questions about an order or a product? Send us a message.</p>
    @if(session('status'))<div class="alert alert--success mt-4" role="status">{{ session('status') }}</div>@endif
    @php
        $ci = collect(['Email' => \App\Models\Setting::get('contact.email'), 'Phone' => \App\Models\Setting::get('contact.phone'), 'Address' => \App\Models\Setting::get('contact.address'), 'Hours' => \App\Models\Setting::get('contact.hours')])->filter();
    @endphp
    @if($ci->isNotEmpty())<dl class="grid gap-x-8 gap-y-2 text-sm mt-4 sm:grid-cols-2">@foreach($ci as $label => $value)<div><dt class="eyebrow !text-[10px]">{{ $label }}</dt><dd class="whitespace-pre-line">{{ $value }}</dd></div>@endforeach</dl>@endif

    <form method="POST" action="{{ route('contact.send') }}" class="card mt-6 space-y-4" novalidate data-validate>
        @csrf
        <x-form-errors />
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="name" label="Your name" :value="auth()->user()?->name" required autocomplete="name" />
            <x-field name="email" label="Email" type="email" :value="auth()->user()?->email" required autocomplete="email" />
            <x-field name="phone" label="Phone (optional)" type="tel" :value="auth()->user()?->phone" autocomplete="tel" />
            <x-field name="subject" label="Subject (optional)" />
        </div>
        <div class="field"><label class="label" for="f-message">Message <span class="req" aria-hidden="true">*</span></label>
            <textarea class="textarea" id="f-message" name="message" required minlength="10" @error('message') aria-invalid="true" @enderror>{{ old('message') }}</textarea>
            @error('message')<span class="error-text">{{ $message }}</span>@enderror</div>
        {{-- Honeypot --}}
        <div class="sr-only" aria-hidden="true"><label>Leave blank <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <button class="btn">Send message</button>
    </form>
</div>
@endsection
