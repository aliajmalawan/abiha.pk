<?php
/*
 * What shopping here really involves, under the buy buttons of a product: the payment methods that are switched on, the
 * couriers that are active (with their logos), order tracking and the secure checkout. Nothing here is a promise the
 * shop has not configured: a line disappears when its data is empty.
 */
use App\Services\ContentPages;

$pay = ContentPages::paymentLabels();
$ship = ContentPages::shippingMethods();
$names = array_values(array_filter(array_map(static fn ($m) => (string) ($m['name'] ?? ''), $ship)));
$codes = array_values(array_filter(array_map(static fn ($m) => (string) ($m['code'] ?? ''), $ship), static fn ($c) => in_array($c, ['dhl', 'fedex', 'ups'], true)));
?>
<ul class="assure" aria-label="Shopping with us">
    <?php if ($pay): ?><li><?= icon('credit-card', 18) ?><span>Pay by <?= e(ContentPages::join($pay, ', ', ' or ')) ?></span></li><?php endif; ?>
    <?php if ($names): ?>
        <li><?= icon('truck', 18) ?><span>Delivery by <?= e(ContentPages::join($names, ', ', ' or ')) ?></span>
            <?php if ($codes): ?><span class="assure__logos" aria-hidden="true"><?php foreach ($codes as $c): ?><?= carrier_logo($c, 16) ?><?php endforeach; ?></span><?php endif; ?></li>
    <?php endif; ?>
    <li><?= icon('package', 18) ?><span><a href="<?= e(route('track')) ?>">Track your order</a> at every step</span></li>
    <li><?= icon('lock', 18) ?><span>Secure checkout</span></li>
</ul>
