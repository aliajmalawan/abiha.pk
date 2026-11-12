<?php
/** Shipments list (port of admin/shipments/index.blade.php). @var array $shipments (Pager) @var array $filters */
use App\Core\View;

$fv = static fn (string $k): string => is_scalar($filters[$k] ?? null) ? (string) $filters[$k] : '';
?>
<h1 class="!text-2xl">Shipments</h1>
<p class="help mt-1">A shipment appears here when you add courier details to an order or mark it shipped. Open the order to change them.</p>

<form method="GET" class="flex flex-wrap gap-3 items-end mt-4" role="search">
    <div class="field"><label class="label text-xs" for="q">Search</label><input class="input" id="q" name="q" value="<?= e($fv('q')) ?>" placeholder="Order, tracking number or courier"></div>
    <div class="field"><label class="label text-xs" for="status">Status</label>
        <select class="select" id="status" name="status"><option value="">All</option><?php foreach (['pending' => 'Pending', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'returned' => 'Returned'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $fv('status') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn--sm !min-h-[48px]">Filter</button>
</form>

<div class="card !p-0 mt-4 overflow-x-auto">
    <table class="table"><thead><tr><th>Order</th><th>Courier</th><th>Tracking</th><th>Status</th><th>Shipped</th><th>Delivered</th></tr></thead><tbody>
        <?php foreach ($shipments['items'] as $s): ?>
            <tr>
                <td><?php if ($s['order_number'] !== null): ?><a class="link" href="<?= e(route('admin.orders.show', ['number' => $s['order_number']])) ?>"><?= e($s['order_number']) ?></a><?php else: ?> - <?php endif; ?></td>
                <td><?= e($s['courier'] ?: '-') ?></td>
                <td><?php if ($s['tracking_url'] && $s['tracking_number']): ?><a class="link" href="<?= e($s['tracking_url']) ?>" target="_blank" rel="noopener"><?= e($s['tracking_number']) ?></a><?php else: ?><?= e($s['tracking_number'] ?: '-') ?><?php endif; ?></td>
                <td><span class="badge <?= $s['status'] === 'delivered' ? 'badge--success' : ($s['status'] === 'shipped' ? 'badge--info' : 'badge--ivory') ?>"><?= e(ucfirst((string) $s['status'])) ?></span></td>
                <td><?= $s['shipped_at'] ? e(dt((string) $s['shipped_at'], 'j M Y, g:i a')) : '-' ?></td>
                <td><?= $s['delivered_at'] ? e(dt((string) $s['delivered_at'], 'j M Y, g:i a')) : '-' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$shipments['items']): ?>
            <tr><td colspan="6" class="text-center py-8 muted">No shipments yet.</td></tr>
        <?php endif; ?>
    </tbody></table>
</div>
<div class="mt-4"><?= View::partial('admin/partials/pagination', ['pager' => $shipments]) ?></div>
