<?php
/**
 * Order confirmation (port of storefront/order-confirmation.blade.php).
 * Vars: $order (OrderService::load row), $token (the private link token or null), $bankInstructions.
 */
use App\Services\OrderService;
use App\Services\Payments\CardGateway;

$first = explode(' ', trim((string) $order['name']))[0];
$ps = (string) $order['payment_status'];
?>
<div class="container py-12 lg:py-16 max-w-3xl">
    <div class="text-center">
        <span class="inline-grid place-content-center size-16 rounded-full bg-ivory text-ink"><?= icon('check', 30) ?></span>
        <h1 class="!text-3xl lg:!text-4xl mt-4">Thank you, <?= e($first) ?>.</h1>
        <p class="lead mx-auto">Your order <strong class="text-ink"><?= e($order['number']) ?></strong> has been received.</p>
    </div>

    <?php if (has_error('order')): ?><p class="alert alert--danger mt-6" role="alert"><?= e(error('order')) ?></p><?php endif; ?>
    <?php if ($order['payment_method'] === 'bank_transfer'): ?>
        <div class="card !bg-ivory !border-transparent mt-8">
            <h2 class="!text-lg">Complete your bank transfer</h2>
            <p class="text-sm">Please transfer <strong><?= e(money($order['total'])) ?></strong> and quote order number <strong><?= e($order['number']) ?></strong> as the reference. We start processing once the payment is verified.</p>
            <?php if ($bankInstructions): ?><pre class="mt-3 whitespace-pre-wrap font-body text-sm bg-white border border-line rounded p-4"><?= e($bankInstructions) ?></pre><?php endif; ?>
            <?php if ($ps === 'unpaid' || $ps === 'failed'): ?>
                <form method="POST" action="<?= e(route('order.payment-reference', ['number' => $order['number']])) ?>" enctype="multipart/form-data" class="mt-4 space-y-3">
                    <?= csrf_field() ?> <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="field">
                        <label class="label" for="ref">Already transferred? Enter your bank reference / transaction ID</label>
                        <input class="input" id="ref" name="reference" minlength="3" maxlength="120" value="<?= e(is_scalar(old('reference')) ? old('reference') : '') ?>"<?= has_error('reference') ? ' aria-invalid="true"' : '' ?>>
                    </div>
                    <div class="field">
                        <label class="label" for="proof">Or upload your payment receipt (JPG, PNG or PDF, up to 4 MB)</label>
                        <input class="input" id="proof" type="file" name="proof" accept="image/jpeg,image/png,application/pdf"<?= has_error('proof') ? ' aria-invalid="true"' : '' ?>>
                        <?php if (has_error('proof')): ?><p class="error-text"><?= e(error('proof')) ?></p><?php endif; ?>
                    </div>
                    <?php if (has_error('reference')): ?><p class="error-text" role="alert"><?= e(error('reference')) ?></p><?php endif; ?>
                    <button class="btn btn--sm">Submit payment details</button>
                    <p class="help">You can send the reference, the receipt, or both. This does not confirm your payment: our team verifies it against our bank account first.</p>
                </form>
            <?php elseif ($ps === 'awaiting_verification'): ?>
                <p class="alert alert--info mt-4">Thanks. We have your transfer reference and are verifying the payment.</p>
            <?php endif; ?>
        </div>
    <?php elseif ($order['payment_method'] === 'card'): ?>
        <div class="card !bg-ivory !border-transparent mt-8">
            <h2 class="!text-lg">Card payment</h2>
            <?php if ($ps === 'paid'): ?>
                <p class="alert alert--success">Payment received. Thank you.</p>
            <?php elseif ($ps === 'pending'): ?>
                <p class="alert alert--info">Your payment is being processed by our payment provider. This page does not mark it paid: we confirm it once the provider tells us it succeeded.</p>
            <?php elseif ($ps === 'failed'): ?>
                <p class="alert alert--danger">Your card payment did not go through. Your order is still saved, so you can try again.</p>
            <?php else: ?>
                <p class="text-sm">Your order is placed but not paid yet. Complete the card payment on our payment provider's secure page.</p>
            <?php endif; ?>
            <?php if (in_array($ps, ['unpaid', 'pending', 'failed'], true) && !in_array($order['status'], ['cancelled', 'returned'], true) && (new CardGateway())->isAvailable()): ?>
                <form method="POST" action="<?= e(route('order.pay', ['number' => $order['number']])) ?>" class="mt-4"><?= csrf_field() ?> <input type="hidden" name="token" value="<?= e($token) ?>"><button class="btn btn--sm"><?= $ps === 'failed' ? 'Try card payment again' : 'Pay by card' ?></button></form>
            <?php endif; ?>
        </div>
    <?php elseif ($order['payment_method'] === 'cod'): ?>
        <div class="alert alert--info mt-8">You chose <strong>Cash on Delivery</strong>. Please keep <?= e(money($order['total'])) ?> ready when your order arrives.</div>
    <?php endif; ?>

    <div class="card mt-8 space-y-6">
        <div class="grid gap-6 sm:grid-cols-2 text-sm">
            <div><h2 class="eyebrow mb-2">Delivering to</h2><p><?= e($order['name']) ?><br><?= e($order['address']) ?><br><?= e($order['city']) ?>, <?= e($order['province']) ?> <?= e($order['postal_code']) ?><br><?= e($order['phone']) ?></p></div>
            <div><h2 class="eyebrow mb-2">Status</h2><p><span class="badge badge--<?= e(OrderService::statusTone((string) $order['status'])) ?>"><?= e(OrderService::statusLabel((string) $order['status'])) ?></span> <span class="badge badge--ivory"><?= e(OrderService::paymentStatusLabel($ps)) ?></span></p><p class="mt-2 text-grey-text">Placed <?= e(dt($order['created_at'], 'j M Y, g:i a')) ?></p><?php if ($order['shipping_eta']): ?><p class="mt-1">Estimated delivery: <strong><?= e($order['shipping_eta']) ?></strong> <span class="text-grey-text">(<?= e($order['shipping_method_name']) ?>, an estimate)</span></p><?php endif; ?></div>
        </div>
        <ul class="divide-y divide-line border-y border-line">
            <?php foreach ($order['items'] as $item): ?>
                <li class="py-3 flex justify-between gap-4 text-sm"><span><?= e($item['name']) ?> <?php if ($item['options']): ?><span class="text-grey-text">· <?= e($item['options']) ?></span><?php endif; ?> × <?= (int) $item['quantity'] ?></span><span class="font-semibold"><?= e(money($item['total'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <dl class="space-y-1 text-sm ml-auto max-w-xs">
            <div class="flex justify-between"><dt>Subtotal</dt><dd><?= e(money($order['subtotal'])) ?></dd></div>
            <?php if ((int) $order['discount']): ?><div class="flex justify-between text-success"><dt>Discount (<?= e($order['coupon_code']) ?>)</dt><dd>− <?= e(money($order['discount'])) ?></dd></div><?php endif; ?>
            <div class="flex justify-between"><dt>Shipping</dt><dd><?= (int) $order['shipping_cost'] ? e(money($order['shipping_cost'])) : 'Free' ?></dd></div>
            <?php if ((int) $order['tax_amount']): ?><div class="flex justify-between text-grey-text"><dt>Includes <?= e($order['tax_label'] ?? 'tax') ?></dt><dd><?= e(money($order['tax_amount'])) ?></dd></div><?php endif; ?>
            <div class="flex justify-between border-t border-line pt-2 text-base font-bold font-display"><dt>Total</dt><dd><?= e(money($order['total'])) ?></dd></div>
        </dl>
    </div>

    <p class="text-sm text-grey-text text-center mt-6">Save your order number. You can follow your order any time from
        <a class="link" href="<?= e(route('track')) ?>">Track order</a><?php if ($user): ?> or <a class="link" href="<?= e(route_exists('account.orders.show') ? route('account.orders.show', $order) : url('/account/orders/' . rawurlencode((string) $order['number']))) ?>">your account</a><?php endif; ?>.</p>
    <div class="text-center mt-6"><a href="<?= e(route('shop')) ?>" class="btn btn--outline">Continue shopping</a></div>
</div>
