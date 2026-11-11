@extends('admin.layout')
@section('title', 'Menus')

@section('content')
<h1 class="!text-2xl">Menus</h1>
<p class="help mt-1 max-w-2xl">Edit the links in the header and the footer. The <strong>Shop</strong> mega menu is built from your categories automatically. Use a site address such as <code>/shop?sale=1</code> or a full <code>https://</code> address.</p>

<form method="POST" action="{{ route('admin.menus.update') }}" class="max-w-3xl space-y-6 mt-4" novalidate>
    @csrf @method('PUT')
    @foreach($lists as $key => $title)
        <section class="card space-y-3" x-data="{ rows: @js(old($key, $rows[$key])), add() { if (this.rows.length < 12) this.rows.push({ label: '', url: '/' }) },
                move(i, by) { const j = i + by; if (j < 0 || j >= this.rows.length) return; [this.rows[i], this.rows[j]] = [this.rows[j], this.rows[i]]; } }">
            <div class="flex items-center justify-between gap-3">
                <h2 class="!text-lg !mb-0">{{ $title }}</h2>
                <button type="button" class="btn btn--sm btn--outline" @click="add()" :disabled="rows.length >= 12">Add link</button>
            </div>
            <template x-for="(r, i) in rows" :key="i">
                <div class="grid gap-2 sm:grid-cols-[1fr_1.4fr_auto] items-start">
                    <div class="field"><label class="label text-xs" :for="'{{ $key }}-l-' + i">Text</label><input class="input !min-h-[44px]" :id="'{{ $key }}-l-' + i" :name="'{{ $key }}[' + i + '][label]'" x-model="r.label" maxlength="40" required></div>
                    <div class="field"><label class="label text-xs" :for="'{{ $key }}-u-' + i">Address</label><input class="input !min-h-[44px]" :id="'{{ $key }}-u-' + i" :name="'{{ $key }}[' + i + '][url]'" x-model="r.url" maxlength="255" required></div>
                    <div class="flex gap-1 sm:mt-6">
                        <button type="button" class="btn btn--sm btn--outline !px-2" @click="move(i, -1)" :disabled="i === 0" :aria-label="'Move ' + r.label + ' up'">&uarr;</button>
                        <button type="button" class="btn btn--sm btn--outline !px-2" @click="move(i, 1)" :disabled="i === rows.length - 1" :aria-label="'Move ' + r.label + ' down'">&darr;</button>
                        <button type="button" class="btn btn--sm btn--outline !px-2 text-danger" @click="rows.splice(i, 1)" :aria-label="'Remove ' + r.label">&times;</button>
                    </div>
                </div>
            </template>
            <p class="muted text-sm" x-show="rows.length === 0">No links: nothing is shown here.</p>
            @foreach($errors->getMessages() as $field => $msgs)@if(str_starts_with($field, $key.'.'))<p class="error-text">{{ $msgs[0] }}</p>@endif @endforeach
            <button type="submit" name="restore" value="{{ $key }}" class="text-sm underline" data-confirm="Restore the default links for this list?">Restore the default links</button>
        </section>
    @endforeach
    <button class="btn">Save menus</button>
</form>
@endsection
