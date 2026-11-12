<?php
/**
 * Shipping & returns (port of resources/views/storefront/shipping-returns.blade.php).
 *
 * @var string|null $deliveryInfo
 * @var string|null $returnPolicy
 * @var list<array> $methods active shipping methods
 */
$cur = cfg('abiha.currency.symbol');
?>
<div class="container py-12 lg:py-16 max-w-3xl">
    <h1 class="!text-3xl lg:!text-4xl">Shipping &amp; returns</h1>

    <h2 class="!text-xl mt-8">Delivery</h2>
    <div class="prose-abiha"><?= $deliveryInfo ? nl2br(e($deliveryInfo)) : '<p class="muted">Delivery information will be published soon.</p>' ?></div>

    <?php if ($methods): ?>
        <div class="overflow-x-auto mt-4"><table class="w-full text-sm">
            <thead><tr class="text-left border-b border-line"><th class="py-2">Method</th><th>Estimated time</th><th>Cost</th></tr></thead>
            <tbody><?php foreach ($methods as $m): ?><tr class="border-b border-line"><td class="py-3"><span class="inline-flex items-center gap-3"><?php if (in_array($m['code'], ['dhl', 'fedex', 'ups'], true)): ?><span class="carrier-tile"><?= carrier_logo((string) $m['code'], 22) . "\n" ?></span><?php endif; ?><?= e($m['name']) ?></span> <?php if (!empty($m['is_sample']) && cfg('abiha.show_sample_labels')): ?><span class="sample-flag">SAMPLE</span><?php endif; ?></td><td><?= e($m['eta'] ?: '-') ?></td>
                <td><?= e($cur) ?> <?= number_format((int) $m['cost']) ?><?php if ($m['free_over']): ?> <span class="text-grey-text">(free over <?= number_format((int) $m['free_over']) ?>)</span><?php endif; ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>

    <div class="carrier-strip" aria-label="Delivery partners">
        <span class="carrier-strip__label">Delivery partners</span>
        <?php foreach (['dhl', 'fedex', 'ups'] as $c): ?><span class="carrier-tile carrier-tile--lg"><?= carrier_logo($c, 34) . "\n" ?></span><?php endforeach; ?>
    </div>

    <h2 class="!text-xl mt-10">Returns</h2>
    <div class="prose-abiha"><?= $returnPolicy ? nl2br(e($returnPolicy)) : '<p class="muted">Our return policy will be published soon.</p>' ?></div>
</div>
