<?php
/** Loaded into the cart drawer (port of storefront/partials/cart-drawer.blade.php). Totals come from Cart::summary(). */
use App\Core\View;

if ($summary['empty']): ?>
    <?= View::partial('store/cart/_empty', ['compact' => true, 'icon' => 'shopping-bag', 'title' => 'Your bag is empty', 'text' => 'Add something you love and it will wait for you here.', 'href' => route('shop'), 'action' => 'Start shopping', 'class' => 'm-auto', 'attrs' => ' data-autofocus-target']) ?>
<?php else: ?>
    <ul class="min-h-0 flex-1 divide-y divide-line overflow-y-auto px-5">
        <?php foreach ($summary['lines'] as $line): $p = $line['product']; $purl = route('product', $p); ?>
            <li class="flex gap-4 py-4" x-data="drawerLine('<?= e(route('cart.update', ['key' => $line['key']])) ?>', '<?= e(route('cart.remove', ['key' => $line['key']])) ?>')" :class="busy &amp;&amp; 'opacity-60'">
                <a href="<?= e($purl) ?>" class="relative block aspect-[4/5] w-20 shrink-0 overflow-hidden rounded bg-ivory border border-line">
                    <?= View::partial('store/cart/_thumb', ['product' => $p, 'sizes' => '80px']) ?>
                </a>
                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-3">
                        <a href="<?= e($purl) ?>" class="font-display font-semibold text-sm no-underline hover:underline"><?= e($p['name']) ?></a>
                        <button type="button" class="-mr-1 grid size-8 shrink-0 place-content-center rounded text-grey-text hover:text-ink" @click="remove()" aria-label="Remove <?= e($p['name']) ?> from your bag"><?= icon('trash-2', 16) ?></button>
                    </div>
                    <?php if ($line['options']): ?><p class="text-xs text-grey-text"><?= e($line['options']) ?></p><?php endif; ?>
                    <?php if ($line['notice']): ?><p class="mt-1 text-xs text-warning"><?= e($line['notice']) ?></p><?php endif; ?>
                    <div class="mt-3 flex items-center justify-between">
                        <?php if ($line['stock'] > 0): ?>
                            <div class="inline-flex items-center rounded-md border border-input" role="group" aria-label="Quantity for <?= e($p['name']) ?>">
                                <button type="button" class="grid size-9 place-content-center" @click="set(<?= $line['quantity'] - 1 ?>)" :disabled="busy" aria-label="Decrease quantity"><?= icon('minus', 14) ?></button>
                                <output class="w-7 text-center text-sm font-semibold"><?= (int) $line['quantity'] ?></output>
                                <button type="button" class="grid size-9 place-content-center" @click="set(<?= $line['quantity'] + 1 ?>)" :disabled="busy || <?= $line['quantity'] >= min($line['stock'], 20) ? 'true' : 'false' ?>" aria-label="Increase quantity"><?= icon('plus', 14) ?></button>
                            </div>
                        <?php else: ?><span></span><?php endif; ?>
                        <p class="price !mb-0 text-sm"><?= e(money($line['stock'] > 0 ? $line['total'] : 0)) ?></p>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <footer class="space-y-3 border-t border-line bg-white px-5 py-4">
        <div class="flex items-center justify-between font-display font-bold"><span>Subtotal</span><span><?= e(money($summary['subtotal'])) ?></span></div>
        <p class="text-xs text-grey-text">Shipping and coupons are applied at checkout.</p>
        <a href="<?= e(route('checkout')) ?>" class="btn btn--lg btn--block" data-autofocus>Checkout</a>
        <a href="<?= e(route('cart')) ?>" class="block text-center text-sm link">View full bag</a>
    </footer>
<?php endif;
