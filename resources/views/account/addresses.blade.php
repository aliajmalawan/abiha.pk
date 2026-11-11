@extends('account.layout')
@section('title', 'Address book')
@section('heading', 'Address book')

@section('account')
<div class="grid gap-4 md:grid-cols-2">
    @foreach($addresses as $a)
        <div class="card space-y-3" x-data="{ edit: {{ $errors->any() && old('_edit') == $a->id ? 'true' : 'false' }} }">
            <div class="flex items-start justify-between gap-2">
                <p class="font-display font-semibold !mb-0">{{ $a->label ?: 'Address' }} @if($a->is_default)<span class="badge badge--gold ml-1">Default</span>@endif</p>
            </div>
            <address class="not-italic text-sm leading-relaxed" x-show="!edit">{{ $a->name }}<br>{{ $a->address }}<br>{{ $a->city }}, {{ $a->province }} {{ $a->postal_code }}<br>{{ $a->phone }}</address>
            <div class="flex gap-4 text-sm" x-show="!edit">
                <button type="button" class="link" @click="edit = true">Edit</button>
                <form method="POST" action="{{ route('account.addresses.destroy', $a) }}" x-data @submit="$store.confirm.ask($event, { title: 'Remove this address?', message: 'It will be deleted from your address book. Past orders are not affected.', label: 'Remove address' })">@csrf @method('DELETE')<button class="underline text-grey-text">Remove</button></form>
            </div>
            <form x-show="edit" x-cloak method="POST" action="{{ route('account.addresses.update', $a) }}" class="space-y-3" novalidate data-validate>
                @csrf @method('PUT')<input type="hidden" name="_edit" value="{{ $a->id }}">
                @include('account.partials.address-fields', ['a' => $a, 'prefix' => 'e'.$a->id])
                <div class="flex gap-3"><button class="btn btn--sm">Save</button><button type="button" class="btn btn--sm btn--outline" @click="edit = false">Cancel</button></div>
            </form>
        </div>
    @endforeach
</div>

<form method="POST" action="{{ route('account.addresses.store') }}" class="card mt-8 space-y-4 max-w-2xl" novalidate data-validate>
    @csrf
    <h2 class="!text-xl">Add a new address</h2>
    @include('account.partials.address-fields', ['a' => null, 'prefix' => 'new'])
    <button class="btn">Save address</button>
</form>
@endsection
