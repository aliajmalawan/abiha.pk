<?php
/** Customers list (port of admin/customers/index.blade.php). @var array $customers (Pager) @var array $filters */
use App\Core\View;

$cur = (string) cfg('abiha.currency.symbol');
$fv = static fn (string $k): string => is_scalar($filters[$k] ?? null) ? (string) $filters[$k] : '';
?>
<div class="flex flex-wrap items-center justify-between gap-3"><h1 class="!text-2xl !mb-0">Customers</h1><a href="<?= e(route('admin.customers.export')) ?>" class="btn btn--sm btn--outline">Export CSV</a></div>
<form method="GET" class="my-4 flex flex-wrap gap-2" role="search">
    <input name="q" value="<?= e($fv('q')) ?>" class="input !min-h-[40px] max-w-xs" placeholder="Name, email or phone" aria-label="Search">
    <select name="status" class="select !min-h-[40px] !w-auto" aria-label="Status"><option value="">All</option><option value="active"<?= $fv('status') === 'active' ? ' selected' : '' ?>>Active</option><option value="blocked"<?= $fv('status') === 'blocked' ? ' selected' : '' ?>>Blocked</option></select>
    <button class="btn btn--sm">Filter</button>
</form>
<div class="card !p-0 overflow-x-auto">
    <table class="table"><thead><tr><th>Customer</th><th>Phone</th><th>Joined</th><th class="num">Orders</th><th class="num">Spent</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($customers['items'] as $c): ?>
            <tr><td><a class="link" href="<?= e(route('admin.customers.show', ['id' => $c['id']])) ?>"><?= e($c['name']) ?></a><br><span class="text-xs text-grey-text"><?= e($c['email']) ?></span></td><td><?= e($c['phone'] ?? '-') ?></td>
                <td><?= e(dt($c['created_at'], 'j M Y')) ?></td><td class="num"><?= (int) $c['orders_count'] ?></td><td class="num"><?= e($cur) ?> <?= e(number_format((int) $c['spent'])) ?></td>
                <td><span class="badge <?= (int) $c['is_active'] === 1 ? 'badge--success' : 'badge--danger' ?>"><?= (int) $c['is_active'] === 1 ? 'Active' : 'Blocked' ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$customers['items']): ?><tr><td colspan="6" class="text-center py-10 muted">No customers found.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<div class="mt-6"><?= View::partial('admin/partials/pagination', ['pager' => $customers]) ?></div>
