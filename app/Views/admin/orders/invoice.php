<?php
/** Printable invoice / packing slip (port of admin/orders/invoice.blade.php). @var array $order (OrderService::load) */
use App\Core\Security;
use App\Services\Admin\Display;
use App\Services\OrderService;

$cur = (string) cfg('abiha.currency.symbol');
$num = static fn ($v): string => number_format((int) $v);
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Invoice <?= e($order['number']) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/abiha/abiha.css')) ?>"></head>
<body class="bg-white p-8 max-w-3xl mx-auto">
<div class="flex justify-between items-start">
    <?= logo(130) ?>
    <div class="text-right text-sm"><h1 class="!text-xl !mb-1">Invoice</h1><p><?= e($order['number']) ?><br><?= e(dt((string) $order['created_at'], 'j M Y')) ?></p></div>
</div>
<div class="grid grid-cols-2 gap-6 my-8 text-sm">
    <div><h2 class="eyebrow mb-1">Bill / ship to</h2><p><?= e($order['name']) ?><br><?= e($order['address']) ?><br><?= e($order['city']) ?>, <?= e($order['province']) ?> <?= e($order['postal_code']) ?><br><?= e($order['phone']) ?><br><?= e($order['email']) ?></p></div>
    <div><h2 class="eyebrow mb-1">Order</h2><p>Payment: <?= e(Display::words($order['payment_method'])) ?> (<?= e(OrderService::paymentStatusLabel((string) $order['payment_status'])) ?>)<br>Shipping: <?= e($order['shipping_method_name']) ?></p></div>
</div>
<table class="table"><thead><tr><th>Item</th><th>SKU</th><th class="num">Price</th><th class="num">Qty</th><th class="num">Total</th></tr></thead><tbody>
    <?php foreach ($order['items'] as $i): ?><tr><td><?= e($i['name']) ?> <?php if ($i['options']): ?><span class="text-xs text-grey-text">(<?= e($i['options']) ?>)</span><?php endif; ?></td><td><?= e($i['sku']) ?></td><td class="num"><?= $num($i['unit_price']) ?></td><td class="num"><?= (int) $i['quantity'] ?></td><td class="num"><?= $num($i['total']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<dl class="mt-4 ml-auto max-w-xs text-sm space-y-1">
    <div class="flex justify-between"><dt>Subtotal</dt><dd><?= e($cur) ?> <?= $num($order['subtotal']) ?></dd></div>
    <?php if ((int) $order['discount']): ?><div class="flex justify-between"><dt>Discount</dt><dd>− <?= e($cur) ?> <?= $num($order['discount']) ?></dd></div><?php endif; ?>
    <div class="flex justify-between"><dt>Shipping</dt><dd><?= e($cur) ?> <?= $num($order['shipping_cost']) ?></dd></div>
    <?php if ((int) $order['tax_amount']): ?><div class="flex justify-between text-grey-text"><dt>Includes <?= e($order['tax_label'] ?? 'tax') ?> (<?= e(Display::rate($order['tax_rate'])) ?>%)</dt><dd><?= e($cur) ?> <?= $num($order['tax_amount']) ?></dd></div><?php endif; ?>
    <div class="flex justify-between border-t border-ink pt-2 font-bold text-base"><dt>Total</dt><dd><?= e($cur) ?> <?= $num($order['total']) ?></dd></div>
</dl>
<script nonce="<?= e(Security::nonce()) ?>">window.addEventListener('load', () => window.print());</script>
</body></html>
