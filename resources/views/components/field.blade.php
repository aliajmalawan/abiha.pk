@props(['name', 'label', 'type' => 'text', 'value' => null, 'required' => false, 'help' => null, 'autocomplete' => null, 'bag' => 'default'])
@php $err = $errors->getBag($bag)->first($name); @endphp
<div {{ $attributes->only('class')->class(['field']) }}>
    <label class="label" for="f-{{ $name }}">{{ $label }} @if($required)<span class="req" aria-hidden="true">*</span>@endif</label>
    <input class="input" id="f-{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $type === 'password' || ! is_scalar($shown = old($name, $value)) ? '' : $shown }}"
           @if($required) required @endif @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
           @if($err) aria-invalid="true" aria-describedby="e-{{ $name }}" @elseif($help) aria-describedby="h-{{ $name }}" @endif
           {{ $attributes->except('class') }}>
    @if($err)<span class="error-text" id="e-{{ $name }}">{{ $err }}</span>@elseif($help)<span class="help" id="h-{{ $name }}">{{ $help }}</span>@endif
</div>
