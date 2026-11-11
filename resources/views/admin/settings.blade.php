@extends('admin.layout')
@section('title', 'Settings')

@section('content')
<h1 class="!text-2xl">General settings</h1>
<p class="help mb-4">Payment methods and bank details are under <a class="link" href="{{ route('admin.payments.edit') }}">Payments</a>; shipping charges under <a class="link" href="{{ route('admin.shipping.index') }}">Shipping</a>.</p>
<form method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data" class="max-w-3xl space-y-6" novalidate>
    @csrf @method('PUT')
    @foreach($groups as $heading => $fields)
        <section class="card space-y-5" aria-labelledby="g-{{ Str::slug($heading) }}">
            <h2 class="!text-lg !mb-0" id="g-{{ Str::slug($heading) }}">{{ $heading }}</h2>
            @foreach($fields as $key => [$label, $type, $rules, $help])
                @php $name = \App\Http\Controllers\Admin\SettingController::formKey($key); @endphp
                <div class="field">
                    @if($type === 'checkbox')
                        <label class="check"><input type="checkbox" name="{{ $name }}" value="1" @checked(old($name, $values[$key]))> {{ $label }}</label>
                    @else
                        <label class="label" for="{{ $name }}">{{ $label }}</label>
                        @if($type === 'textarea')<textarea class="textarea" id="{{ $name }}" name="{{ $name }}" rows="4">{{ old($name, $values[$key]) }}</textarea>
                        @elseif($type === 'image')
                            @php $isIcon = $key === 'site.favicon'; $current = $values[$key] ? \Illuminate\Support\Facades\Storage::disk('public')->url($values[$key]) : null; @endphp
                            <div class="brandbox" x-data="{ url: null, file: '' }">
                                <div class="brandbox__stage" :class="url && 'brandbox__stage--new'">
                                    <img x-show="url" x-cloak :src="url" alt="Preview of the new file" class="brandbox__img {{ $isIcon ? 'brandbox__img--icon' : '' }}">
                                    <div x-show="!url">
                                        @if($current)<img src="{{ $current }}" alt="Current {{ $isIcon ? 'favicon' : 'logo' }}" class="brandbox__img {{ $isIcon ? 'brandbox__img--icon' : '' }}">
                                        @elseif($isIcon)<img src="{{ asset('assets/brand/favicon-64.png') }}" alt="Default favicon" class="brandbox__img brandbox__img--icon">
                                        @else<x-logo :width="150" />@endif
                                    </div>
                                </div>
                                <p class="brandbox__state"><span x-show="!url">{{ $current ? 'Current '.($isIcon ? 'favicon' : 'logo') : 'Using the default '.($isIcon ? 'favicon' : 'logo') }}</span><span x-show="url" x-cloak>New file: <strong x-text="file"></strong> (saved when you press Save)</span></p>
                                @if($isIcon)
                                    <div class="brandbox__tab" aria-hidden="true">
                                        <img :src="url || '{{ $current ?: asset('assets/brand/favicon-32.png') }}'" alt="" width="16" height="16">
                                        <span>{{ config('abiha.name') }}</span>
                                    </div>
                                @endif
                                <input class="input !pt-2.5" type="file" id="{{ $name }}" name="{{ $name }}" accept="{{ $isIcon ? 'image/png,.ico' : 'image/png,image/jpeg,image/webp' }}"
                                       @change="const f = $event.target.files[0]; if (url) URL.revokeObjectURL(url); url = f && f.type !== '' && f.type.startsWith('image/') ? URL.createObjectURL(f) : null; file = f ? f.name : ''">
                                @if($current)<label class="check"><input type="checkbox" name="remove_{{ $name }}" value="1"> Remove (go back to the default)</label>@endif
                            </div>
                        @else<input class="input" id="{{ $name }}" name="{{ $name }}" type="{{ $type === 'number' ? 'number' : $type }}" @if($type === 'number') step="0.01" min="0" max="100" @endif value="{{ old($name, $values[$key]) }}">@endif
                    @endif
                    @error($name)<span class="error-text">{{ $message }}</span>@elseif($help)<span class="help">{{ $help }}</span>@enderror
                </div>
            @endforeach
            @if(collect($fields)->contains(fn ($f) => $f[1] === 'image'))
                <div class="pt-4 border-t border-line"><button class="btn btn--sm">Save {{ strtolower($heading) }}</button></div>
            @endif
        </section>
    @endforeach
    <button class="btn">Save settings</button>
</form>
@endsection
