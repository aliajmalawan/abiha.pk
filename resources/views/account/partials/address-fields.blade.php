@php $editing = $a && old('_edit') == $a->id; $v = fn ($k) => $editing ? old($k) : ($a?->{$k} ?? ($a ? null : old($k))); @endphp
<div class="grid gap-3 sm:grid-cols-2">
    @foreach([['label', 'Label (e.g. Home)', 'text'], ['name', 'Full name', 'text'], ['phone', 'Phone', 'tel']] as [$k, $lbl, $type])
        <div class="field {{ $k === 'name' ? 'sm:col-span-1' : '' }}">
            <label class="label" for="{{ $prefix.$k }}">{{ $lbl }}</label>
            <input class="input" id="{{ $prefix.$k }}" name="{{ $k }}" type="{{ $type }}" value="{{ $v($k) }}" @if($k !== 'label') required @endif @error($k) @if($editing || ! $a) aria-invalid="true" @endif @enderror>
            @if($editing || ! $a)@error($k)<span class="error-text">{{ $message }}</span>@enderror @endif
        </div>
    @endforeach
    <div class="field">
        <label class="label" for="{{ $prefix }}province">Province</label>
        <select class="select" id="{{ $prefix }}province" name="province" required>
            <option value="">Select</option>
            @foreach(config('abiha.provinces') as $pv)<option value="{{ $pv }}" @selected($v('province') === $pv)>{{ $pv }}</option>@endforeach
        </select>
        @if($editing || ! $a)@error('province')<span class="error-text">{{ $message }}</span>@enderror @endif
    </div>
    <div class="field"><label class="label" for="{{ $prefix }}city">City</label><input class="input" id="{{ $prefix }}city" name="city" value="{{ $v('city') }}" required>@if($editing || ! $a)@error('city')<span class="error-text">{{ $message }}</span>@enderror @endif</div>
    <div class="field"><label class="label" for="{{ $prefix }}postal">Postal code</label><input class="input" id="{{ $prefix }}postal" name="postal_code" value="{{ $v('postal_code') }}"></div>
    <div class="field sm:col-span-2"><label class="label" for="{{ $prefix }}address">Complete address</label><textarea class="textarea !min-h-[80px]" id="{{ $prefix }}address" name="address" required>{{ $v('address') }}</textarea>@if($editing || ! $a)@error('address')<span class="error-text">{{ $message }}</span>@enderror @endif</div>
</div>
<label class="check"><input type="checkbox" name="is_default" value="1" @checked($a ? $a->is_default : old('is_default'))> Make this my default address</label>
