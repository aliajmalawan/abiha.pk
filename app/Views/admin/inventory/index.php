<?php
/** Port of admin/inventory/index.blade.php. @var array $products (Pager, items with 'variants') @var array $filters */
use App\Core\View;
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <h1 class="!text-2xl !mb-0">Inventory</h1>
    <div class="flex gap-2"><a href="<?= e(route('admin.inventory.export')) ?>" class="btn btn--sm btn--outline">Export stock CSV</a><a href="<?= e(route('admin.inventory.ledger')) ?>" class="btn btn--sm btn--outline">Stock history</a></div>
</div>

<form method="GET" class="mb-4 flex flex-wrap gap-2" role="search">
    <input name="q" value="<?= e($filters['q'] ?? '') ?>" class="input !min-h-[40px] max-w-xs" placeholder="Search name or SKU" aria-label="Search">
    <select name="status" class="select !min-h-[40px] !w-auto" aria-label="Stock level"><option value="">All stock levels</option>
        <option value="low"<?= ($filters['status'] ?? '') === 'low' ? ' selected' : '' ?>>Low stock</option><option value="out"<?= ($filters['status'] ?? '') === 'out' ? ' selected' : '' ?>>Out of stock</option></select>
    <button class="btn btn--sm">Filter</button>
</form>

<div class="card !p-0 overflow-x-auto">
    <table class="table">
        <thead><tr><th>Item</th><th>SKU</th><th class="num">On hand</th><th>Adjust stock</th></tr></thead>
        <tbody>
        <?php foreach ($products['items'] as $p): ?>
            <?php
            // One adjustment form per stock-holding row (product, or each variant)
            $lines = (int) $p['has_variants'] === 1
                ? array_map(static fn ($v) => ['target' => 'variant', 'id' => (int) $v['id'], 'label' => $p['name'] . ' · ' . $v['label'], 'sku' => $v['sku'], 'stock' => (int) $v['stock'], 'threshold' => 3], $p['variants'])
                : [['target' => 'product', 'id' => (int) $p['id'], 'label' => $p['name'], 'sku' => $p['sku'], 'stock' => (int) $p['stock'], 'threshold' => (int) $p['low_stock_threshold']]];
            ?>
            <?php foreach ($lines as $l): ?>
                <tr>
                    <td><?= e($l['label']) ?><?php if (!$p['is_active']): ?> <span class="badge badge--ivory ml-1">Hidden</span><?php endif; ?></td>
                    <td class="text-grey-text"><?= e($l['sku']) ?></td>
                    <td class="num"><span class="<?= $l['stock'] <= 0 ? 'text-danger font-semibold' : ($l['stock'] <= $l['threshold'] ? 'text-warning font-semibold' : '') ?>"><?= $l['stock'] ?></span></td>
                    <td>
                        <form method="POST" action="<?= e(route('admin.inventory.adjust')) ?>" class="flex flex-wrap items-center gap-2">
                            <?= csrf_field() ?> <input type="hidden" name="target" value="<?= $l['target'] ?>"><input type="hidden" name="id" value="<?= $l['id'] ?>">
                            <select name="mode" class="select !min-h-[36px] !w-auto text-xs" aria-label="Action"><option value="add">Add</option><option value="remove">Remove</option><option value="set">Set to</option></select>
                            <input name="quantity" type="number" min="0" required class="input !min-h-[36px] !w-20 text-sm" aria-label="Quantity">
                            <select name="reason" class="select !min-h-[36px] !w-auto text-xs" aria-label="Reason"><option value="restock">Restock</option><option value="adjustment">Stock-take</option><option value="return">Customer return</option></select>
                            <input name="note" maxlength="190" placeholder="Note" class="input !min-h-[36px] !w-32 text-sm" aria-label="Note">
                            <button class="btn btn--sm !min-h-[36px]">Apply</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if (!$products['items']): ?>
            <tr><td colspan="4" class="text-center py-10 muted">No products found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<div class="mt-6"><?= View::partial('admin/partials/pagination', ['pager' => $products]) ?></div>
