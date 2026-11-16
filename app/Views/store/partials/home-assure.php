<?php
/*
 * Home page: a slim strip right under the hero with what shopping here really involves. Every line comes from the shop's own
 * settings (the couriers that are active, the payment methods that are switched on) or is a fact about the site (order tracking,
 * an encrypted connection). A line disappears when its data is empty: nothing here is a promise the shop has not configured.
 */
use App\Services\ContentPages;

$pay = ContentPages::paymentLabels();
$couriers = array_values(array_filter(array_map(static fn ($m) => (string) ($m['name'] ?? ''), ContentPages::shippingMethods())));
$items = [];
if ($couriers) {
    $items[] = ['truck', 'Delivery by ' . ContentPages::join($couriers, ', ', ' or '), 'Tracking shared when your order ships', null];
}
if ($pay) {
    $items[] = ['credit-card', 'Pay by ' . ContentPages::join($pay, ', ', ' or '), 'Choose how you pay at checkout', null];
}
$items[] = ['package', 'Track your order', 'Check its status any time', route('track')];
$items[] = ['lock', 'Secure checkout', 'Your details travel over an encrypted connection', null];
?>
<section class="home-assure" id="after-hero" aria-label="Shopping with us">
    <ul class="container home-assure__list" data-reveal-group>
        <?php foreach ($items as [$ic, $title, $sub, $href]): ?>
            <li class="home-assure__item" data-reveal>
                <span class="home-assure__icon" aria-hidden="true"><?= icon($ic, 22) ?></span>
                <span class="home-assure__text">
                    <?php if ($href): ?><a href="<?= e($href) ?>" class="home-assure__title"><?= e($title) ?></a><?php else: ?><strong class="home-assure__title"><?= e($title) ?></strong><?php endif; ?>
                    <span class="home-assure__sub"><?= e($sub) ?></span>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
