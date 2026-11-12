<?php
/** Customer detail (port of admin/customers/show.blade.php). @var array $customer @var array $addresses @var array $orders @var int $spent @var int $orderCount */
use App\Services\OrderService;

$cur = (string) cfg('abiha.currency.symbol');
$active = (int) $customer['is_active'] === 1;
?>
<a href="<?= e(route('admin.customers.index')) ?>" class="link text-sm inline-flex items-center gap-1 mb-4"><?= icon('chevron-left', 14) ?> Customers</a>
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="!text-2xl !mb-0"><?= e($customer['name']) ?></h1>
    <form method="POST" action="<?= e(route('admin.customers.active', ['id' => $customer['id']])) ?>" data-confirm="<?= $active ? 'Block this customer from signing in?' : 'Allow this customer to sign in again?' ?>"><?= csrf_field() ?><?= method_field('PATCH') ?>
        <button class="btn btn--sm <?= $active ? 'btn--outline' : '' ?>"><?= $active ? 'Block sign-in' : 'Unblock' ?></button></form>
</div>
<div class="grid gap-4 sm:grid-cols-3 mt-4">
    <div class="stat"><span>Orders</span><b><?= (int) $orderCount ?></b></div>
    <div class="stat"><span>Lifetime spend</span><b><?= e($cur) ?> <?= e(number_format($spent)) ?></b></div>
    <div class="stat"><span>Last sign-in</span><b class="!text-base"><?= $customer['last_login_at'] ? e(dt($customer['last_login_at'], 'j M Y')) : 'Never' ?></b></div>
</div>
<div class="grid gap-6 lg:grid-cols-[1fr_340px] mt-6 items-start">
    <section class="card !p-0 overflow-x-auto"><h2 class="!text-lg p-4 !mb-0">Orders</h2>
        <table class="table"><thead><tr><th>Order</th><th>Date</th><th>Status</th><th class="num">Total</th></tr></thead><tbody>
            <?php foreach ($orders as $o): ?><tr><td><a class="link" href="<?= e(route('admin.orders.show', $o)) ?>"><?= e($o['number']) ?></a></td><td><?= e(dt($o['created_at'], 'j M Y')) ?></td><td><span class="badge badge--<?= e(OrderService::statusTone((string) $o['status'])) ?>"><?= e(OrderService::statusLabel((string) $o['status'])) ?></span></td><td class="num"><?= e($cur) ?> <?= e(number_format((int) $o['total'])) ?></td></tr><?php endforeach; ?>
            <?php if (!$orders): ?><tr><td colspan="4" class="text-center py-8 muted">No orders.</td></tr><?php endif; ?>
        </tbody></table></section>
    <aside class="space-y-6 text-sm">
        <section class="card"><h2 class="!text-lg">Contact</h2><p><?= e($customer['email']) ?><br><?= e($customer['phone'] ?? 'No phone') ?><br>Joined <?= e(dt($customer['created_at'], 'j M Y')) ?></p></section>
        <section class="card"><h2 class="!text-lg">Addresses</h2>
            <?php foreach ($addresses as $a): ?><address class="not-italic mb-3"><?= e($a['name']) ?> <?php if ((int) $a['is_default'] === 1): ?><span class="badge badge--gold">Default</span><?php endif; ?><br><?= e($a['address']) ?>, <?= e($a['city']) ?>, <?= e($a['province']) ?><br><?= e($a['phone']) ?></address><?php endforeach; ?>
            <?php if (!$addresses): ?><p class="muted">None saved.</p><?php endif; ?></section>
    </aside>
</div>
