{{ $brand }}

{{ $headline }}
Order {{ $order->number }} (placed {{ $order->created_at->format('j M Y') }})

@foreach($order->items as $item)
- {{ $item->name }}@if($item->options) ({{ $item->options }})@endif x {{ $item->quantity }}: {{ $cur }} {{ number_format($item->total) }}
@endforeach

Subtotal: {{ $cur }} {{ number_format($order->subtotal) }}
@if($order->discount)Discount: -{{ $cur }} {{ number_format($order->discount) }}
@endif
Shipping ({{ $order->shipping_method_name }}): {{ $order->shipping_cost ? $cur.' '.number_format($order->shipping_cost) : 'Free' }}
Total: {{ $cur }} {{ number_format($order->total) }}

Payment: {{ $paymentLabel }} ({{ $order->payment_status_label }})
@if($order->shipment && $order->shipment->tracking_number)
Courier: {{ $order->shipment->courier }}
Tracking number: {{ $order->shipment->tracking_number }}
@endif
@if($bank)

Bank transfer details (quote {{ $order->number }} as the reference):
{{ $bank }}
@endif

View your order: {{ $orderUrl }}
