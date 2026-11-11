@extends('admin.layout')
@section('title', 'Stock ledger')

@section('content')
<a href="{{ route('admin.inventory.index') }}" class="link text-sm inline-flex items-center gap-1 mb-4"><x-icon name="chevron-left" :size="14" /> Inventory</a>
<div class="flex flex-wrap items-center justify-between gap-3"><h1 class="!text-2xl !mb-0">Stock ledger</h1><a href="{{ route('admin.inventory.ledger.export') }}" class="btn btn--sm btn--outline">Export CSV</a></div>
<p class="help mb-4">Every stock change, newest first. Entries cannot be edited or deleted.</p>

<form method="GET" class="mb-4 flex flex-wrap gap-2" role="search">
    <input name="q" value="{{ $filters['q'] ?? '' }}" class="input !min-h-[40px] max-w-xs" placeholder="Search product" aria-label="Search">
    <select name="reason" class="select !min-h-[40px] !w-auto" aria-label="Reason"><option value="">All reasons</option>@foreach($reasons as $r)<option value="{{ $r }}" @selected(($filters['reason'] ?? '') === $r)>{{ ucfirst($r) }}</option>@endforeach</select>
    <button class="btn btn--sm">Filter</button>
</form>

<div class="card !p-0 overflow-x-auto">
    <table class="table">
        <thead><tr><th>When</th><th>Item</th><th class="num">Change</th><th class="num">Balance</th><th>Reason</th><th>By</th><th>Note</th></tr></thead>
        <tbody>
        @forelse($rows as $m)
            <tr>
                <td class="whitespace-nowrap">{{ $m->created_at->format('j M Y, g:i a') }}</td>
                <td>{{ $m->product?->name }} @if($m->variant)<span class="text-grey-text text-xs">· {{ $m->variant->sku }}</span>@endif</td>
                <td class="num font-semibold {{ $m->change < 0 ? 'text-danger' : 'text-success' }}">{{ $m->change > 0 ? '+' : '' }}{{ $m->change }}</td>
                <td class="num">{{ $m->balance_after }}</td>
                <td><span class="badge badge--ivory">{{ ucfirst($m->reason) }}</span></td>
                <td>{{ $m->user?->name ?? 'System' }}</td>
                <td class="text-grey-text">{{ $m->note }}</td>
            </tr>
        @empty<tr><td colspan="7" class="text-center py-10 muted">No movements recorded.</td></tr>@endforelse
        </tbody>
    </table>
</div>
<div class="mt-6">{{ $rows->links() }}</div>
@endsection
