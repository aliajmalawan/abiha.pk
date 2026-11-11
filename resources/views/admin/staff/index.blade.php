@extends('admin.layout')
@section('title', 'Staff & roles')

@section('content')
<h1 class="!text-2xl">Staff {{ '&' }} roles</h1>
<div class="grid gap-6 lg:grid-cols-[1fr_340px] mt-4 items-start">
    <section class="card !p-0 overflow-x-auto">
        <table class="table"><thead><tr><th>Person</th><th>Role</th><th>Status</th></tr></thead><tbody>
            @foreach($staff as $u)
                <tr><td>{{ $u->name }}<br><span class="text-xs text-grey-text">{{ $u->email }}</span></td>
                    <td>@if($u->id === auth()->id())<span class="badge">{{ $u->role->name }}</span> <span class="text-xs text-grey-text">(you)</span>
                        @else<form method="POST" action="{{ route('admin.staff.update', $u) }}" class="flex gap-2">@csrf @method('PATCH')
                            <select name="role_id" class="select !min-h-[36px] !w-auto text-sm" aria-label="Role for {{ $u->name }}">
                                @foreach(\App\Models\Role::orderBy('id')->get() as $r)<option value="{{ $r->id }}" @selected($u->role_id === $r->id)>{{ $r->name }}{{ $r->is_staff ? '' : ' (revoke access)' }}</option>@endforeach</select>
                            <button class="btn btn--sm !min-h-[36px]">Save</button></form>@endif</td>
                    <td><span class="badge {{ $u->is_active ? 'badge--success' : 'badge--danger' }}">{{ $u->is_active ? 'Active' : 'Blocked' }}</span></td></tr>
            @endforeach
        </tbody></table>
    </section>
    <aside class="space-y-6">
        <form method="POST" action="{{ route('admin.staff.grant') }}" class="card space-y-3">@csrf
            <h2 class="!text-lg">Give someone access</h2>
            <p class="help">They must already have an account on the website.</p>
            <x-field name="email" label="Their account email" type="email" required />
            <div class="field"><label class="label" for="role_id">Role</label><select class="select" id="role_id" name="role_id">@foreach($roles as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select></div>
            <button class="btn btn--block">Grant access</button>
        </form>
        <section class="card text-sm"><h2 class="!text-lg">What each role can do</h2>
            @foreach($roles as $r)<details class="mb-2"><summary class="font-semibold cursor-pointer">{{ $r->name }}</summary>
                <p class="text-grey-text mt-1">{{ $r->description }}</p>
                <ul class="list-disc pl-5 mt-1">@if($r->slug === 'admin')<li>Everything</li>@else @foreach($r->permissions as $p)<li>{{ $p->name }}</li>@endforeach @endif</ul></details>@endforeach
        </section>
    </aside>
</div>
@endsection
