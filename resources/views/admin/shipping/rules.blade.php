{{-- Included under the shipping-method edit form (existing methods only) --}}
@php $cur = config('abiha.currency.symbol'); @endphp
<section class="card mt-6 max-w-3xl space-y-4" aria-labelledby="rules-h">
    <h2 class="!text-lg !mb-0" id="rules-h">City &amp; province rules</h2>
    <p class="help">Charge a different rate, or turn this method off, for specific destinations. The most specific rule wins: <strong>city</strong>, then <strong>province</strong>, then the default rate above. Matching ignores upper/lower case.</p>

    @if($model->rules->isNotEmpty())
        <div class="overflow-x-auto"><table class="table"><thead><tr><th>Destination</th><th class="num">Cost</th><th class="num">Free over</th><th>Delivery time</th><th>Available</th><th class="num"></th></tr></thead><tbody>
            @foreach($model->rules as $r)
                <tr><td>{{ $r->destination }}</td><td class="num">{{ $r->is_available ? $cur.' '.number_format($r->cost) : '-' }}</td>
                    <td class="num">{{ $r->free_over !== null ? number_format($r->free_over) : 'default' }}</td>
                    <td>{{ $r->eta ?: 'default' }}</td>
                    <td><span class="badge {{ $r->is_available ? 'badge--success' : 'badge--danger' }}">{{ $r->is_available ? 'Yes' : 'Not offered' }}</span></td>
                    <td class="num"><form method="POST" action="{{ route('admin.shipping.rules.destroy', $r) }}" data-confirm="Remove this rule?">@csrf @method('DELETE')<button class="underline text-danger">Remove</button></form></td></tr>
            @endforeach
        </tbody></table></div>
    @else
        <p class="muted text-sm">No rules yet: every destination uses the default rate.</p>
    @endif

    <form method="POST" action="{{ route('admin.shipping.rules.store', $model->id) }}" class="grid gap-3 sm:grid-cols-2 border-t border-line pt-4" novalidate>
        @csrf
        <div class="field"><label class="label text-xs" for="r-province">Province</label>
            <select class="select" id="r-province" name="province"><option value="">Any</option>@foreach(config('abiha.provinces') as $pv)<option value="{{ $pv }}" @selected(old('province') === $pv)>{{ $pv }}</option>@endforeach</select>@error('province')<span class="error-text">{{ $message }}</span>@enderror</div>
        <div class="field"><label class="label text-xs" for="r-city">City</label><input class="input" id="r-city" name="city" value="{{ old('city') }}" placeholder="e.g. Lahore">@error('city')<span class="error-text">{{ $message }}</span>@enderror</div>
        <div class="field"><label class="label text-xs" for="r-cost">Cost for this destination (PKR)</label><input class="input" type="number" min="0" id="r-cost" name="cost" value="{{ old('cost') }}" required>@error('cost')<span class="error-text">{{ $message }}</span>@enderror</div>
        <div class="field"><label class="label text-xs" for="r-free">Free over (PKR, optional)</label><input class="input" type="number" min="0" id="r-free" name="free_over" value="{{ old('free_over') }}"><span class="help">Blank = use the method's threshold.</span></div>
        <div class="field sm:col-span-2"><label class="label text-xs" for="r-eta">Delivery time for this destination (optional)</label><input class="input" id="r-eta" name="eta" maxlength="80" value="{{ old('eta') }}" placeholder="e.g. 2-3 working days">@error('eta')<span class="error-text">{{ $message }}</span>@enderror<span class="help">Blank = use the method's estimate.</span></div>
        <label class="check sm:col-span-2"><input type="checkbox" name="is_available" value="1" checked> This method is offered to this destination (untick to block it)</label>
        <div class="sm:col-span-2"><button class="btn btn--sm">Add rule</button></div>
    </form>
</section>
