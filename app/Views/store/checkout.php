<?php
/**
 * Checkout (port of storefront/checkout.blade.php).
 * Vars: $summary (Cart::summary()), $shippingMethods (ShippingService::methods()), $gateways ([code => PaymentGateway]), $prefill.
 */
use App\Core\View;
use App\Support\Js;

$cfg = [
    'subtotal' => $summary['subtotal'], 'discount' => $summary['discount'],
    'shipping' => old('shipping_method', $summary['shipping_method']['code'] ?? null),
    'payment' => old('payment_method', array_key_first($gateways)),
    'province' => $prefill['province'], 'city' => $prefill['city'],
    'methods' => array_map(static fn ($m) => [
        'code' => $m['code'], 'cost' => (int) $m['cost'], 'free_over' => $m['free_over'] !== null ? (int) $m['free_over'] : null, 'eta' => $m['eta'],
        'rules' => array_map(static fn ($r) => ['province' => $r['province'], 'city' => $r['city'], 'cost' => (int) $r['cost'], 'free_over' => $r['free_over'] !== null ? (int) $r['free_over'] : null, 'eta' => $r['eta'], 'is_available' => (bool) $r['is_available']], $m['rules']),
    ], $shippingMethods),
];
$field = static fn (array $d): string => View::partial('store/cart/_field', $d);
$coupon = $summary['coupon'];
$couponErr = error('coupon');
?>
<div class="container py-10 lg:py-14" x-data="checkout(<?= Js::from($cfg) ?>)">
    <h1 class="!text-3xl lg:!text-4xl">Checkout</h1>

    <?= View::partial('store/cart/_form-errors', ['class' => 'mb-6']) ?>

    <form method="POST" action="<?= e(route('checkout.store')) ?>" class="grid gap-10 lg:grid-cols-[1fr_400px] mt-6" novalidate data-validate>
        <?= csrf_field() ?>
        <div class="space-y-10">
            <?php if (!$user): ?><p class="text-sm muted">Have an account? <a class="link" href="<?= e(route('login')) ?>">Sign in</a> for faster checkout. Guest checkout is fine too.</p><?php endif; ?>

            <section aria-labelledby="c-contact" class="space-y-4">
                <h2 id="c-contact" class="!text-xl">Contact</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <?= $field(['name' => 'name', 'label' => 'Full name', 'value' => $prefill['name'], 'required' => true, 'autocomplete' => 'name', 'class' => 'sm:col-span-2']) ?>
                    <?= $field(['name' => 'phone', 'label' => 'Phone number', 'type' => 'tel', 'value' => $prefill['phone'], 'required' => true, 'autocomplete' => 'tel', 'extra' => ['inputmode' => 'tel', 'placeholder' => '03XX XXXXXXX']]) ?>
                    <?= $field(['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $prefill['email'], 'required' => true, 'autocomplete' => 'email', 'help' => 'Your order confirmation is shown on screen; keep the order number.']) ?>
                </div>
            </section>

            <section aria-labelledby="c-ship" class="space-y-4">
                <h2 id="c-ship" class="!text-xl">Delivery address</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="field">
                        <label class="label" for="f-province">Province <span class="req" aria-hidden="true">*</span></label>
                        <select class="select" id="f-province" name="province" x-model="province" required autocomplete="address-level1"<?= has_error('province') ? ' aria-invalid="true"' : '' ?>>
                            <option value="">Select province</option>
                            <?php foreach ((array) cfg('abiha.provinces') as $pv): ?><option value="<?= e($pv) ?>"<?= $prefill['province'] === $pv ? ' selected' : '' ?>><?= e($pv) ?></option><?php endforeach; ?>
                        </select>
                        <?php if (has_error('province')): ?><span class="error-text"><?= e(error('province')) ?></span><?php endif; ?>
                    </div>
                    <?= $field(['name' => 'city', 'label' => 'City', 'value' => $prefill['city'], 'required' => true, 'autocomplete' => 'address-level2', 'extra' => ['x-model' => 'city']]) ?>
                    <div class="field sm:col-span-2">
                        <label class="label" for="f-address">Complete address <span class="req" aria-hidden="true">*</span></label>
                        <textarea class="textarea !min-h-[96px]" id="f-address" name="address" required autocomplete="street-address"<?= has_error('address') ? ' aria-invalid="true"' : '' ?>><?= e($prefill['address']) ?></textarea>
                        <?php if (has_error('address')): ?><span class="error-text"><?= e(error('address')) ?></span><?php endif; ?>
                    </div>
                    <?= $field(['name' => 'postal_code', 'label' => 'Postal code (optional)', 'value' => $prefill['postal_code'], 'autocomplete' => 'postal-code', 'extra' => ['inputmode' => 'numeric']]) ?>
                    <div class="field sm:col-span-2">
                        <label class="label" for="f-notes">Order notes (optional)</label>
                        <textarea class="textarea !min-h-[80px]" id="f-notes" name="notes" maxlength="500"><?= e(is_scalar(old('notes')) ? old('notes') : '') ?></textarea>
                    </div>
                </div>
                <?php if ($user): ?><label class="check"><input type="checkbox" name="save_address" value="1"<?= old('save_address', true) ? ' checked' : '' ?>> Save this address to my account</label><?php endif; ?>
            </section>

            <section aria-labelledby="c-method" class="space-y-4">
                <h2 id="c-method" class="!text-xl">Shipping method</h2>
                <div class="space-y-3" role="radiogroup">
                    <?php foreach ($shippingMethods as $m): ?>
                        <label class="card !p-4 flex items-start gap-3 cursor-pointer has-[:checked]:border-ink has-[:checked]:bg-ivory">
                            <input type="radio" class="mt-1 accent-ink" name="shipping_method" value="<?= e($m['code']) ?>" x-model="shipping" required>
                            <?php if (in_array($m['code'], ['dhl', 'fedex', 'ups'], true)): ?><span class="carrier-tile"><?= carrier_logo($m['code'], 26) ?></span><?php endif; ?>
                            <span class="flex-1"><span class="font-semibold block"><?= e($m['name']) ?></span><span class="text-sm text-grey-text"><span x-text="etaText('<?= e($m['code']) ?>')"><?= e($m['eta']) ?></span> <?= e($m['description'] ? '· ' . $m['description'] : '') ?></span>
                                <?php if ((int) $m['is_sample'] && cfg('abiha.show_sample_labels')): ?><span class="sample-flag ml-1">SAMPLE RATE</span><?php endif; ?></span>
                            <span class="font-semibold" x-text="quoteText('<?= e($m['code']) ?>')"></span>
                        </label>
                    <?php endforeach; ?>
                    <?php if (!$shippingMethods): ?>
                        <p class="alert alert--warning">Delivery options are not available right now. Please <a class="link" href="<?= e(route('contact')) ?>">contact us</a> and we will help you place your order.</p>
                    <?php endif; ?>
                </div>
                <?php if (has_error('shipping_method')): ?><p class="error-text"><?= e(error('shipping_method')) ?></p><?php endif; ?>
            </section>

            <section aria-labelledby="c-pay" class="space-y-4">
                <h2 id="c-pay" class="!text-xl">Payment</h2>
                <div class="space-y-3" role="radiogroup">
                    <?php foreach ($gateways as $g): ?>
                        <label class="card !p-4 flex items-start gap-3 cursor-pointer has-[:checked]:border-ink has-[:checked]:bg-ivory">
                            <input type="radio" class="mt-1 accent-ink" name="payment_method" value="<?= e($g->code()) ?>" x-model="payment" required>
                            <span><span class="font-semibold block"><?= e($g->label()) ?></span><span class="text-sm text-grey-text"><?= e($g->description()) ?></span></span>
                        </label>
                    <?php endforeach; ?>
                    <?php if (\App\Services\ContentPages::cardComingSoon()): ?>
                        <div class="card !p-4 flex items-start gap-3 opacity-60" aria-disabled="true" data-card-soon>
                            <input type="radio" class="mt-1" disabled aria-label="Debit / Credit Card, coming soon">
                            <span><span class="font-semibold block">Debit / Credit Card <span class="badge badge--ivory ml-1">Coming soon</span></span><span class="text-sm text-grey-text">Card payment will be available here shortly.</span></span>
                        </div>
                    <?php endif; ?>
                    <?php if (!$gateways): ?><p class="alert alert--warning">No payment methods are enabled yet.</p><?php endif; ?>
                </div>
                <?php if (has_error('payment_method')): ?><p class="error-text"><?= e(error('payment_method')) ?></p><?php endif; ?>
                <p class="text-sm text-grey-text" x-show="payment === 'bank_transfer'" x-cloak>Our bank details are shown on the next page after you place the order.</p>
            </section>
        </div>

        <aside class="card !bg-ivory !border-transparent h-fit lg:sticky lg:top-28 space-y-5" aria-label="Order summary">
            <h2 class="!text-xl !mb-0">Order summary</h2>
            <ul class="divide-y divide-line">
                <?php foreach ($summary['lines'] as $line): ?>
                    <li class="py-3 flex gap-3 text-sm">
                        <div class="relative size-16 shrink-0 overflow-hidden rounded bg-white border border-line">
                            <?= View::partial('store/cart/_thumb', ['product' => $line['product'], 'sizes' => '64px']) ?>
                            <span class="absolute -top-0 -right-0 bg-ink text-white text-[10px] size-5 grid place-content-center rounded-bl"><?= (int) $line['quantity'] ?></span>
                        </div>
                        <div class="flex-1 min-w-0"><p class="font-semibold truncate"><?= e($line['product']['name']) ?></p><?php if ($line['options']): ?><p class="text-grey-text text-xs"><?= e($line['options']) ?></p><?php endif; ?></div>
                        <p class="font-semibold"><?= e(money($line['total'])) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="text-sm">
                <input type="hidden" name="from" value="checkout">
                <?php if ($coupon): ?>
                    <p class="flex items-center justify-between rounded border border-line bg-white px-3 py-2">
                        <span class="inline-flex items-center gap-2"><?= icon('tag', 16) ?> <strong><?= e($coupon['code']) ?></strong> applied</span>
                        <button type="submit" class="underline text-grey-text" name="_method" value="DELETE" formaction="<?= e(route('cart.coupon.remove')) ?>" formnovalidate>Remove</button>
                    </p>
                <?php else: ?>
                    <label class="label" for="coupon">Coupon code</label>
                    <div class="input-group mt-1">
                        <input class="input" id="coupon" name="code" autocomplete="off" maxlength="40" @keydown.enter.prevent="$refs.applyCoupon.click()"<?= $couponErr ? ' aria-invalid="true"' : '' ?>>
                        <button type="submit" class="btn btn--sm !min-h-[48px]" x-ref="applyCoupon" formaction="<?= e(route('cart.coupon')) ?>" formnovalidate>Apply</button>
                    </div>
                    <?php if ($couponErr): ?><p class="error-text mt-1" role="alert"><?= e($couponErr) ?></p><?php endif; ?>
                <?php endif; ?>
                <?php if ($summary['coupon_error']): ?><p class="error-text mt-1" role="alert"><?= e($summary['coupon_error']) ?></p><?php endif; ?>
            </div>

            <dl class="space-y-2 text-sm">
                <div class="flex justify-between"><dt>Subtotal</dt><dd><?= e(money($summary['subtotal'])) ?></dd></div>
                <?php if ($summary['discount']): ?><div class="flex justify-between text-success"><dt>Discount</dt><dd>− <?= e(money($summary['discount'])) ?></dd></div><?php endif; ?>
                <div class="flex justify-between"><dt>Shipping</dt><dd x-text="shippingCost ? money(shippingCost) : 'Free'"></dd></div>
                <div class="flex justify-between border-t border-line pt-3 text-lg font-bold font-display"><dt>Total payable</dt><dd x-text="money(total)"><?= e(money($summary['total'])) ?></dd></div>
            </dl>
            <p class="alert alert--danger !text-xs" x-show="unavailable" x-cloak role="alert">This delivery method is not available to your city. Please choose another method.</p>
            <button class="btn btn--lg btn--block" :disabled="unavailable"<?= !$gateways || !$shippingMethods ? ' disabled' : '' ?>>Place order</button>
            <p class="text-xs text-grey-text text-center">By placing your order you agree to our <a class="link" href="<?= e(route('page.shipping')) ?>">shipping &amp; returns policy</a>.</p>
            <a href="<?= e(route('cart')) ?>" class="block text-center text-sm link">Edit bag / change coupon</a>
        </aside>
    </form>
</div>
