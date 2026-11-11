@extends('admin.layout')
@section('title', ($model->exists ? 'Edit ' : 'New ').strtolower($c['label']))

@section('content')
<a href="{{ route($c['route'].'.index') }}" class="link text-sm inline-flex items-center gap-1 mb-4"><x-icon name="chevron-left" :size="14" /> {{ $c['plural'] }}</a>
<h1 class="!text-2xl">{{ $model->exists ? 'Edit' : 'New' }} {{ strtolower($c['label']) }}</h1>

<form method="POST" enctype="multipart/form-data" novalidate
      action="{{ $model->exists ? route($c['route'].'.update', $model->getKey()) : route($c['route'].'.store') }}" class="card mt-4 max-w-3xl space-y-5">
    @csrf
    @if($model->exists) @method('PUT') @endif

    @foreach($c['fields'] as $f)
        @php
            $name = $f['name']; $type = $f['type'] ?? 'text'; $err = $errors->first($name);
            $val = old($name, $type === 'datetime' && $model->{$name} ? $model->{$name}->format('Y-m-d\TH:i') : $model->{$name});
            $opts = isset($f['options']) ? (is_callable($f['options']) ? ($f['options'])($model) : $f['options']) : [];
        @endphp
        <div class="field">
            @if($type === 'checkbox')
                <label class="check"><input type="checkbox" name="{{ $name }}" value="1" @checked(old($name, $model->{$name}))> {{ $f['label'] }}</label>
            @else
                <label class="label" for="f-{{ $name }}">{{ $f['label'] }}</label>
                @switch($type)
                    @case('textarea')<textarea class="textarea" id="f-{{ $name }}" name="{{ $name }}" rows="{{ $f['rows'] ?? 4 }}" @if($err) aria-invalid="true" @endif>{{ $val }}</textarea>@break
                    @case('select')
                        <select class="select" id="f-{{ $name }}" name="{{ $name }}" @if($err) aria-invalid="true" @endif>
                            @if(isset($f['blank']))<option value="">{{ $f['blank'] }}</option>@endif
                            @foreach($opts as $k => $label)<option value="{{ $k }}" @selected((string) $val === (string) $k)>{{ $label }}</option>@endforeach
                        </select>@break
                    @case('multiselect')
                        @php $sel = old($name, $selected[$name] ?? []); @endphp
                        <select class="select !h-56" id="f-{{ $name }}" name="{{ $name }}[]" multiple>
                            @foreach($opts as $k => $label)<option value="{{ $k }}" @selected(in_array($k, $sel))>{{ $label }}</option>@endforeach
                        </select><span class="help">Hold Ctrl (Cmd on Mac) to select several.</span>@break
                    @case('image')
                        @if($model->{$name})<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($model->{$name}) }}" alt="" class="h-24 rounded border border-line object-cover mb-2">
                            <label class="check"><input type="checkbox" name="remove_{{ $name }}" value="1"> Remove current image</label>@endif
                        <input class="input !pt-2.5" type="file" id="f-{{ $name }}" name="{{ $name }}" accept="image/jpeg,image/png,image/webp">@break
                    @default
                        <input class="input" type="{{ $type === 'datetime' ? 'datetime-local' : $type }}" id="f-{{ $name }}" name="{{ $name }}" value="{{ $val }}" @if($err) aria-invalid="true" @endif>
                @endswitch
            @endif
            @if($err)<span class="error-text">{{ $err }}</span>@elseif(! empty($f['help']))<span class="help">{{ $f['help'] }}</span>@endif
        </div>
    @endforeach

    <div class="flex gap-3"><button class="btn">Save</button><a href="{{ route($c['route'].'.index') }}" class="btn btn--outline">Cancel</a></div>
</form>
@if(! empty($c['partial']) && $model->exists)@include($c['partial'], ['model' => $model])@endif
@endsection
