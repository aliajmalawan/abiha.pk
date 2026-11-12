<?php
/**
 * Customer email about an order (port of resources/views/emails/order.blade.php + order-text.blade.php).
 * Data from App\Services\OrderMailer::data(): $order (OrderService::load row), $kind, $headline, $subjectLine,
 * $orderUrl, $bank, $paymentLabel, $contactEmail, $contactPhone.
 */
use App\Services\OrderService;

$subject = $subjectLine;
$firstName = explode(' ', trim((string) ($order['name'] ?: 'there')))[0];
$placed = dt($order['created_at'], 'j M Y');
$ship = $order['shipment'] ?? null;
$payLabel = OrderService::paymentStatusLabel((string) $order['payment_status']);
$cur = App\Core\Money::symbol();
?>
    <tr><td style="padding:28px 28px 8px;">
      <h1 style="margin:0 0 8px;font-family:Georgia,'Times New Roman',serif;font-size:24px;font-weight:normal;"><?= e($headline) ?></h1>
      <p style="margin:0 0 4px;font-size:15px;line-height:1.5;">Hi <?= e($firstName) ?>,</p>
      <p style="margin:0;font-size:15px;line-height:1.5;">Order <strong><?= e($order['number']) ?></strong> · placed <?= e($placed) ?></p>
    </td></tr>

    <?php if ($kind === 'shipped' || ($ship && $ship['tracking_number'] && $kind !== 'placed')): ?>
    <tr><td style="padding:12px 28px 0;">
      <?php if ($ship && ($ship['courier'] || $ship['tracking_number'])): ?>
        <p style="margin:0;padding:12px 14px;background:#F8F6F1;font-size:14px;line-height:1.5;">
          <?php if ($ship['courier']): ?>Courier: <strong><?= e($ship['courier']) ?></strong><br><?php endif; ?>
          <?php if ($ship['tracking_number']): ?>Tracking number: <strong><?= e($ship['tracking_number']) ?></strong><?php endif; ?>
          <?php if ($ship['tracking_url']): ?><br><a href="<?= e($ship['tracking_url']) ?>" style="color:#8A6513;">Track with the courier</a><?php endif; ?>
        </p>
      <?php else: ?>
        <p style="margin:0;font-size:14px;color:#777777;">Courier details will appear on your order page once we have them.</p>
      <?php endif; ?>
    </td></tr>
    <?php endif; ?>

    <?php if ($kind === 'placed' && $order['shipping_eta']): ?>
    <tr><td style="padding:12px 28px 0;"><p style="margin:0;font-size:14px;">Estimated delivery: <strong><?= e($order['shipping_eta']) ?></strong> <span style="color:#777777;">(<?= e($order['shipping_method_name']) ?>, an estimate)</span></p></td></tr>
    <?php endif; ?>

    <tr><td style="padding:18px 28px 0;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:14px;border-top:1px solid #EAEAEA;">
        <?php foreach ($order['items'] as $item): ?>
          <tr>
            <td style="padding:10px 0;border-bottom:1px solid #EAEAEA;"><?= e($item['name']) ?><?php if ($item['options']): ?><br><span style="color:#777777;font-size:12px;"><?= e($item['options']) ?></span><?php endif; ?><br><span style="color:#777777;font-size:12px;">Qty <?= (int) $item['quantity'] ?></span></td>
            <td align="right" style="padding:10px 0;border-bottom:1px solid #EAEAEA;white-space:nowrap;"><?= e(money($item['total'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr><td style="padding:8px 0 2px;color:#777777;">Subtotal</td><td align="right" style="padding:8px 0 2px;"><?= e(money($order['subtotal'])) ?></td></tr>
        <?php if ((int) $order['discount']): ?><tr><td style="padding:2px 0;color:#777777;">Discount <?php if ($order['coupon_code']): ?>(<?= e($order['coupon_code']) ?>)<?php endif; ?></td><td align="right">− <?= e(money($order['discount'])) ?></td></tr><?php endif; ?>
        <tr><td style="padding:2px 0;color:#777777;">Shipping (<?= e($order['shipping_method_name']) ?>)</td><td align="right"><?= (int) $order['shipping_cost'] ? e(money($order['shipping_cost'])) : 'Free' ?></td></tr>
        <tr><td style="padding:8px 0;font-weight:bold;border-top:1px solid #101010;">Total</td><td align="right" style="padding:8px 0;font-weight:bold;border-top:1px solid #101010;"><?= e(money($order['total'])) ?></td></tr>
      </table>
    </td></tr>

    <tr><td style="padding:16px 28px 0;font-size:14px;line-height:1.5;">
      <strong>Delivery address</strong><br>
      <?= e($order['name']) ?><br><?= e($order['address']) ?><br><?= e($order['city']) ?>, <?= e($order['province']) ?> <?= e($order['postal_code']) ?><br><?= e($order['phone']) ?>
    </td></tr>

    <tr><td style="padding:16px 28px 0;font-size:14px;line-height:1.5;">
      <strong>Payment:</strong> <?= e($paymentLabel) ?> · <?= e($payLabel) ?>
      <?php if ($order['payment_method'] === 'cod' && $order['payment_status'] !== 'paid' && in_array($kind, ['placed', 'shipped'], true)): ?><br>Please keep <?= e(money($order['total'])) ?> ready when your order arrives.<?php endif; ?>
    </td></tr>

    <?php if ($bank): ?>
    <tr><td style="padding:12px 28px 0;font-size:14px;line-height:1.5;">
      <p style="margin:0 0 6px;">Please transfer <strong><?= e(money($order['total'])) ?></strong> and quote <strong><?= e($order['number']) ?></strong> as the reference:</p>
      <pre style="margin:0;padding:12px 14px;background:#F8F6F1;white-space:pre-wrap;font-family:Arial,Helvetica,sans-serif;font-size:13px;"><?= e($bank) ?></pre>
      <p style="margin:8px 0 0;color:#777777;font-size:12px;">Your order is processed once we have verified the payment. You can send your bank reference or a photo of the receipt from your order page.</p>
    </td></tr>
    <?php endif; ?>

    <tr><td align="center" style="padding:24px 28px;">
      <a href="<?= e($orderUrl) ?>" style="display:inline-block;background:#101010;color:#FFFFFF;text-decoration:none;font-weight:bold;font-size:14px;letter-spacing:1px;text-transform:uppercase;padding:14px 28px;">View your order</a>
    </td></tr>
<?php
$contact = '';
if ($contactEmail || $contactPhone) {
    $contact = 'Questions? ' . ($contactEmail ? 'Write to ' . e($contactEmail) : '') . ($contactEmail && $contactPhone ? ' or ' : '') . ($contactPhone ? 'call ' . e($contactPhone) : '') . '.';
}
$footer = 'This is an automated message about your order. ' . $contact;

// Plain-text part (order-text.blade.php).
$t = [$brand, '', $headline, 'Order ' . $order['number'] . ' (placed ' . $placed . ')', ''];
foreach ($order['items'] as $item) {
    $t[] = '- ' . $item['name'] . ($item['options'] ? ' (' . $item['options'] . ')' : '') . ' x ' . $item['quantity'] . ': ' . money($item['total']);
}
$t[] = '';
$t[] = 'Subtotal: ' . money($order['subtotal']);
if ((int) $order['discount']) {
    $t[] = 'Discount: -' . money($order['discount']);
}
$t[] = 'Shipping (' . $order['shipping_method_name'] . '): ' . ((int) $order['shipping_cost'] ? money($order['shipping_cost']) : 'Free');
$t[] = 'Total: ' . money($order['total']);
$t[] = '';
$t[] = 'Payment: ' . $paymentLabel . ' (' . $payLabel . ')';
if ($ship && $ship['tracking_number']) {
    $t[] = 'Courier: ' . $ship['courier'];
    $t[] = 'Tracking number: ' . $ship['tracking_number'];
}
if ($bank) {
    $t[] = '';
    $t[] = 'Bank transfer details (quote ' . $order['number'] . ' as the reference):';
    $t[] = $bank;
}
$t[] = '';
$t[] = 'View your order: ' . $orderUrl;
$text = implode("\n", $t);
