<?php
/** Payment history (port of admin/payment-history.blade.php). @var array $rows (Pager) @var array $filters @var array $totals */
use App\Core\View;
use App\Services\Admin\Display;

$cur = (string) cfg('abiha.currency.symbol');
$fv = static fn (string $k): string => is_scalar($filters[$k] ?? null) ? (string) $filters[$k] : '';
?>
<h1 class="!text-2xl">Payment history</h1>
<p class="help mt-1 max-w-2xl">Every step of every payment, newest first: payment started, provider notices, bank transfers reported, staff decisions and refunds recorded. Rows are never edited or deleted.</p>

<div class="grid gap-3 grid-cols-2 lg:grid-cols-4 mt-4">
    <?php foreach (['pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded'] as $k => $l): ?>
        <div class="stat"><span><?= e($l) ?></span><b><?= e(number_format($totals[$k]['n'] ?? 0)) ?></b><small class="text-grey-text text-xs"><?= e($cur) ?> <?= e(number_format($totals[$k]['total'] ?? 0)) ?> in records</small></div>
    <?php endforeach; ?>
</div>

<form method="GET" class="flex flex-wrap gap-3 items-end mt-4" role="search">
    <div class="field"><label class="label text-xs" for="q">Search</label><input class="input" id="q" name="q" value="<?= e($fv('q')) ?>" placeholder="Order number or reference"></div>
    <div class="field"><label class="label text-xs" for="provider">Method</label>
        <select class="select" id="provider" name="provider"><option value="">All</option><?php foreach (['cod' => 'Cash on delivery', 'bank_transfer' => 'Bank transfer', 'card' => 'Card'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $fv('provider') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label class="label text-xs" for="status">Status</label>
        <select class="select" id="status" name="status"><option value="">All</option><?php foreach (['pending', 'paid', 'failed', 'refunded'] as $k): ?><option value="<?= e($k) ?>"<?= $fv('status') === $k ? ' selected' : '' ?>><?= e(ucfirst($k)) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn--sm !min-h-[48px]">Filter</button>
</form>

<div class="card !p-0 mt-4 overflow-x-auto">
    <table class="table"><thead><tr><th>When</th><th>Order</th><th>Method</th><th>Event</th><th>Status</th><th>Reference</th><th class="num">Amount</th><th>By</th></tr></thead><tbody>
        <?php foreach ($rows['items'] as $t): ?>
            <tr>
                <td><?= e(dt((string) $t['created_at'], 'j M Y, g:i a')) ?></td>
                <td><?php if ($t['order_number'] !== null): ?><a class="link" href="<?= e(route('admin.orders.show', ['number' => $t['order_number']])) ?>"><?= e($t['order_number']) ?></a><?php else: ?> - <?php endif; ?></td>
                <td><?= e(Display::words($t['provider'])) ?></td>
                <td><?= e(Display::sentence($t['type'])) ?></td>
                <td><span class="badge <?= $t['status'] === 'paid' ? 'badge--success' : ($t['status'] === 'failed' ? 'badge--danger' : 'badge--ivory') ?>"><?= e(ucfirst((string) $t['status'])) ?></span></td>
                <td><?= e($t['reference'] ?: '-') ?></td>
                <td class="num"><?= e($cur) ?> <?= e(number_format((int) $t['amount'])) ?></td>
                <td><?= e($t['user_name'] ?: 'System') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows['items']): ?>
            <tr><td colspan="8" class="text-center py-8 muted">No payment records yet.</td></tr>
        <?php endif; ?>
    </tbody></table>
</div>
<div class="mt-4"><?= View::partial('admin/partials/pagination', ['pager' => $rows]) ?></div>
