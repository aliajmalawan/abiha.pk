@extends('admin.layout')
@section('title', 'Attributes')

@section('content')
<h1 class="!text-2xl">Attributes</h1>
<p class="help mb-4">The options customers choose between (size, colour…). <strong>Size</strong> and <strong>Colour</strong> power the shop filters.</p>
<div class="grid gap-6 lg:grid-cols-[1fr_320px] items-start">
    <div class="space-y-6">
        @foreach($attributes as $a)
            <section class="card">
                <div class="flex items-center justify-between"><h2 class="!text-lg !mb-0">{{ $a->name }} <span class="text-xs text-grey-text font-normal">({{ $a->slug }})</span></h2>
                    @unless(in_array($a->slug, ['size', 'color']))<form method="POST" action="{{ route('admin.attributes.destroy', $a) }}" data-confirm="Delete this attribute?">@csrf @method('DELETE')<button type="submit" class="act act--del"><x-icon name="trash-2" :size="14" /> Delete</button></form>@endunless</div>
                <ul class="flex flex-wrap gap-2 my-4">
                    @foreach($a->values as $v)
                        <li class="inline-flex items-center gap-2 rounded-full border border-line bg-ivory pl-3 pr-1 py-1 text-sm">
                            @if($v->color_hex)<span class="size-4 rounded-full border border-input" style="background:{{ $v->color_hex }}"></span>@endif {{ $v->value }}
                            <form method="POST" action="{{ route('admin.attributes.values.destroy', $v) }}" data-confirm="Remove {{ $v->value }}?">@csrf @method('DELETE')<button class="size-6 grid place-content-center rounded-full hover:bg-white" aria-label="Remove {{ $v->value }}"><x-icon name="x" :size="14" /></button></form>
                        </li>
                    @endforeach
                </ul>
                <form method="POST" action="{{ route('admin.attributes.values.store', $a) }}" class="flex flex-wrap items-end gap-2">@csrf
                    <div class="field"><label class="label text-xs" for="v{{ $a->id }}">New value</label><input class="input !min-h-[40px]" id="v{{ $a->id }}" name="value" required maxlength="60"></div>
                    @if($a->type === 'color')<div class="field"><label class="label text-xs" for="h{{ $a->id }}">Colour</label><input class="input !min-h-[40px] !w-20 !p-1" type="color" id="h{{ $a->id }}" name="color_hex" value="#101010"></div>@endif
                    <button class="btn btn--sm !min-h-[40px]">Add</button>
                </form>
            </section>
        @endforeach
    </div>
    <form method="POST" action="{{ route('admin.attributes.store') }}" class="card space-y-3">@csrf
        <h2 class="!text-lg">New attribute</h2>
        <x-field name="name" label="Name" required placeholder="e.g. Material" />
        <x-field name="slug" label="Identifier" required placeholder="e.g. material" help="Lowercase letters, numbers, - or _." />
        <div class="field"><label class="label" for="type">Type</label><select class="select" id="type" name="type"><option value="select">List of options</option><option value="color">Colour swatches</option></select></div>
        <button class="btn btn--block">Add attribute</button>
    </form>
</div>
@endsection
