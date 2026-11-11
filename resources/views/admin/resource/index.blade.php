@extends('admin.layout')
@section('title', $c['plural'])

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <h1 class="!text-2xl !mb-0">{{ $c['plural'] }}</h1>
    <a href="{{ route($c['route'].'.create') }}" class="btn btn--sm">Add {{ strtolower($c['label']) }}</a>
</div>

@if(! empty($c['search']))
    <form method="GET" class="mb-4 flex gap-2 max-w-md" role="search">
        <label for="q" class="sr-only">Search</label>
        <input id="q" name="q" value="{{ $term }}" class="input !min-h-[40px]" placeholder="Search {{ strtolower($c['plural']) }}">
        <button class="btn btn--sm">Search</button>
    </form>
@endif

<div class="card !p-0 overflow-x-auto">
    <table class="table">
        <thead><tr>@foreach($c['columns'] as $col)<th>{{ $col[1] }}</th>@endforeach<th class="num">Actions</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr>
                @foreach($c['columns'] as $col)
                    @php $v = data_get($row, $col[0]); $type = $col[2] ?? 'text'; @endphp
                    <td>
                        @if($type === 'bool')<span class="badge {{ $v ? 'badge--success' : 'badge--ivory' }}">{{ $v ? 'Yes' : 'No' }}</span>
                        @elseif($type === 'money'){{ $v === null ? '-' : number_format($v) }}
                        @elseif($type === 'date'){{ $v ? \Illuminate\Support\Carbon::parse($v)->format('j M Y') : '-' }}
                        @else{{ $v ?? '-' }}@endif
                    </td>
                @endforeach
                <td class="num whitespace-nowrap">
                    <span class="act-group"><a class="act act--edit" href="{{ route($c['route'].'.edit', $row->getKey()) }}"><x-icon name="pencil" :size="14" /> Edit</a>
                    <form method="POST" action="{{ route($c['route'].'.destroy', $row->getKey()) }}" class="inline" data-confirm="Delete this {{ strtolower($c['label']) }}?">@csrf @method('DELETE')
                        <button type="submit" class="act act--del"><x-icon name="trash-2" :size="14" /> Delete</button></form></span>
                </td>
            </tr>
        @empty
            <tr><td colspan="{{ count($c['columns']) + 1 }}" class="text-center py-10 muted">Nothing here yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-6">{{ $rows->links() }}</div>
@endsection
