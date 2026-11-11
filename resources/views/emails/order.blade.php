<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $brand }}</title></head>
<body style="margin:0;padding:0;background:#F8F6F1;font-family:Arial,Helvetica,sans-serif;color:#101010;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F8F6F1;padding:24px 12px;">
<tr><td align="center">
  <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#FFFFFF;border:1px solid #EAEAEA;">
    <tr><td align="center" style="padding:24px 24px 8px;"><img src="{{ $logo }}" alt="{{ $brand }}" width="120" style="display:block;border:0;max-width:120px;height:auto;"></td></tr>
    <tr><td style="height:3px;background:#C99A35;font-size:0;line-height:0;">&nbsp;</td></tr>
    <tr><td style="padding:28px 28px 8px;">
      <h1 style="margin:0 0 8px;font-family:Georgia,'Times New Roman',serif;font-size:24px;font-weight:normal;">{{ $headline }}</h1>
      <p style="margin:0 0 4px;font-size:15px;line-height:1.5;">Hi {{ \Illuminate\Support\Str::before($order->name ?: 'there', ' ') }},</p>
      <p style="margin:0;font-size:15px;line-height:1.5;">Order <strong>{{ $order->number }}</strong> · placed {{ $order->created_at->format('j M Y') }}</p>
    </td></tr>

    @if($kind === 'shipped' || ($order->shipment && $order->shipment->tracking_number && $kind !== 'placed'))
    <tr><td style="padding:12px 28px 0;">
      @if($order->shipment && ($order->shipment->courier || $order->shipment->tracking_number))
        <p style="margin:0;padding:12px 14px;background:#F8F6F1;font-size:14px;line-height:1.5;">
          @if($order->shipment->courier)Courier: <strong>{{ $order->shipment->courier }}</strong><br>@endif
          @if($order->shipment->tracking_number)Tracking number: <strong>{{ $order->shipment->tracking_number }}</strong>@endif
          @if($order->shipment->tracking_url)<br><a href="{{ $order->shipment->tracking_url }}" style="color:#8A6513;">Track with the courier</a>@endif
        </p>
      @else
        <p style="margin:0;font-size:14px;color:#777777;">Courier details will appear on your order page once we have them.</p>
      @endif
    </td></tr>
    @endif

    @if($kind === 'placed' && $order->shipping_eta)
    <tr><td style="padding:12px 28px 0;"><p style="margin:0;font-size:14px;">Estimated delivery: <strong>{{ $order->shipping_eta }}</strong> <span style="color:#777777;">({{ $order->shipping_method_name }}, an estimate)</span></p></td></tr>
    @endif

    <tr><td style="padding:18px 28px 0;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:14px;border-top:1px solid #EAEAEA;">
        @foreach($order->items as $item)
          <tr>
            <td style="padding:10px 0;border-bottom:1px solid #EAEAEA;">{{ $item->name }}@if($item->options)<br><span style="color:#777777;font-size:12px;">{{ $item->options }}</span>@endif<br><span style="color:#777777;font-size:12px;">Qty {{ $item->quantity }}</span></td>
            <td align="right" style="padding:10px 0;border-bottom:1px solid #EAEAEA;white-space:nowrap;">{{ $cur }} {{ number_format($item->total) }}</td>
          </tr>
        @endforeach
        <tr><td style="padding:8px 0 2px;color:#777777;">Subtotal</td><td align="right" style="padding:8px 0 2px;">{{ $cur }} {{ number_format($order->subtotal) }}</td></tr>
        @if($order->discount)<tr><td style="padding:2px 0;color:#777777;">Discount @if($order->coupon_code)({{ $order->coupon_code }})@endif</td><td align="right">− {{ $cur }} {{ number_format($order->discount) }}</td></tr>@endif
        <tr><td style="padding:2px 0;color:#777777;">Shipping ({{ $order->shipping_method_name }})</td><td align="right">{{ $order->shipping_cost ? $cur.' '.number_format($order->shipping_cost) : 'Free' }}</td></tr>
        <tr><td style="padding:8px 0;font-weight:bold;border-top:1px solid #101010;">Total</td><td align="right" style="padding:8px 0;font-weight:bold;border-top:1px solid #101010;">{{ $cur }} {{ number_format($order->total) }}</td></tr>
      </table>
    </td></tr>

    <tr><td style="padding:16px 28px 0;font-size:14px;line-height:1.5;">
      <strong>Delivery address</strong><br>
      {{ $order->shippingAddress?->name }}<br>{{ $order->shippingAddress?->address }}<br>{{ $order->shippingAddress?->city }}, {{ $order->shippingAddress?->province }} {{ $order->shippingAddress?->postal_code }}<br>{{ $order->shippingAddress?->phone }}
    </td></tr>

    <tr><td style="padding:16px 28px 0;font-size:14px;line-height:1.5;">
      <strong>Payment:</strong> {{ $paymentLabel }} · {{ $order->payment_status_label }}
      @if($order->payment_method === 'cod' && $order->payment_status !== 'paid' && in_array($kind, ['placed', 'shipped']))<br>Please keep {{ $cur }} {{ number_format($order->total) }} ready when your order arrives.@endif
    </td></tr>

    @if($bank)
    <tr><td style="padding:12px 28px 0;font-size:14px;line-height:1.5;">
      <p style="margin:0 0 6px;">Please transfer <strong>{{ $cur }} {{ number_format($order->total) }}</strong> and quote <strong>{{ $order->number }}</strong> as the reference:</p>
      <pre style="margin:0;padding:12px 14px;background:#F8F6F1;white-space:pre-wrap;font-family:Arial,Helvetica,sans-serif;font-size:13px;">{{ $bank }}</pre>
      <p style="margin:8px 0 0;color:#777777;font-size:12px;">Your order is processed once we have verified the payment. You can send your bank reference or a photo of the receipt from your order page.</p>
    </td></tr>
    @endif

    <tr><td align="center" style="padding:24px 28px;">
      <a href="{{ $orderUrl }}" style="display:inline-block;background:#101010;color:#FFFFFF;text-decoration:none;font-weight:bold;font-size:14px;letter-spacing:1px;text-transform:uppercase;padding:14px 28px;">View your order</a>
    </td></tr>

    <tr><td style="padding:16px 28px 24px;border-top:1px solid #EAEAEA;font-size:12px;line-height:1.5;color:#777777;">
      This is an automated message about your order. @if($contactEmail || $contactPhone)Questions? @if($contactEmail)Write to {{ $contactEmail }}@endif @if($contactEmail && $contactPhone) or @endif @if($contactPhone)call {{ $contactPhone }}@endif.@endif
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
