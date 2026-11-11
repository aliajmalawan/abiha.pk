@extends('admin.layout')
@section('title', 'Alerts')

@section('content')
<div class="flex items-center justify-between gap-3">
    <h1 class="!text-2xl">Alerts</h1>
    @if(auth()->user()->unreadNotifications()->exists())
        <form method="POST" action="{{ route('admin.notifications.read-all') }}">@csrf<button class="btn btn--outline btn--sm">Mark all as read</button></form>
    @endif
</div>

<div class="card !p-0 mt-4 max-w-3xl divide-y divide-line">
    @forelse($notifications as $n)
        <form method="POST" action="{{ route('admin.notifications.read', $n->id) }}">
            @csrf
            <button class="w-full text-left p-4 flex gap-3 hover:bg-ivory" @if($n->read_at === null) aria-label="Unread: {{ $n->data['title'] ?? '' }}" @endif>
                <span class="mt-1.5 size-2 shrink-0 rounded-full {{ $n->read_at ? 'bg-line' : 'bg-gold' }}" aria-hidden="true"></span>
                <span class="flex-1">
                    <span class="block {{ $n->read_at ? '' : 'font-semibold' }}">{{ $n->data['title'] ?? 'Alert' }}</span>
                    <span class="block text-sm text-grey-text">{{ $n->data['body'] ?? '' }}</span>
                    <span class="block text-xs text-grey-text mt-1">{{ $n->created_at->diffForHumans() }}</span>
                </span>
            </button>
        </form>
    @empty
        <p class="p-6 muted text-sm">No alerts yet. New orders and bank transfers waiting to be verified will appear here.</p>
    @endforelse
</div>
<div class="mt-4">{{ $notifications->links() }}</div>
@endsection
