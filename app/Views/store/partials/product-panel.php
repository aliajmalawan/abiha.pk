<?php
/* One row of products in the layout the admin chose: carousel (swipe / arrows), grid, or "shop the look" (tall pictures). Every item is a normal link.
   Vars: $products (cards), $layout, $link, $label. */
use App\Core\View;
use App\Services\Catalog;
use App\Support\Img;

$cur = cfg('abiha.currency.symbol');
$basis = $layout === 'lookbook' ? 'basis-[56%] sm:basis-[36%] md:basis-[28%] lg:basis-[19%]' : 'basis-[62%] sm:basis-[40%] md:basis-[31%] lg:basis-[23.5%]';
?>
<?php if ($layout === 'grid'): ?>
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-x-3 gap-y-8 sm:gap-x-5" data-reveal-group>
        <?php foreach ($products as $product): ?><?= View::partial('store/partials/product-card', ['product' => $product, 'swatches' => true, 'reveal' => true, 'tilt' => true]) ?><?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="relative" x-data="{ atStart: true, atEnd: false,
            upd() { const r = this.$refs.rail; this.atStart = r.scrollLeft < 4; this.atEnd = r.scrollLeft + r.clientWidth >= r.scrollWidth - 4 },
            step(d) { const r = this.$refs.rail; r.scrollBy({ left: d * r.clientWidth * 0.85, behavior: 'smooth' }) } }"
         x-init="upd(); $nextTick(() => upd())" @resize.window.debounce.150ms="upd()">
        <div x-ref="rail" data-reveal-group @scroll.passive="upd()" role="region" tabindex="0" aria-label="<?= e($label) ?>"
             class="flex gap-3 sm:gap-5 overflow-x-auto snap-x snap-mandatory pb-3 -mx-4 px-4 sm:mx-0 sm:px-0 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <?php foreach ($products as $product): $i0 = Catalog::imagePath($product, 0); $thumb = Catalog::imagePath($product, 1) ?: $i0; ?>
                <div class="snap-start shrink-0 <?= $basis ?>" data-reveal>
                    <?php if ($layout === 'lookbook'): ?>
                        <a href="<?= e(route('product', $product)) ?>" class="home-look">
                            <?php if ($i0): ?><?= Img::picture($i0, (string) $product['name'], '(min-width:1024px) 19vw, 56vw') ?><?php else: ?><?= View::partial('components/placeholder') ?><?php endif; ?>
                            <span class="home-look__bar">
                                <?php if ($thumb): ?><?= Img::picture($thumb, '', '46px', false, false, ['class' => 'home-look__thumb']) ?><?php endif; ?>
                                <span class="min-w-0"><span class="block text-sm font-semibold truncate"><?= e($product['name']) ?></span><span class="block text-sm text-grey-text"><?= e($cur) ?> <?= number_format((int) $product['price']) ?></span></span>
                            </span>
                        </a>
                    <?php else: ?>
                        <?= View::partial('store/partials/product-card', ['product' => $product, 'swatches' => true, 'tilt' => true]) ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="home-arrow absolute -left-4 top-[38%] hidden lg:grid" @click="step(-1)" :disabled="atStart" aria-label="Previous"><?= icon('chevron-left', 22) ?></button>
        <button type="button" class="home-arrow absolute -right-4 top-[38%] hidden lg:grid" @click="step(1)" :disabled="atEnd" aria-label="Next"><?= icon('chevron-right', 22) ?></button>
    </div>
<?php endif; ?>
<?php if (!empty($link)): ?>
    <div class="mt-8 text-center"><a href="<?= e(url($link)) ?>" class="home-btn home-btn--outline">View all</a></div>
<?php endif; ?>
