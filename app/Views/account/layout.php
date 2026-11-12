<?php
/**
 * Customer account frame (port of resources/views/account/layout.blade.php): heading, side navigation, page body.
 *
 * @var string $account rendered page body
 * @var string $heading
 */
$links = [
    ['account.dashboard', 'Overview', 'layout-dashboard'], ['account.orders', 'Orders', 'package'], ['account.addresses', 'Addresses', 'map-pin'],
    ['account.profile', 'Profile & password', 'user'], ['wishlist', 'Wishlist', 'heart'],
];
?>
<div class="container py-10 lg:py-14">
    <h1 class="!text-3xl lg:!text-4xl"><?= e($heading ?? 'My account') ?></h1>
    <div class="grid gap-10 lg:grid-cols-[240px_1fr] mt-8">
        <nav aria-label="Account" class="flex lg:flex-col gap-1 overflow-x-auto lg:overflow-visible border-b lg:border-b-0 border-line pb-2 lg:pb-0">
            <?php foreach ($links as [$route, $label, $icon]): ?>
                <?php $active = route_is($route) || ($route === 'account.orders' && route_is('account.orders.*')); ?>
                <a href="<?= e(route($route)) ?>"<?= $active ? ' aria-current="page"' : '' ?> class="flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-semibold whitespace-nowrap no-underline <?= $active ? 'bg-ink text-white' : 'hover:bg-ivory' ?>"><?= icon($icon, 18) ?> <?= e($label) ?></a>
            <?php endforeach; ?>
            <form method="POST" action="<?= e(route('logout')) ?>" class="lg:mt-4"><?= csrf_field() ?>
                <button class="flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-sm font-semibold text-grey-text hover:bg-ivory whitespace-nowrap"><?= icon('log-out', 18) ?> Sign out</button>
            </form>
        </nav>
        <div class="min-w-0"><?= $account ?></div>
    </div>
</div>
