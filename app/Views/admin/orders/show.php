<?php
/**
 * Order detail (port of admin/orders/show.blade.php).
 * @var array $order (OrderService::load + history/payments/transactions/events/account) @var array $nextStatuses
 * @var bool $canVerify @var bool $canRefund @var array $couriers
 */
use App\Auth\Auth;
use App\Services\Admin\Display;
use App\Services\OrderService;

$cur = (string) cfg('abiha.currency.symbol');
$num = static fn ($v): string => number_format((int) $v);
$statusLabel = OrderService::statusLabel((string) $order['status']);
?>
<a href="<?= e(route('admin.orders.index')) ?>" class="link text-sm inline-flex items-center gap-1 mb-4"><?= icon('chevron-left', 14) ?> Orders</a>
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="!text-2xl !mb-0">Order <?= e($order['number']) ?></h1>
    <div class="flex items-center gap-2"><span class="badge badge--<?= e(OrderService::statusTone((string) $order['status'])) ?>"><?= e($statusLabel) ?></span><span class="badge badge--ivory"><?= e(OrderService::paymentStatusLabel((string) $order['payment_status'])) ?></span>
        <a href="<?= e(route('admin.orders.invoice', $order)) ?>" target="_blank" class="btn btn--sm btn--outline">Print invoice</a></div>
</div>
<p class="text-sm text-grey-text mt-1">Placed <?= e(dt((string) $order['created_at'], 'j M Y, g:i a')) ?> · <?= e(Display::words($order['payment_method'])) ?> · <?= e($order['shipping_method_name']) ?></p>

<div class="grid gap-6 lg:grid-cols-[1fr_360px] mt-6 items-start">
    <div class="space-y-6">
        <section class="card !p-0 overflow-x-auto">
            <table class="table"><thead><tr><th>Item</th><th>SKU</th><th class="num">Price</th><th class="num">Qty</th><th class="num">Total</th></tr></thead><tbody>
                <?php foreach ($order['items'] as $i): ?>
                    <tr><td><?= e($i['name']) ?> <?php if ($i['options']): ?><br><span class="text-xs text-grey-text"><?= e($i['options']) ?></span><?php endif; ?></td><td class="text-grey-text"><?= e($i['sku']) ?></td>
                        <td class="num"><?= $num($i['unit_price']) ?></td><td class="num"><?= (int) $i['quantity'] ?></td><td class="num"><?= $num($i['total']) ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>
            <dl class="p-4 ml-auto max-w-xs text-sm space-y-1">
                <div class="flex justify-between"><dt>Subtotal</dt><dd><?= e($cur) ?> <?= $num($order['subtotal']) ?></dd></div>
                <?php if ((int) $order['discount']): ?><div class="flex justify-between text-success"><dt>Discount <?php if ($order['coupon_code']): ?>(<?= e($order['coupon_code']) ?>)<?php endif; ?></dt><dd>− <?= e($cur) ?> <?= $num($order['discount']) ?></dd></div><?php endif; ?>
                <div class="flex justify-between"><dt>Shipping</dt><dd><?= e($cur) ?> <?= $num($order['shipping_cost']) ?></dd></div>
                <?php if ((int) $order['tax_amount']): ?><div class="flex justify-between text-grey-text"><dt>Includes <?= e($order['tax_label'] ?? 'tax') ?> (<?= e(Display::rate($order['tax_rate'])) ?>%)</dt><dd><?= e($cur) ?> <?= $num($order['tax_amount']) ?></dd></div><?php endif; ?>
                <div class="flex justify-between border-t border-line pt-2 font-bold font-display text-base"><dt>Total</dt><dd><?= e($cur) ?> <?= $num($order['total']) ?></dd></div>
            </dl>
        </section>

        <section class="card"><h2 class="!text-lg">History</h2>
            <ol class="space-y-3 text-sm border-l-2 border-line pl-4">
                <?php foreach ($order['history'] as $h): ?>
                    <li><strong><?= e(OrderService::STATUSES[$h['status']] ?? $h['status']) ?></strong> <span class="text-grey-text">· <?= e(dt((string) $h['created_at'], 'j M Y, g:i a')) ?><?php if ($h['user_name'] !== null): ?> · <?= e($h['user_name']) ?><?php endif; ?></span><?php if ($h['note']): ?><br><span class="text-grey-text"><?= e($h['note']) ?></span><?php endif; ?></li>
                <?php endforeach; ?>
            </ol></section>
    </div>

    <aside class="space-y-6">
        <section class="card text-sm"><h2 class="!text-lg">Customer</h2>
            <p><?= e($order['name']) ?><br><?= e($order['phone']) ?><br><a class="link" href="mailto:<?= e($order['email']) ?>"><?= e($order['email']) ?></a><?php if ($order['account']): ?><br><a class="link" href="<?= e(route('admin.customers.show', ['id' => $order['account']['id']])) ?>">View account</a><?php else: ?><br><span class="text-grey-text">Guest checkout</span><?php endif; ?></p>
            <h3 class="eyebrow mt-4 mb-1">Ship to</h3>
            <address class="not-italic"><?= e($order['address']) ?><br><?= e($order['city']) ?>, <?= e($order['province']) ?> <?= e($order['postal_code']) ?></address>
            <?php if ($order['notes']): ?><h3 class="eyebrow mt-4 mb-1">Customer note</h3><p><?= e($order['notes']) ?></p><?php endif; ?>
        </section>

        <?php if (Auth::can('orders.manage')): ?>
        <section class="card space-y-3"><h2 class="!text-lg">Process order</h2>
            <?php if ($nextStatuses): ?>
                <form method="POST" action="<?= e(route('admin.orders.status', $order)) ?>" class="space-y-2"><?= csrf_field() ?><?= method_field('PATCH') ?>
                    <label class="label" for="status">Move to</label>
                    <select class="select" id="status" name="status"><?php foreach ($nextStatuses as $s): ?><option value="<?= e($s) ?>"><?= e(OrderService::STATUSES[$s]) ?></option><?php endforeach; ?></select>
                    <input class="input" name="note" placeholder="Note (optional)" maxlength="190" aria-label="Note">
                    <?php if (in_array('returned', $nextStatuses, true)): ?><label class="check"><input type="checkbox" name="restock" value="1"> If marked Returned: put the items back in stock</label><?php endif; ?>
                    <p class="help">Cancelling returns the items to stock and frees any coupon use. Returned orders only restock if you tick the box (inspect goods first).</p>
                    <button class="btn btn--sm btn--block">Update status</button>
                </form>
            <?php else: ?><p class="muted text-sm">This order is <?= e(strtolower($statusLabel)) ?> and cannot change further.</p><?php endif; ?>
            <form method="POST" action="<?= e(route('admin.orders.tracking', $order)) ?>" class="space-y-2 pt-3 border-t border-line"><?= csrf_field() ?><?= method_field('PATCH') ?>
                <h3 class="font-semibold text-sm">Shipping</h3>
                <p class="help">Hand the parcel to your courier, then enter their details here. Customers see exactly what you type.</p>
                <?php if ($order['shipping_eta']): ?><p class="text-xs text-grey-text">Estimate shown to the customer: <?= e($order['shipping_eta']) ?></p><?php endif; ?>
                <label class="label" for="cr">Courier</label><input class="input" id="cr" name="courier" value="<?= e(old('courier', $order['courier'])) ?>" maxlength="80">
                <label class="label" for="tn">Tracking number</label><input class="input" id="tn" name="tracking_number" value="<?= e(old('tracking_number', $order['tracking_number'])) ?>" maxlength="120">
                <label class="label" for="tu">Tracking link</label><input class="input" id="tu" type="url" name="tracking_url" value="<?= e(old('tracking_url', $order['tracking_url'])) ?>" placeholder="https://">
                <?php if ($m = error('tracking_url')): ?><span class="error-text"><?= e($m) ?></span><?php endif; ?>
                <button class="btn btn--sm btn--outline btn--block">Save shipping details</button>
            </form>
            <?php if ($order['events']): ?>
                <details class="text-xs pt-2"><summary class="cursor-pointer text-grey-text">Shipment timeline (<?= count($order['events']) ?>)</summary>
                    <ol class="mt-2 space-y-1"><?php foreach ($order['events'] as $ev): ?><li><?= e(dt((string) $ev['occurred_at'], 'j M, g:i a')) ?> · <strong><?= e(Display::sentence($ev['status'])) ?></strong><?php if ($ev['description']): ?> · <?= e($ev['description']) ?><?php endif; ?> · <?= e($ev['user_name'] ?? ucfirst((string) $ev['source'])) ?></li><?php endforeach; ?></ol></details>
            <?php endif; ?>
            <?php if ($couriers && !$order['tracking_number'] && in_array($order['status'], ['confirmed', 'processing'], true)): ?>
                <form method="POST" action="<?= e(route('admin.orders.shipment', $order)) ?>" class="space-y-2 pt-3 border-t border-line"><?= csrf_field() ?>
                    <label class="label" for="bk">Book with a connected courier</label>
                    <select class="select" id="bk" name="courier"><?php foreach ($couriers as $c): ?><option value="<?= e($c->code()) ?>"><?= e($c->label()) ?></option><?php endforeach; ?></select>
                    <?php if ($m = error('shipment')): ?><span class="error-text" role="alert"><?= e($m) ?></span><?php endif; ?>
                    <button class="btn btn--sm btn--block" data-confirm="Book this shipment now?">Book shipment</button>
                </form>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="card space-y-3"><h2 class="!text-lg">Payment</h2>
            <p class="text-sm"><?= e(Display::words($order['payment_method'])) ?>: <strong><?= e(OrderService::paymentStatusLabel((string) $order['payment_status'])) ?></strong></p>
            <?php foreach ($order['payments'] as $p): ?>
                <p class="text-xs text-grey-text"><?= e(ucfirst((string) $p['status'])) ?> · <?= e($cur) ?> <?= $num($p['amount']) ?><?php if ($p['reference']): ?> · Ref: <?= e($p['reference']) ?><?php endif; ?> <?php if ($p['proof_path']): ?> · <a class="link" href="<?= e(route('admin.orders.payment.proof', ['number' => $order['number'], 'payment' => $p['id']])) ?>" target="_blank" rel="noopener">View receipt</a> (uploaded <?= e(dt($p['proof_submitted_at'], 'j M, g:i a')) ?>)<?php endif; ?> <?php if ($p['verifier_name'] !== null): ?> · verified by <?= e($p['verifier_name']) ?> <?= e(dt($p['verified_at'], 'j M, g:i a')) ?><?php endif; ?></p>
            <?php endforeach; ?>
            <?php if ($order['transactions']): ?>
                <details class="text-xs"><summary class="cursor-pointer text-grey-text">Payment history (<?= count($order['transactions']) ?>)</summary>
                    <ol class="mt-2 space-y-1"><?php foreach ($order['transactions'] as $t): ?><li><?= e(dt((string) $t['created_at'], 'j M, g:i a')) ?> · <?= e(Display::sentence($t['type'])) ?> · <strong><?= e(ucfirst((string) $t['status'])) ?></strong><?php if ($t['reference']): ?> · <?= e($t['reference']) ?><?php endif; ?> · <?= e($t['user_name'] ?? 'System') ?></li><?php endforeach; ?></ol></details>
            <?php endif; ?>
            <?php if (Auth::can('payments.verify')): ?>
                <?php if ($canVerify): ?>
                    <form method="POST" action="<?= e(route('admin.orders.payment.verify', $order)) ?>" class="space-y-2 pt-3 border-t border-line"><?= csrf_field() ?>
                        <p class="help">Confirm only after the money is really received (bank statement checked, or cash collected). The amount must equal the order total.</p>
                        <input class="input" name="reference" placeholder="Bank reference / receipt no." maxlength="120" aria-label="Reference">
                        <input class="input" type="number" name="amount" min="0" placeholder="Amount received (default <?= $num($order['total']) ?>)" aria-label="Amount received">
                        <input class="input" name="note" placeholder="Note (optional)" maxlength="190" aria-label="Note">
                        <button class="btn btn--sm btn--gold btn--block" data-confirm="Mark this order as PAID?">Mark as paid</button>
                    </form>
                    <form method="POST" action="<?= e(route('admin.orders.payment.reject', $order)) ?>" class="space-y-2"><?= csrf_field() ?>
                        <input class="input" name="note" placeholder="Why not received?" maxlength="190" aria-label="Reason">
                        <button class="btn btn--sm btn--outline btn--block">Payment not received</button>
                    </form>
                <?php endif; ?>
                <?php if ($canRefund): ?>
                    <form method="POST" action="<?= e(route('admin.orders.payment.refund', $order)) ?>" class="space-y-2 pt-3 border-t border-line"><?= csrf_field() ?>
                        <p class="help"><strong>Refund the customer first</strong> (bank transfer, wallet or provider dashboard). This button only records that it was done: it does not send any money.</p>
                        <input class="input" name="note" placeholder="Reference / note (optional)" maxlength="190" aria-label="Refund note">
                        <button class="btn btn--sm btn--outline btn--block" data-confirm="Record that this order was refunded?">Record refund</button>
                    </form>
                <?php endif; ?>
                <?php if ($order['payment_status'] !== 'paid' && !in_array($order['payment_method'], ['bank_transfer', 'cod'], true)): ?>
                    <p class="help">Online payments are confirmed automatically by the payment provider.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </aside>
</div>
