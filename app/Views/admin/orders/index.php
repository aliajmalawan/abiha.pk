<?php
/** Orders list (port of admin/orders/index.blade.php). @var array $orders (Pager) @var array $filters @var array $counts */
use App\Core\View;
use App\Services\Admin\Display;
use App\Services\Admin\Form;
use App\Services\OrderService;

$cur = (string) cfg('abiha.currency.symbol');
$fv = static fn (string $k): string => is_scalar($filters[$k] ?? null) ? (string) $filters[$k] : '';
?>
<div class="flex flex-wrap items-center justify-between gap-3"><h1 class="!text-2xl !mb-0">Orders</h1>
    <a href="<?= e(route('admin.orders.export', array_filter($filters, static fn ($v) => $v !== null && $v !== ''))) ?>" class="btn btn--sm btn--outline">Export CSV</a></div>

<div class="flex flex-wrap gap-2 my-4 text-sm">
    <a href="<?= e(route('admin.orders.index')) ?>" class="badge <?= $fv('status') === '' ? '' : 'badge--ivory' ?> no-underline">All</a>
    <?php foreach (OrderService::STATUSES as $k => $l): ?>
        <a href="<?= e(route('admin.orders.index', ['status' => $k])) ?>" class="badge <?= $fv('status') === $k ? '' : 'badge--ivory' ?> no-underline"><?= e($l) ?> (<?= (int) ($counts[$k] ?? 0) ?>)</a>
    <?php endforeach; ?>
</div>

<form method="GET" class="mb-4 flex flex-wrap gap-2" role="search">
    <?php if ($fv('status') !== ''): ?><input type="hidden" name="status" value="<?= e($fv('status')) ?>"><?php endif; ?>
    <input name="q" value="<?= e($fv('q')) ?>" class="input !min-h-[40px] max-w-xs" placeholder="Order no., email, name or phone" aria-label="Search orders">
    <select name="payment" class="select !min-h-[40px] !w-auto" aria-label="Payment status"><option value="">Any payment</option><?php foreach (OrderService::PAYMENT_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= $fv('payment') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <select name="method" class="select !min-h-[40px] !w-auto" aria-label="Method"><option value="">Any method</option><?php foreach (['cod' => 'Cash on Delivery', 'bank_transfer' => 'Bank Transfer'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $fv('method') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <input type="date" name="from" value="<?= e($fv('from')) ?>" class="input !min-h-[40px] !w-auto" aria-label="From date">
    <input type="date" name="to" value="<?= e($fv('to')) ?>" class="input !min-h-[40px] !w-auto" aria-label="To date">
    <button class="btn btn--sm">Filter</button>
</form>

<div class="card !p-0 overflow-x-auto">
    <table class="table">
        <thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Payment</th><th>Status</th><th class="num">Total</th></tr></thead>
        <tbody>
        <?php foreach ($orders['items'] as $o): ?>
            <?php $ps = (string) $o['payment_status']; ?>
            <tr>
                <td><a class="link" href="<?= e(route('admin.orders.show', $o)) ?>"><?= e($o['number']) ?></a><br><span class="text-xs text-grey-text"><?= (int) $o['items_count'] ?> <?= Form::plural('line', (int) $o['items_count']) ?></span></td>
                <td class="whitespace-nowrap"><?= e(dt((string) $o['created_at'], 'j M Y, g:i a')) ?></td>
                <td><?= e($o['name']) ?><br><span class="text-xs text-grey-text"><?= e($o['email']) ?></span></td>
                <td><?= e(Display::words($o['payment_method'])) ?><br><span class="badge <?= $ps === 'paid' ? 'badge--success' : ($ps === 'awaiting_verification' ? 'badge--warning' : 'badge--ivory') ?>"><?= e(OrderService::paymentStatusLabel($ps)) ?></span></td>
                <td><span class="badge badge--<?= e(OrderService::statusTone((string) $o['status'])) ?>"><?= e(OrderService::statusLabel((string) $o['status'])) ?></span></td>
                <td class="num font-semibold"><?= e($cur) ?> <?= e(number_format((int) $o['total'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$orders['items']): ?><tr><td colspan="6" class="text-center py-10 muted">No orders found.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<div class="mt-6"><?= View::partial('admin/partials/pagination', ['pager' => $orders]) ?></div>
