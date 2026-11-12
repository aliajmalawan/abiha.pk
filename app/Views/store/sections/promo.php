<?php
/* Promotional banner. The words come from the banner placed as "Promotional banner". The deal line is the real product with the
   biggest discount right now; with nothing discounted it is simply not shown. */
use App\Services\Catalog;

$deal = $deal ?? null;
$img = $banner['image'] ?: ($deal ? Catalog::imagePath($deal, 0) : null);
$pct = $deal['discount_percent'] ?? null;
$cur = cfg('abiha.currency.symbol');
?>
<section class="section <?= e($toneClass ?? '') ?>">
    <div class="container grid items-center gap-8 lg:grid-cols-2 lg:gap-14">
        <div class="relative overflow-hidden bg-ivory border border-line <?= e($img ? '[&_picture]:block' : 'aspect-[4/3]') ?>">
            <?php if ($pct): ?><span class="absolute left-4 top-4 z-10 bg-ink px-3 py-1.5 text-sm font-bold text-champagne">−<?= (int) $pct ?>%</span><?php endif; ?>
            <?php if ($img): ?><?= App\Support\Img::picture($img, (string) $banner['title'], '(min-width:1024px) 50vw, 100vw', false, false, ['class' => 'w-full h-auto']) ?><?php else: ?><?= App\Core\View::partial('components/placeholder') ?><?php endif; ?>
        </div>
        <div class="max-lg:text-center">
            <?php if ($banner['eyebrow']): ?><p class="eyebrow"><?= e($banner['eyebrow']) ?></p><?php endif; ?>
            <h2><?= e($banner['title']) ?></h2>
            <hr class="gold-rule max-lg:mx-auto">
            <?php if ($banner['subtitle']): ?><p class="lead max-lg:mx-auto"><?= e($banner['subtitle']) ?></p><?php endif; ?>
            <?php if ($deal): ?>
                <p class="text-sm">Featured: <a href="<?= e(route('product', $deal)) ?>" class="font-semibold underline underline-offset-4 decoration-gold"><?= e($deal['name']) ?></a>
                    <strong class="ml-2"><?= e($cur) ?> <?= number_format((int) $deal['price']) ?></strong> <s class="text-grey-text"><?= e($cur) ?> <?= number_format((int) $deal['compare_price']) ?></s></p>
            <?php endif; ?>
            <?php if ($banner['cta_label']): ?><a href="<?= e(url($banner['cta_url'] ?: '/shop')) ?>" class="home-btn mt-2"><?= e($banner['cta_label']) ?></a><?php endif; ?>
            <?php if ($banner['is_sample'] && cfg('abiha.show_sample_labels')): ?><div class="mt-4"><span class="sample-flag">SAMPLE BANNER</span></div><?php endif; ?>
        </div>
    </div>
</section>
