<?php
/**
 * Short "new order" email to the Order notification address (setting mail.notify). Port of emails/staff-order.blade.php.
 * Data: $order (OrderService::load row), $adminUrl.
 */
$subject = cfg('abiha.name') . '®: new order ' . $order['number'];
$payment = ucwords(str_replace('_', ' ', (string) $order['payment_method'])) . ' (' . App\Services\OrderService::paymentStatusLabel((string) $order['payment_status']) . ')';
?>
    <tr><td style="padding:28px 28px 24px;font-size:14px;line-height:1.6;">
      <p style="margin:0 0 12px;font-size:16px;"><strong>New order <?= e($order['number']) ?></strong></p>
      Total: <?= e(money($order['total'])) ?><br>
      Payment: <?= e($payment) ?><br>
      Customer: <?= e($order['name']) ?>, <?= e($order['city']) ?><br><br>
      Open it: <a href="<?= e($adminUrl) ?>" style="color:#8A6513;"><?= e($adminUrl) ?></a>
    </td></tr>
<?php
$text = "New order {$order['number']}\n\nTotal: " . money($order['total']) . "\nPayment: {$payment}\nCustomer: {$order['name']}, {$order['city']}\n\nOpen it: {$adminUrl}";
