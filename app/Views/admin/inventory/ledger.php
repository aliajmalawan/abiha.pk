<?php
/** Port of admin/inventory/ledger.blade.php. @var array $rows (Pager) @var array $filters @var array $reasons */
use App\Core\View;
?>
<a href="<?= e(route('admin.inventory.index')) ?>" class="link text-sm inline-flex items-center gap-1 mb-4"><?= icon('chevron-left', 14) ?> Inventory</a>
<div class="flex flex-wrap items-center justify-between gap-3"><h1 class="!text-2xl !mb-0">Stock ledger</h1><a href="<?= e(route('admin.inventory.ledger.export')) ?>" class="btn btn--sm btn--outline">Export CSV</a></div>
<p class="help mb-4">Every stock change, newest first. Entries cannot be edited or deleted.</p>

<form method="GET" class="mb-4 flex flex-wrap gap-2" role="search">
    <input name="q" value="<?= e($filters['q'] ?? '') ?>" class="input !min-h-[40px] max-w-xs" placeholder="Search product" aria-label="Search">
    <select name="reason" class="select !min-h-[40px] !w-auto" aria-label="Reason"><option value="">All reasons</option><?php foreach ($reasons as $r): ?><option value="<?= e($r) ?>"<?= ($filters['reason'] ?? '') === $r ? ' selected' : '' ?>><?= e(ucfirst($r)) ?></option><?php endforeach; ?></select>
    <button class="btn btn--sm">Filter</button>
</form>

<div class="card !p-0 overflow-x-auto">
    <table class="table">
        <thead><tr><th>When</th><th>Item</th><th class="num">Change</th><th class="num">Balance</th><th>Reason</th><th>By</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($rows['items'] as $m): ?>
            <tr>
                <td class="whitespace-nowrap"><?= e(dt((string) $m['created_at'])) ?></td>
                <td><?= e($m['product_name']) ?> <?php if ($m['variant_sku'] !== null): ?><span class="text-grey-text text-xs">· <?= e($m['variant_sku']) ?></span><?php endif; ?></td>
                <td class="num font-semibold <?= (int) $m['change'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (int) $m['change'] > 0 ? '+' : '' ?><?= (int) $m['change'] ?></td>
                <td class="num"><?= (int) $m['balance_after'] ?></td>
                <td><span class="badge badge--ivory"><?= e(ucfirst((string) $m['reason'])) ?></span></td>
                <td><?= e($m['user_name'] ?? 'System') ?></td>
                <td class="text-grey-text"><?= e($m['note']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows['items']): ?><tr><td colspan="7" class="text-center py-10 muted">No movements recorded.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<div class="mt-6"><?= View::partial('admin/partials/pagination', ['pager' => $rows]) ?></div>
