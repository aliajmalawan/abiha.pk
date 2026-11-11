@extends('admin.layout')
@section('title', 'Message')

@section('content')
<a href="{{ route('admin.messages.index') }}" class="link text-sm inline-flex items-center gap-1 mb-4"><x-icon name="chevron-left" :size="14" /> Messages</a>
<article class="card max-w-3xl">
    <h1 class="!text-xl">{{ $message->subject ?: 'Message from '.$message->name }}</h1>
    <p class="text-sm text-grey-text">{{ $message->name }} · <a class="link" href="mailto:{{ $message->email }}">{{ $message->email }}</a>@if($message->phone) · {{ $message->phone }}@endif · {{ $message->created_at->format('j M Y, g:i a') }}</p>
    {{-- Plain text only: customer input is never rendered as HTML --}}
    <div class="mt-4 whitespace-pre-wrap text-sm leading-relaxed">{{ $message->message }}</div>
    <div class="flex flex-wrap gap-3 mt-6">
        <a class="btn btn--sm" href="mailto:{{ $message->email }}?subject={{ rawurlencode('Re: '.($message->subject ?: 'Your message')) }}">Reply by email</a>
        <form method="POST" action="{{ route('admin.messages.update', $message) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="replied"><button class="btn btn--sm btn--outline">Mark as replied</button></form>
        <form method="POST" action="{{ route('admin.messages.destroy', $message) }}" data-confirm="Delete this message?">@csrf @method('DELETE')<button class="btn btn--sm btn--ghost text-danger">Delete</button></form>
    </div>
</article>
@endsection
