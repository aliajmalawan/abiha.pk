New order {{ $order->number }}

Total: {{ $cur }} {{ number_format($order->total) }}
Payment: {{ ucwords(str_replace('_', ' ', $order->payment_method)) }} ({{ $order->payment_status_label }})
Customer: {{ $order->shippingAddress?->name }}, {{ $order->shippingAddress?->city }}

Open it: {{ $adminUrl }}
