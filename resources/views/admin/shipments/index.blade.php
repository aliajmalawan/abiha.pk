@extends('admin.layout')
@section('title', 'Shipments')

@section('content')
<h1 class="!text-2xl">Shipments</h1>
<p class="help mt-1">A shipment appears here when you add courier details to an order or mark it shipped. Open the order to change them.</p>

<form method="GET" class="flex flex-wrap gap-3 items-end mt-4" role="search">
    <div class="field"><label class="label text-xs" for="q">Search</label><input class="input" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Order, tracking number or courier"></div>
    <div class="field"><label class="label text-xs" for="status">Status</label>
        <select class="select" id="status" name="status"><option value="">All</option>@foreach(['pending' => 'Pending', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'returned' => 'Returned'] as $k => $l)<option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></div>
    <button class="btn btn--sm !min-h-[48px]">Filter</button>
</form>

<div class="card !p-0 mt-4 overflow-x-auto">
    <table class="table"><thead><tr><th>Order</th><th>Courier</th><th>Tracking</th><th>Status</th><th>Shipped</th><th>Delivered</th></tr></thead><tbody>
        @forelse($shipments as $s)
            <tr>
                <td>@if($s->order)<a class="link" href="{{ route('admin.orders.show', $s->order) }}">{{ $s->order->number }}</a>@else - @endif</td>
                <td>{{ $s->courier ?: '-' }}</td>
                <td>@if($s->tracking_url && $s->tracking_number)<a class="link" href="{{ $s->tracking_url }}" target="_blank" rel="noopener">{{ $s->tracking_number }}</a>@else{{ $s->tracking_number ?: '-' }}@endif</td>
                <td><span class="badge {{ $s->status === 'delivered' ? 'badge--success' : ($s->status === 'shipped' ? 'badge--info' : 'badge--ivory') }}">{{ ucfirst($s->status) }}</span></td>
                <td>{{ $s->shipped_at?->format('j M Y, g:i a') ?: '-' }}</td>
                <td>{{ $s->delivered_at?->format('j M Y, g:i a') ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center py-8 muted">No shipments yet.</td></tr>
        @endforelse
    </tbody></table>
</div>
<div class="mt-4">{{ $shipments->links() }}</div>
@endsection
