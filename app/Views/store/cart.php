<?php
/** The bag page (port of storefront/cart.blade.php). Vars: $summary (Cart::summary()). */
use App\Core\View;

$m = $summary['shipping_method'];
?>
<div class="container py-10 lg:py-14">
    <h1 class="!text-3xl lg:!text-4xl">Your bag <?php if (!$summary['empty']): ?><span class="text-grey-text text-xl font-normal">(<?= (int) $summary['count'] ?>)</span><?php endif; ?></h1>

    <?php if ($summary['empty']): ?>
        <?= View::partial('store/cart/_empty', ['icon' => 'shopping-bag', 'title' => 'Your bag is empty', 'text' => 'Add something you love and it will wait for you here.', 'action' => 'Continue shopping', 'href' => route('shop')]) ?>
    <?php else: ?>
        <?php foreach ($summary['notices'] as $n): ?><div class="alert alert--warning mb-3" role="alert"><?= e($n) ?></div><?php endforeach; ?>

        <div class="grid gap-10 lg:grid-cols-[1fr_380px] mt-6">
            <ul class="divide-y divide-line border-y border-line">
                <?php foreach ($summary['lines'] as $line): $p = $line['product']; $purl = route('product', $p); ?>
                    <li class="py-6 grid grid-cols-[96px_1fr] gap-4 sm:grid-cols-[120px_1fr_auto] sm:gap-6" x-data="cartLine('<?= e(route('cart.update', ['key' => $line['key']])) ?>')">
                        <a href="<?= e($purl) ?>" class="relative block aspect-[4/5] overflow-hidden rounded bg-ivory border border-line">
                            <?= View::partial('store/cart/_thumb', ['product' => $p, 'sizes' => '120px']) ?>
                        </a>
                        <div class="min-w-0">
                            <a href="<?= e($purl) ?>" class="font-display font-semibold no-underline hover:underline"><?= e($p['name']) ?></a>
                            <?php if ($line['options']): ?><p class="text-sm text-grey-text mt-1"><?= e($line['options']) ?></p><?php endif; ?>
                            <p class="text-xs text-grey-text">SKU: <?= e($line['sku']) ?></p>
                            <p class="price mt-2"><?= e(money($line['unit_price'])) ?> <?php if ($line['compare_price']): ?><s class="text-sm"><?= e(money($line['compare_price'])) ?></s><?php endif; ?></p>

                            <div class="mt-3 flex flex-wrap items-center gap-4">
                                <?php if ($line['stock'] > 0): ?>
                                    <div class="inline-flex items-center border border-input rounded-md" role="group" aria-label="Quantity for <?= e($p['name']) ?>">
                                        <button type="button" class="size-10 grid place-content-center" @click="set(<?= $line['quantity'] - 1 ?>)" :disabled="busy" aria-label="Decrease quantity"><?= icon('minus', 14) ?></button>
                                        <output class="w-8 text-center text-sm font-semibold"><?= (int) $line['quantity'] ?></output>
                                        <button type="button" class="size-10 grid place-content-center" @click="set(<?= $line['quantity'] + 1 ?>)" :disabled="busy || <?= $line['quantity'] >= min($line['stock'], 20) ? 'true' : 'false' ?>" aria-label="Increase quantity"><?= icon('plus', 14) ?></button>
                                    </div>
                                <?php endif; ?>
                                <form method="POST" action="<?= e(route('cart.remove', ['key' => $line['key']])) ?>"><?= csrf_field() ?><?= method_field('DELETE') ?>
                                    <button class="text-sm underline text-grey-text hover:text-ink inline-flex items-center gap-1"><?= icon('trash-2', 15) ?> Remove</button>
                                </form>
                            </div>
                        </div>
                        <p class="price col-start-2 sm:col-start-3 sm:text-right"><?= e(money($line['stock'] > 0 ? $line['total'] : 0)) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>

            <aside class="card !bg-ivory !border-transparent h-fit lg:sticky lg:top-28 space-y-5" aria-label="Order summary">
                <h2 class="!text-xl !mb-0">Order summary</h2>

                <?php if ($summary['coupon']): ?>
                    <div class="flex items-center justify-between gap-3 rounded-md bg-white border border-line px-3 py-2 text-sm">
                        <span class="inline-flex items-center gap-2"><?= icon('tag', 16) ?> <strong><?= e($summary['coupon']['code']) ?></strong></span>
                        <form method="POST" action="<?= e(route('cart.coupon.remove')) ?>"><?= csrf_field() ?><?= method_field('DELETE') ?><button class="underline text-grey-text">Remove</button></form>
                    </div>
                <?php else: ?>
                    <form method="POST" action="<?= e(route('cart.coupon')) ?>">
                        <?= csrf_field() ?>
                        <label class="label" for="coupon">Coupon code</label>
                        <?php $cerr = error('coupon') ?? error('code'); ?>
                        <div class="input-group mt-1"><input class="input" id="coupon" name="code" value="<?= e(is_scalar(old('code')) ? old('code') : '') ?>" autocomplete="off"<?= $cerr ? ' aria-invalid="true"' : '' ?>><button class="btn btn--sm !min-h-[48px]">Apply</button></div>
                        <?php if ($cerr): ?><p class="error-text mt-1" role="alert"><?= e($cerr) ?></p><?php endif; ?>
                        <?php if ($summary['coupon_error']): ?><p class="error-text mt-1" role="alert"><?= e($summary['coupon_error']) ?></p><?php endif; ?>
                    </form>
                <?php endif; ?>

                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt>Subtotal</dt><dd><?= e(money($summary['subtotal'])) ?></dd></div>
                    <?php if ($summary['discount']): ?><div class="flex justify-between text-success"><dt>Discount</dt><dd>− <?= e(money($summary['discount'])) ?></dd></div><?php endif; ?>
                    <div class="flex justify-between"><dt>Shipping <?php if ($m): ?><span class="text-grey-text">(<?= e($m['name']) ?><?php if ((int) $m['is_sample'] && cfg('abiha.show_sample_labels')): ?>, sample rate <?php endif; ?>)</span><?php endif; ?></dt>
                        <dd><?= $summary['shipping'] ? e(money($summary['shipping'])) : 'Free' ?></dd></div>
                    <div class="flex justify-between border-t border-line pt-3 text-base font-bold font-display"><dt>Total</dt><dd><?= e(money($summary['total'])) ?></dd></div>
                </dl>
                <?php $payLabels = \App\Services\ContentPages::paymentLabels(); $soon = \App\Services\ContentPages::cardComingSoon(); ?>
                <?php if ($payLabels || $soon): ?><p class="text-xs text-grey-text flex items-center gap-2" data-pay-note><?= icon('credit-card', 16) ?><span>Pay by <?= e(\App\Services\ContentPages::join($payLabels, ', ', ' or ')) ?><?= $soon ? ($payLabels ? '. ' : '') . 'Debit / Credit Card coming soon' : '' ?></span></p><?php endif; ?>
                <p class="text-xs text-grey-text">Shipping is an estimate. You can choose your delivery method at checkout.</p>
                <a href="<?= e(route('checkout')) ?>" class="btn btn--lg btn--block">Checkout</a>
                <a href="<?= e(route('shop')) ?>" class="block text-center text-sm link">Continue shopping</a>
            </aside>
        </div>
    <?php endif; ?>
</div>
