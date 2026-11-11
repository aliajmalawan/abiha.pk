@extends('admin.layout')
@section('title', 'Activity log')

@section('content')
<h1 class="!text-2xl">Activity log</h1>
<p class="help mb-4">Who changed what. Entries cannot be edited or deleted.</p>
<form method="GET" class="mb-4 flex gap-2 max-w-md" role="search"><input name="q" value="{{ $filters['q'] ?? '' }}" class="input !min-h-[40px]" placeholder="Filter by action, e.g. order or product" aria-label="Filter"><button class="btn btn--sm">Filter</button></form>
<div class="card !p-0 overflow-x-auto"><table class="table"><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Subject</th><th>Details</th><th>IP</th></tr></thead><tbody>
    @forelse($logs as $l)
        <tr><td class="whitespace-nowrap">{{ $l->created_at->format('j M Y, g:i a') }}</td><td>{{ $l->user?->name ?? 'System' }}</td><td><span class="badge badge--ivory">{{ $l->action }}</span></td>
            <td class="text-grey-text">{{ $l->subject_type ? class_basename($l->subject_type).' #'.$l->subject_id : '-' }}</td>
            <td class="text-xs text-grey-text max-w-xs truncate" title="{{ json_encode($l->properties) }}">{{ $l->properties ? \Illuminate\Support\Str::limit(json_encode($l->properties, JSON_UNESCAPED_UNICODE), 80) : '' }}</td><td class="text-xs">{{ $l->ip_address }}</td></tr>
    @empty<tr><td colspan="6" class="text-center py-10 muted">No activity yet.</td></tr>@endforelse
</tbody></table></div>
<div class="mt-6">{{ $logs->links() }}</div>
@endsection
