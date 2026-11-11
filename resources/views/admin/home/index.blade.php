@extends('admin.layout')
@section('title', 'Homepage')

@section('content')
<h1 class="!text-2xl">Homepage layout</h1>
<p class="help mb-4">Every block on the homepage is managed here: turn blocks on or off, set their order (lowest number first), edit their text and options, add new blocks and remove ones you do not want. Banner images (hero, main campaign, promotional banner) are edited under <a class="link" href="{{ route('admin.banners.index') }}">Banners</a>; testimonials under <a class="link" href="{{ route('admin.testimonials.index') }}">Testimonials</a>; the announcement bar, contact details, social links and footer text under <a class="link" href="{{ route('admin.settings.edit') }}">Settings</a>.</p>

<form method="POST" action="{{ route('admin.home.reorder') }}" class="card !p-0 overflow-x-auto">@csrf @method('PATCH')
    <table class="table"><thead><tr><th>Block</th><th>Heading</th><th class="w-28">Order</th><th>Visible</th><th class="num">Edit</th><th class="num">Remove</th></tr></thead><tbody>
        @forelse($sections as $s)
            <tr><td>{{ $types[$s->type] ?? $s->type }}@if($s->type === 'products' && $s->setting('source')) <span class="text-grey-text text-xs">({{ $s->setting('source') }})</span>@endif</td><td>{{ $s->title ?: '-' }}</td>
                <td><input class="input !min-h-[36px] !w-20" type="number" min="0" max="999" name="sections[{{ $s->id }}][sort_order]" value="{{ $s->sort_order }}" aria-label="Order for {{ $s->title ?: ($types[$s->type] ?? $s->type) }}"></td>
                <td><label class="check"><input type="checkbox" name="sections[{{ $s->id }}][is_active]" value="1" @checked($s->is_active)> Show</label></td>
                <td class="num">@unless(in_array($s->type, $single))<a class="act act--edit" href="{{ route('admin.home.edit', $s) }}"><x-icon name="pencil" :size="14" /> Edit</a>@else<span class="text-grey-text text-xs">via Banners</span>@endunless</td>
                <td class="num"><button type="submit" form="remove-{{ $s->id }}" class="underline text-danger" data-confirm="Remove this block from the homepage?">Remove</button></td></tr>
        @empty
            <tr><td colspan="6" class="text-grey-text text-center py-6">The homepage has no blocks yet. Add one below.</td></tr>
        @endforelse
    </tbody></table>
    <div class="p-4"><button class="btn btn--sm">Save layout</button></div>
</form>
@foreach($sections as $s)
    <form id="remove-{{ $s->id }}" method="POST" action="{{ route('admin.home.destroy', $s) }}" class="hidden" data-confirm="Remove this block from the homepage?">@csrf @method('DELETE')</form>
@endforeach

<form method="POST" action="{{ route('admin.home.store') }}" class="card mt-6 max-w-2xl space-y-4" novalidate>@csrf
    <h2 class="!text-lg !mb-0">Add a block</h2>
    <p class="help">New blocks start hidden at the bottom, so the live page does not change until you tick Show. You can add as many product rows as you like (new arrivals, best sellers, trending, one per collection…). The hero, main campaign and promotional banner can each appear once.</p>
    <div class="field"><label class="label" for="type">Type of block</label>
        <select class="select" id="type" name="type">@foreach($types as $k => $l)<option value="{{ $k }}" @selected(old('type') === $k)>{{ $l }}</option>@endforeach</select>
        @error('type')<span class="error-text" role="alert">{{ $message }}</span>@enderror</div>
    <x-field name="title" label="Heading (optional)" />
    <button class="btn btn--sm">Add block</button>
</form>
@endsection
