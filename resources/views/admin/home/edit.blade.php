@extends('admin.layout')
@section('title', 'Edit section')

@section('content')
<a href="{{ route('admin.home.index') }}" class="link text-sm inline-flex items-center gap-1 mb-4"><x-icon name="chevron-left" :size="14" /> Homepage</a>
<h1 class="!text-2xl">{{ $types[$section->type] ?? $section->type }}</h1>
<form method="POST" action="{{ route('admin.home.update', $section) }}" class="card mt-4 max-w-2xl space-y-4" enctype="multipart/form-data" novalidate>
    @csrf @method('PUT')
    <x-field name="title" label="Heading" :value="$section->title" />
    <x-field name="subtitle" label="Small text / subheading" :value="$section->subtitle" />
    @if(in_array($section->type, ['categories', 'products', 'collections', 'testimonials']))
        <x-field name="limit" label="How many to show (1-12)" type="number" min="1" max="12" :value="$section->setting('limit', 4)" />
    @endif
    @if($section->type === 'products')
        <div class="field"><label class="label" for="source">Which products</label>
            <select class="select" id="source" name="source">@foreach($sources as $k => $l)<option value="{{ $k }}" @selected(old('source', $section->setting('source')) === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="field"><label class="label" for="collection">Collection (when "A specific collection" is chosen)</label>
            <select class="select" id="collection" name="collection"><option value="">None</option>@foreach($collections as $slug => $name)<option value="{{ $slug }}" @selected(old('collection', $section->setting('collection')) === $slug)>{{ $name }}</option>@endforeach</select></div>
        <div class="field"><label class="label" for="layout">Layout</label>
            <select class="select" id="layout" name="layout">@foreach($layouts as $k => $l)<option value="{{ $k }}" @selected(old('layout', $section->setting('layout', 'carousel')) === $k)>{{ $l }}</option>@endforeach</select></div>
        <label class="check"><input type="checkbox" name="tabs" value="1" @checked(old('tabs', $section->setting('tabs', false)))> Show tabs for the collections (each tab lists this block's products from one collection)</label>
        <x-field name="link" label="&quot;View all&quot; link" :value="$section->setting('link')" help="Site path like /shop?sort=newest. Blank hides the link." />
    @endif
    @if($section->type === 'brand')
        <div class="field"><label class="label" for="body">Brand introduction text</label><textarea class="textarea" id="body" name="body" rows="5">{{ old('body', $section->setting('body')) }}</textarea>@error('body')<span class="error-text">{{ $message }}</span>@enderror</div>
        <x-field name="cta_label" label="Button text" :value="$section->setting('cta_label')" />
        <x-field name="cta_url" label="Button link" :value="$section->setting('cta_url')" />
        <div class="field"><label class="label" for="image">Brand image</label>
            @if($section->setting('image'))<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($section->setting('image')) }}" alt="" class="h-24 rounded border border-line mb-2"><label class="check mb-2"><input type="checkbox" name="remove_image" value="1"> Remove the current image</label>@endif
            <input class="input !pt-2.5" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp"><span class="help">JPG, PNG or WebP, up to 4 MB. Without an image a neutral placeholder is shown.</span>@error('image')<span class="error-text" role="alert">{{ $message }}</span>@enderror</div>
    @endif
    @if($section->type === 'social')
        <div class="field"><span class="label">Pictures (up to 6)</span>
            @php $pics = array_values((array) $section->setting('images', [])); @endphp
            @if($pics)<div class="grid grid-cols-3 sm:grid-cols-6 gap-2 mb-2">@foreach($pics as $i => $path)<label class="block text-center text-xs"><img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($path) }}" alt="" class="aspect-square object-cover rounded border border-line w-full"><span class="check justify-center mt-1"><input type="checkbox" name="remove_images[]" value="{{ $i }}"> Remove</span></label>@endforeach</div>@endif
            <input class="input !pt-2.5" type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp" aria-label="Add pictures"><span class="help">Upload your own photos: JPG, PNG or WebP, up to 4 MB each, at most 6 photos. The Instagram link comes from Settings → Social links; the pictures link to it.</span>@error('images.*')<span class="error-text" role="alert">{{ $message }}</span>@enderror</div>
    @endif
    <div class="flex gap-3"><button class="btn">Save</button><a href="{{ route('admin.home.index') }}" class="btn btn--outline">Cancel</a></div>
</form>
@endsection
