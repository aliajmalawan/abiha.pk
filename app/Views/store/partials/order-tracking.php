<?php
/**
 * Order progress, items and updates (port of partials/order-tracking.blade.php). Used by the track page and the
 * customer account order page. Vars: $order (OrderService::load() row: items, history, tracking fields).
 */
use App\Services\OrderService;

$steps = ['pending' => 'Placed', 'confirmed' => 'Confirmed', 'processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered'];
$keys = array_keys($steps);
$currentIdx = array_search($order['status'], $keys, true);
?>
<section class="mt-10 space-y-6" aria-label="Order <?= e($order['number']) ?>">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="!text-xl !mb-0">Order <?= e($order['number']) ?></h2>
        <div class="flex gap-2"><span class="badge badge--<?= e(OrderService::statusTone((string) $order['status'])) ?>"><?= e(OrderService::statusLabel((string) $order['status'])) ?></span><span class="badge badge--ivory"><?= e(OrderService::paymentStatusLabel((string) $order['payment_status'])) ?></span></div>
    </div>

    <?php if ($order['status'] === 'cancelled'): ?>
        <div class="alert alert--danger">This order was cancelled.</div>
    <?php elseif ($order['status'] === 'returned'): ?>
        <div class="alert alert--warning">This order was returned.<?php if ($order['payment_status'] === 'refunded'): ?> Your refund has been issued.<?php endif; ?></div>
    <?php else: ?>
        <ol class="grid grid-cols-5 gap-1 text-center text-[11px] sm:text-xs" aria-label="Progress">
            <?php foreach ($steps as $k => $label): $done = $currentIdx !== false && array_search($k, $keys, true) <= $currentIdx; ?>
                <li><span class="mx-auto mb-2 grid size-8 place-content-center rounded-full border-2 <?= $done ? 'bg-ink border-ink text-champagne' : 'border-line text-grey-text' ?>"><?php if ($done): ?><?= icon('check', 15) ?><?php endif; ?></span>
                    <span class="<?= $done ? 'font-semibold' : 'text-grey-text' ?>"><?= e($label) ?></span></li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if ($order['shipping_eta'] && !in_array($order['status'], ['delivered', 'cancelled', 'returned'], true)): ?>
        <p class="text-sm">Estimated delivery: <strong><?= e($order['shipping_eta']) ?></strong> <span class="text-grey-text">(<?= e($order['shipping_method_name']) ?>, an estimate, not a guarantee)</span></p>
    <?php endif; ?>
    <?php if (!empty($order['tracking_number'])): ?>
        <p class="text-sm"><?php if ($order['courier']): ?><?= e($order['courier']) ?> · <?php endif; ?> Tracking number: <strong><?= e($order['tracking_number']) ?></strong><?php if ($order['tracking_url']): ?> · <a class="link" href="<?= e($order['tracking_url']) ?>" rel="noopener" target="_blank">Track with courier</a><?php endif; ?></p>
    <?php endif; ?>

    <ul class="divide-y divide-line border-y border-line">
        <?php foreach ($order['items'] as $item): ?>
            <li class="py-3 flex justify-between gap-4 text-sm"><span><?= e($item['name']) ?> <?php if ($item['options']): ?><span class="text-grey-text">· <?= e($item['options']) ?></span><?php endif; ?> × <?= (int) $item['quantity'] ?></span><span class="font-semibold"><?= e(money($item['total'])) ?></span></li>
        <?php endforeach; ?>
    </ul>
    <p class="text-right font-display font-bold">Total: <?= e(money($order['total'])) ?></p>

    <?php if ($order['history']): ?>
        <div>
            <h3 class="eyebrow mb-3">Updates</h3>
            <ol class="space-y-3 text-sm border-l-2 border-line pl-4">
                <?php foreach ($order['history'] as $h): ?>
                    <li><span class="font-semibold"><?= e(OrderService::statusLabel((string) $h['status'])) ?></span> <span class="text-grey-text">· <?= e(dt($h['created_at'], 'j M Y, g:i a')) ?></span><?php if ($h['note']): ?><br><span class="text-grey-text"><?= e($h['note']) ?></span><?php endif; ?></li>
                <?php endforeach; ?>
            </ol>
        </div>
    <?php endif; ?>
</section>
