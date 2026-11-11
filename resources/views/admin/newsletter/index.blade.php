@extends('admin.layout')
@section('title', 'Newsletter')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="!text-2xl !mb-0">Newsletter <span class="text-grey-text text-base font-normal">({{ $activeCount }} active)</span></h1>
    <a href="{{ route('admin.newsletter.export') }}" class="btn btn--sm btn--outline">Export CSV</a>
</div>
<form method="GET" class="my-4 flex gap-2 max-w-md" role="search"><input name="q" value="{{ $q }}" class="input !min-h-[40px]" placeholder="Search email" aria-label="Search"><button class="btn btn--sm">Search</button></form>
<div class="card !p-0 overflow-x-auto"><table class="table"><thead><tr><th>Email</th><th>Subscribed</th><th>Status</th><th class="num">Actions</th></tr></thead><tbody>
    @forelse($subscribers as $s)
        <tr><td>{{ $s->email }}</td><td>{{ $s->subscribed_at?->format('j M Y') }}</td>
            <td><span class="badge {{ $s->unsubscribed_at ? 'badge--ivory' : 'badge--success' }}">{{ $s->unsubscribed_at ? 'Unsubscribed' : 'Active' }}</span></td>
            <td class="num"><form method="POST" action="{{ route('admin.newsletter.destroy', $s) }}" data-confirm="Remove this subscriber?">@csrf @method('DELETE')<button class="underline text-danger">Remove</button></form></td></tr>
    @empty<tr><td colspan="4" class="text-center py-10 muted">No subscribers yet.</td></tr>@endforelse
</tbody></table></div>
<div class="mt-6">{{ $subscribers->links() }}</div>
@endsection
