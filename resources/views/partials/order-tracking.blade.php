@php
    $cur = config('abiha.currency.symbol');
    $steps = ['pending' => 'Placed', 'confirmed' => 'Confirmed', 'processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered'];
    $keys = array_keys($steps);
    $currentIdx = array_search($order->status, $keys, true);
@endphp
<section class="mt-10 space-y-6" aria-label="Order {{ $order->number }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="!text-xl !mb-0">Order {{ $order->number }}</h2>
        <div class="flex gap-2"><span class="badge badge--{{ $order->status_tone }}">{{ $order->status_label }}</span><span class="badge badge--ivory">{{ $order->payment_status_label }}</span></div>
    </div>

    @if($order->status === 'cancelled')
        <div class="alert alert--danger">This order was cancelled.</div>
    @elseif($order->status === 'returned')
        <div class="alert alert--warning">This order was returned.@if($order->payment_status === 'refunded') Your refund has been issued.@endif</div>
    @else
        <ol class="grid grid-cols-5 gap-1 text-center text-[11px] sm:text-xs" aria-label="Progress">
            @foreach($steps as $k => $label)
                @php $done = $currentIdx !== false && array_search($k, $keys, true) <= $currentIdx; @endphp
                <li><span class="mx-auto mb-2 grid size-8 place-content-center rounded-full border-2 {{ $done ? 'bg-ink border-ink text-champagne' : 'border-line text-grey-text' }}">@if($done)<x-icon name="check" :size="15" />@endif</span>
                    <span class="{{ $done ? 'font-semibold' : 'text-grey-text' }}">{{ $label }}</span></li>
            @endforeach
        </ol>
    @endif

    @if($order->shipping_eta && ! in_array($order->status, ['delivered', 'cancelled', 'returned'], true))
        <p class="text-sm">Estimated delivery: <strong>{{ $order->shipping_eta }}</strong> <span class="text-grey-text">({{ $order->shipping_method_name }}, an estimate, not a guarantee)</span></p>
    @endif
    @if($order->tracking_number)
        <p class="text-sm">@if($order->courier){{ $order->courier }} · @endif Tracking number: <strong>{{ $order->tracking_number }}</strong>@if($order->tracking_url) · <a class="link" href="{{ $order->tracking_url }}" rel="noopener" target="_blank">Track with courier</a>@endif</p>
    @endif

    <ul class="divide-y divide-line border-y border-line">
        @foreach($order->items as $item)
            <li class="py-3 flex justify-between gap-4 text-sm"><span>{{ $item->name }} @if($item->options)<span class="text-grey-text">· {{ $item->options }}</span>@endif × {{ $item->quantity }}</span><span class="font-semibold">{{ $cur }} {{ number_format($item->total) }}</span></li>
        @endforeach
    </ul>
    <p class="text-right font-display font-bold">Total: {{ $cur }} {{ number_format($order->total) }}</p>

    @if($order->history->isNotEmpty())
        <div>
            <h3 class="eyebrow mb-3">Updates</h3>
            <ol class="space-y-3 text-sm border-l-2 border-line pl-4">
                @foreach($order->history as $h)
                    <li><span class="font-semibold">{{ \App\Models\Order::STATUSES[$h->status] ?? ucfirst($h->status) }}</span> <span class="text-grey-text">· {{ $h->created_at->format('j M Y, g:i a') }}</span>@if($h->note)<br><span class="text-grey-text">{{ $h->note }}</span>@endif</li>
                @endforeach
            </ol>
        </div>
    @endif
</section>
