@extends('admin.layout')
@section('title', 'Messages')

@section('content')
<h1 class="!text-2xl">Contact messages</h1>
<div class="flex gap-2 my-4 text-sm">
    <a href="{{ route('admin.messages.index') }}" class="badge {{ ! $status ? '' : 'badge--ivory' }} no-underline">All</a>
    @foreach(['new' => 'New', 'read' => 'Read', 'replied' => 'Replied'] as $k => $l)<a href="{{ route('admin.messages.index', ['status' => $k]) }}" class="badge {{ $status === $k ? '' : 'badge--ivory' }} no-underline">{{ $l }}</a>@endforeach
</div>
<div class="card !p-0 overflow-x-auto"><table class="table"><thead><tr><th>From</th><th>Subject</th><th>Received</th><th>Status</th></tr></thead><tbody>
    @forelse($messages as $m)
        <tr><td><a class="link {{ $m->status === 'new' ? 'font-bold' : '' }}" href="{{ route('admin.messages.show', $m) }}">{{ $m->name }}</a><br><span class="text-xs text-grey-text">{{ $m->email }}</span></td>
            <td>{{ \Illuminate\Support\Str::limit($m->subject ?: $m->message, 60) }}</td><td>{{ $m->created_at->format('j M Y, g:i a') }}</td>
            <td><span class="badge {{ $m->status === 'new' ? 'badge--warning' : ($m->status === 'replied' ? 'badge--success' : 'badge--ivory') }}">{{ ucfirst($m->status) }}</span></td></tr>
    @empty<tr><td colspan="4" class="text-center py-10 muted">No messages.</td></tr>@endforelse
</tbody></table></div>
<div class="mt-6">{{ $messages->links() }}</div>
@endsection
