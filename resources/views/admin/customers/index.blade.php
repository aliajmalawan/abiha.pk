@extends('admin.layout')
@section('title', 'Customers')

@section('content')
@php $cur = config('abiha.currency.symbol'); @endphp
<div class="flex flex-wrap items-center justify-between gap-3"><h1 class="!text-2xl !mb-0">Customers</h1><a href="{{ route('admin.customers.export') }}" class="btn btn--sm btn--outline">Export CSV</a></div>
<form method="GET" class="my-4 flex flex-wrap gap-2" role="search">
    <input name="q" value="{{ $filters['q'] ?? '' }}" class="input !min-h-[40px] max-w-xs" placeholder="Name, email or phone" aria-label="Search">
    <select name="status" class="select !min-h-[40px] !w-auto" aria-label="Status"><option value="">All</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option><option value="blocked" @selected(($filters['status'] ?? '') === 'blocked')>Blocked</option></select>
    <button class="btn btn--sm">Filter</button>
</form>
<div class="card !p-0 overflow-x-auto">
    <table class="table"><thead><tr><th>Customer</th><th>Phone</th><th>Joined</th><th class="num">Orders</th><th class="num">Spent</th><th>Status</th></tr></thead><tbody>
        @forelse($customers as $c)
            <tr><td><a class="link" href="{{ route('admin.customers.show', $c->id) }}">{{ $c->name }}</a><br><span class="text-xs text-grey-text">{{ $c->email }}</span></td><td>{{ $c->phone ?? '-' }}</td>
                <td>{{ $c->created_at->format('j M Y') }}</td><td class="num">{{ $c->orders_count }}</td><td class="num">{{ $cur }} {{ number_format((int) $c->spent) }}</td>
                <td><span class="badge {{ $c->is_active ? 'badge--success' : 'badge--danger' }}">{{ $c->is_active ? 'Active' : 'Blocked' }}</span></td></tr>
        @empty<tr><td colspan="6" class="text-center py-10 muted">No customers found.</td></tr>@endforelse
    </tbody></table>
</div>
<div class="mt-6">{{ $customers->links() }}</div>
@endsection
