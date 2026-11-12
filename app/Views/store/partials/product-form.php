<?php
/**
 * Port of <x-product-form>: one Alpine component (productForm) drives price, stock and SKU from the selected variant.
 * The server re-validates everything on add. Vars: $product (Catalog card), $compact = false.
 */
use App\Services\Catalog;
use App\Support\HtmlSanitizer;
use App\Support\Js;

$p = $product;
$compact = !empty($compact);
$addUrl = route_exists('cart.add') ? route('cart.add') : url('/cart/items');
// Everything below is ALSO drawn by the server, so the old price, the saving and "From" are right before JavaScript runs.
$sym = cfg('abiha.currency.symbol');
$shown = $p['display_price'];
$cmp = $p['display_compare_price'];
$disc = $cmp && $cmp > $shown ? (int) round((1 - $shown / $cmp) * 100) : 0;
$hasFrom = $p['has_variants'] && count(array_unique(array_map(static fn ($v) => $v['effective_price'], $p['variants']))) > 1;
?>
<div x-data="productForm({ productId: <?= (int) $p['id'] ?>, payload: <?= Js::from(Catalog::variantPayload($p)) ?>, addUrl: '<?= e($addUrl) ?>', cartUrl: '<?= e(route('cart')) ?>' })" class="space-y-6">
    <div>
        <div class="flex flex-wrap items-baseline gap-3">
            <p class="price !text-3xl !mb-0" aria-live="polite">
                <span x-show="fromLabel" <?php if (!$hasFrom): ?> x-cloak <?php endif; ?> class="text-sm font-normal text-grey-text">From </span><span x-text="money(price)"><?= e($sym) ?> <?= number_format($shown) ?></span>
            </p>
            <s class="text-grey-text" x-show="compare" <?php if (!($cmp && $cmp > $shown)): ?> x-cloak <?php endif; ?> x-text="compare ? money(compare) : ''"><?php if ($cmp && $cmp > $shown): ?><?= e($sym) ?> <?= number_format($cmp) ?><?php endif; ?></s>
            <span class="badge badge--gold" x-show="discount > 0" <?php if (!($disc > 0)): ?> x-cloak <?php endif; ?> x-text="'Save ' + discount + '%'"><?php if ($disc > 0): ?>Save <?= $disc ?>%<?php endif; ?></span>
        </div>
        <p class="mt-2 text-sm flex flex-wrap items-center gap-x-4 gap-y-1">
            <span class="inline-flex items-center gap-1.5" :class="!complete ? 'text-grey-text' : (inStock ? 'text-success' : 'text-danger')">
                <span class="size-2 rounded-full bg-current"></span><span x-text="stockLabel"><?= $p['in_stock'] ? 'In stock' : 'Out of stock' ?></span>
            </span>
            <span class="text-grey-text">SKU: <span x-text="sku"><?= e($p['sku']) ?></span></span>
        </p>
    </div>

    <template x-if="colors.length">
        <fieldset>
            <legend class="label mb-2">Colour<span class="font-normal text-grey-text">: <span x-text="color ?? 'Select'"></span></span></legend>
            <div class="flex flex-wrap gap-3" role="radiogroup">
                <template x-for="c in colors" :key="c.name">
                    <button type="button" role="radio" class="swatch" :aria-checked="color === c.name" :aria-label="c.name + (optionAvailable('color', c.name) ? '' : ' (unavailable)')"
                            :style="`--c:${c.hex || '#ccc'}`" :class="!optionAvailable('color', c.name) && 'opacity-35 line-through'" @click="pick('color', c.name)"></button>
                </template>
            </div>
        </fieldset>
    </template>

    <template x-if="sizes.length">
        <fieldset>
            <legend class="label mb-2">Size<span class="font-normal text-grey-text">: <span x-text="size ?? 'Select'"></span></span></legend>
            <div class="flex flex-wrap gap-2" role="radiogroup">
                <template x-for="s in sizes" :key="s">
                    <button type="button" role="radio" class="chip" :aria-checked="size === s" :disabled="!optionAvailable('size', s)" @click="pick('size', s)" x-text="s"></button>
                </template>
            </div>
            <?php if (!$compact && trim((string) setting('size.guide')) !== ''): ?>
                <button type="button" class="link hit text-xs mt-2" aria-haspopup="dialog" @click="$dispatch('open-size-guide')">Size guide</button>
            <?php endif; ?>
        </fieldset>
    </template>

    <p class="error-text" x-show="error" x-text="error" role="alert"></p>

    <div class="flex items-stretch gap-2 sm:gap-3">
        <div class="inline-flex items-center border border-input rounded-md" role="group" aria-label="Quantity">
            <button type="button" class="size-11 grid place-content-center" @click="dec()" aria-label="Decrease quantity" :disabled="qty <= 1"><?= icon('minus', 16) ?></button>
            <output class="w-8 text-center font-semibold" x-text="qty" aria-live="polite">1</output>
            <button type="button" class="size-11 grid place-content-center" @click="inc()" aria-label="Increase quantity" :disabled="qty >= Math.min(20, stock || 1)"><?= icon('plus', 16) ?></button>
        </div>
        <button type="button" data-main-add class="btn flex-1 min-w-0 !px-3" :class="{ 'is-loading': busy, 'is-done': justAdded }" :disabled="complete && !inStock" @click="add(false)">
            <span class="btn-label" x-text="complete && !inStock ? 'Sold out' : 'Add to bag'">Add to bag</span>
            <span class="btn-done"><?= icon('check', 16) ?> Added</span>
        </button>
        <div x-data="wishButton(<?= (int) $p['id'] ?>, '<?= e(route('wishlist.toggle', $p)) ?>')">
            <button type="button" class="btn btn--outline !px-3 h-full" @click="toggle()" :aria-pressed="active" aria-label="Toggle wishlist"><?= icon('heart', 20, [':class' => "active ? 'fill-gold text-gold' : ''"]) ?></button>
        </div>
    </div>
    <button type="button" class="btn btn--gold btn--block" :disabled="complete && !inStock" @click="add(true)">Buy now</button>

    <?php if (!$compact): ?>
        <?php /* Phones: once the main button has scrolled out of view, the price and an add-to-bag button stay at the bottom of the screen. */ ?>
        <div x-data="{ show: false }" x-init="
                const main = document.querySelector('[data-main-add]');
                if (main && 'IntersectionObserver' in window) { new IntersectionObserver(([e]) => { show = !e.isIntersecting; }, { threshold: 0.1 }).observe(main); }
                $watch('show', (v) => { document.body.style.paddingBottom = v && window.matchMedia('(max-width: 1023px)').matches ? '84px' : ''; });">
            <div x-cloak x-show="show" x-transition.opacity.duration.150ms role="region" aria-label="Add to bag"
                 class="lg:hidden fixed inset-x-0 bottom-0 z-[90] bg-white border-t border-line shadow-lift px-4 pt-3" style="padding-bottom:max(.75rem, var(--safe-bottom))">
                <div class="flex items-center gap-3">
                    <div class="min-w-0"><p class="text-xs text-grey-text truncate"><?= e($p['name']) ?></p><p class="font-bold" x-text="money(price)"><?= e($sym) ?> <?= number_format($p['price']) ?></p></div>
                    <button type="button" class="btn flex-1 !min-h-[48px]" :class="{ 'is-loading': busy, 'is-done': justAdded }" :disabled="complete && !inStock"
                            @click="if (!complete) { add(false); document.querySelector('[data-main-add]')?.scrollIntoView({ block: 'center', behavior: 'smooth' }); } else { add(false); }">
                        <span class="btn-label" x-text="complete && !inStock ? 'Sold out' : (complete ? 'Add to bag' : 'Choose options')">Add to bag</span>
                        <span class="btn-done"><?= icon('check', 16) ?> Added</span>
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
