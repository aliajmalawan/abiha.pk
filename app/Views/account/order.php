<?php
/**
 * One order (port of resources/views/account/order.blade.php).
 *
 * @var array $order AccountOrders::withDetails() row
 */
use App\Core\View;
use App\Services\AccountOrders;
?>
<a href="<?= e(route('account.orders')) ?>" class="link text-sm inline-flex items-center gap-1 mb-4"><?= icon('chevron-left', 14) ?> All orders</a>

<div class="card">
    <?= View::partial('account/partials/order-tracking', ['order' => $order]) ?>
    <div class="grid gap-6 sm:grid-cols-2 text-sm mt-8 pt-6 border-t border-line">
        <div><h3 class="eyebrow mb-2">Delivery address</h3><p><?= e($order['name']) ?><br><?= e($order['address']) ?><br><?= e($order['city']) ?>, <?= e($order['province']) ?> <?= e($order['postal_code']) ?><br><?= e($order['phone']) ?></p></div>
        <div><h3 class="eyebrow mb-2">Payment</h3><p><?= e($order['payment_method'] === 'cod' ? 'Cash on Delivery' : ucwords(str_replace('_', ' ', (string) $order['payment_method']))) ?><br><span class="text-grey-text"><?= e(AccountOrders::paymentStatusLabel((string) $order['payment_status'])) ?></span></p></div>
    </div>
    <?php if (AccountOrders::isCancellable($order)): ?>
        <form method="POST" action="<?= e(route('account.orders.cancel', $order)) ?>" class="mt-6" x-data @submit="$store.confirm.ask($event, { title: 'Cancel this order?', message: 'The items go back on the shelf and this cannot be undone.', label: 'Yes, cancel order' })"><?= csrf_field() ?><button class="btn btn--outline btn--sm">Cancel order</button></form>
    <?php endif; ?>
</div>
