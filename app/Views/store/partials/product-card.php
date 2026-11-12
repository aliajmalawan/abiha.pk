<?php
/**
 * Product card (port of <x-product-card>). Vars: $product (Catalog card), $wishlistRemove = false, $eager = false,
 * $swatches = false, $reveal = false (adds data-reveal="data-reveal" like the old attribute bag).
 */
use App\Services\Catalog;
use App\Support\Img;

$p = $product;
$wishlistRemove = !empty($wishlistRemove);
$eager = !empty($eager);
$swatches = !empty($swatches);
$img0 = Catalog::imagePath($p, 0);
$img1 = Catalog::imagePath($p, 1);
$cardSizes = '(min-width:1024px) 25vw, (min-width:640px) 33vw, 50vw';
$addUrl = route_exists('cart.add') ? route('cart.add') : url('/cart/items');
$cartUrl = route('cart');
$quickUrl = route('product.quick', $p);
$label = $p['badge_label'];
?>
<article class="product-card group"<?= !empty($reveal) ? ' data-reveal="data-reveal"' : '' ?> x-data="quickAdd(<?= (int) $p['id'] ?>, '<?= e($addUrl) ?>', '<?= e($cartUrl) ?>')">
    <div class="product-card__media">
        <?php if ($img0): ?>
            <?= Img::picture($img0, (string) ($p['images'][0]['alt'] ?: $p['name']), $cardSizes, $eager, false, ['class' => trim('absolute inset-0 transition-opacity duration-500 ' . ($img1 ? 'group-hover:opacity-0' : ''))]) ?>
            <?php if ($img1): ?><?= Img::picture($img1, '', $cardSizes, false, false, ['class' => 'absolute inset-0 opacity-0 transition-opacity duration-500 group-hover:opacity-100']) ?><?php endif; ?>
        <?php else: ?>
            <?= App\Core\View::partial('components/placeholder') ?>
        <?php endif; ?>

        <?php if ($label): ?>
            <span class="badge <?= $label === 'Sold out' ? '' : 'badge--gold' ?> product-card__badge" data-badge="<?= e($label) ?>"><?= e($label) ?><?php if ($label === 'Sale' && $p['discount_percent']): ?> −<?= (int) $p['discount_percent'] ?>%<?php endif; ?></span>
        <?php endif; ?>

        <div x-data="wishButton(<?= (int) $p['id'] ?>, '<?= e(route('wishlist.toggle', $p)) ?>')" class="contents">
            <button type="button" class="product-card__wish" @click.prevent="toggle()" :aria-pressed="active" aria-label="Toggle wishlist for <?= e($p['name']) ?>">
                <?= icon('heart', 20, [':class' => "active ? 'fill-gold text-gold' : ''"]) ?>
            </button>
        </div>

        <?php /* Quick actions (always visible on touch, slide up on hover for desktop) */ ?>
        <?php if ($p['in_stock']): ?>
            <div class="absolute inset-x-2 bottom-2 z-[1] flex gap-2 transition duration-300 lg:translate-y-2 lg:opacity-0 lg:group-hover:translate-y-0 lg:group-hover:opacity-100 lg:group-focus-within:translate-y-0 lg:group-focus-within:opacity-100">
                <?php if ($p['has_variants']): ?>
                    <button type="button" class="btn btn--sm btn--block" @click.prevent="$store.quick.show('<?= e($quickUrl) ?>')">Choose options</button>
                <?php else: ?>
                    <button type="button" class="btn btn--sm flex-1" :class="{ 'is-loading': busy, 'is-done': done }" @click.prevent="add()"><span class="btn-label">Quick add</span><span class="btn-done"><?= icon('check', 16) ?> Added</span></button>
                    <button type="button" class="btn btn--sm btn--light !px-3" @click.prevent="$store.quick.show('<?= e($quickUrl) ?>')" aria-label="Quick view <?= e($p['name']) ?>"><?= icon('eye', 18) ?></button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <div>
        <?php if ($p['category']): ?><span class="product-card__cat"><?= e($p['category']['name']) ?></span><?php endif; ?>
        <h3 class="product-card__title"><a href="<?= e(route('product', $p)) ?>"><?= e($p['name']) ?></a></h3>
        <?= Catalog::priceHtml($p) ?>
        <?php if ($swatches && count($p['colors'])): ?>
            <ul class="flex items-center gap-1.5 mt-2" aria-label="Colours">
                <?php foreach (array_slice($p['colors'], 0, 5) as $c): ?><li class="size-3.5 rounded-full border border-black/15" style="background:<?= e($c['hex'] ?: '#ccc') ?>" title="<?= e($c['name']) ?>"></li><?php endforeach; ?>
                <?php if (count($p['colors']) > 5): ?><li class="text-xs text-grey-text">+<?= count($p['colors']) - 5 ?></li><?php endif; ?>
            </ul>
        <?php endif; ?>
        <?php if (!empty($p['is_sample']) && cfg('abiha.show_sample_labels')): ?><span class="sample-flag mt-1">SAMPLE</span><?php endif; ?>
    </div>
    <?php if ($wishlistRemove): ?>
        <div class="mt-3 flex gap-2 relative z-[2]">
            <?php if ($p['in_stock']): ?>
                <form method="POST" action="<?= e(route('wishlist.move', $p)) ?>" class="flex-1"><?= csrf_field() ?><button class="btn btn--sm btn--block">Move to bag</button></form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</article>
