<?php
/**
 * Account overview (port of resources/views/account/dashboard.blade.php).
 *
 * @var list<array> $recentOrders  (with item_quantity)
 * @var int $orderCount
 * @var int $wishlistCount
 * @var array|null $defaultAddress
 */
use App\Services\AccountOrders;

$cur = cfg('abiha.currency.symbol');
?>
<div class="grid gap-4 sm:grid-cols-3">
    <a href="<?= e(route('account.orders')) ?>" class="card card--hover no-underline"><p class="eyebrow">Orders</p><p class="font-display font-extrabold text-3xl !mb-0"><?= (int) $orderCount ?></p></a>
    <a href="<?= e(route('wishlist')) ?>" class="card card--hover no-underline"><p class="eyebrow">Wishlist</p><p class="font-display font-extrabold text-3xl !mb-0"><?= (int) $wishlistCount ?></p></a>
    <a href="<?= e(route('account.addresses')) ?>" class="card card--hover no-underline"><p class="eyebrow">Default address</p><p class="text-sm !mb-0"><?= e($defaultAddress ? $defaultAddress['city'] . ', ' . $defaultAddress['province'] : 'Not added yet') ?></p></a>
</div>

<h2 class="!text-xl mt-10">Recent orders</h2>
<?php foreach ($recentOrders as $o): ?>
    <a href="<?= e(route('account.orders.show', $o)) ?>" class="card card--hover no-underline flex flex-wrap items-center justify-between gap-3 mb-3">
        <span><strong class="font-display"><?= e($o['number']) ?></strong><br><span class="text-sm text-grey-text"><?= e(dt($o['created_at'], 'j M Y')) ?> · <?= (int) $o['item_quantity'] ?> items</span></span>
        <span class="flex items-center gap-3"><span class="badge badge--<?= e(AccountOrders::statusTone((string) $o['status'])) ?>"><?= e(AccountOrders::statusLabel((string) $o['status'])) ?></span><strong><?= e($cur) ?> <?= number_format((int) $o['total']) ?></strong></span>
    </a>
<?php endforeach; ?>
<?php if (!$recentOrders): ?>
    <p class="muted">You haven't placed an order yet. <a class="link" href="<?= e(route('shop')) ?>">Start shopping</a></p>
<?php endif; ?>
