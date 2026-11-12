<?php
/**
 * Order history (port of resources/views/account/orders.blade.php), 10 per page.
 *
 * @var list<array> $orders (with items_count)
 * @var int $page
 * @var int $lastPage
 */
use App\Core\View;
use App\Services\AccountOrders;

$cur = cfg('abiha.currency.symbol');
?>
<?php foreach ($orders as $o): ?>
    <a href="<?= e(route('account.orders.show', $o)) ?>" class="card card--hover no-underline flex flex-wrap items-center justify-between gap-3 mb-3">
        <span><strong class="font-display"><?= e($o['number']) ?></strong><br><span class="text-sm text-grey-text"><?= e(dt($o['created_at'], 'j M Y')) ?> · <?= (int) $o['items_count'] ?> <?= (int) $o['items_count'] === 1 ? 'line' : 'lines' ?></span></span>
        <span class="flex items-center gap-3"><span class="badge badge--<?= e(AccountOrders::statusTone((string) $o['status'])) ?>"><?= e(AccountOrders::statusLabel((string) $o['status'])) ?></span><strong><?= e($cur) ?> <?= number_format((int) $o['total']) ?></strong></span>
    </a>
<?php endforeach; ?>
<?php if (!$orders): ?>
    <div class="empty">
        <span class="empty__icon" aria-hidden="true"><?= icon('package', 30) ?></span>
        <h2>No orders yet</h2>
        <p>When you place an order it will appear here so you can follow it.</p>
        <div class="mt-2 flex flex-wrap justify-center gap-3">
            <a href="<?= e(route('shop')) ?>" class="btn ">Start shopping</a>
        </div>
    </div>
<?php endif; ?>
<div class="mt-6"><?= View::partial('account/partials/pagination', ['page' => $page, 'lastPage' => $lastPage, 'base' => route('account.orders')]) ?></div>
