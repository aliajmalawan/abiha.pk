<?php
/* The brand story: one picture, the admin's text, then figures counted from the catalogue. */
use App\Services\Catalog;
use App\Services\HomePage;

$pics = array_values(array_filter($showcase, static fn ($p) => Catalog::imagePath($p, 0)));
$main = HomePage::setting($section, 'image') ?: (isset($pics[0]) ? Catalog::imagePath($pics[0], 0) : null);
$figures = array_filter($stats, static fn ($n) => $n > 0);
?>
<section class="section <?= e($toneClass ?? '') ?>">
    <div class="container grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
        <div class="relative overflow-hidden bg-ivory border border-line <?= e($main ? '[&_picture]:block' : 'aspect-[5/4]') ?>">
            <?php if ($main): ?><?= App\Support\Img::picture($main, (string) ($section['title'] ?: ''), '(min-width:1024px) 50vw, 100vw', false, false, ['class' => 'w-full h-auto']) ?><?php else: ?><?= App\Core\View::partial('components/placeholder') ?><?php endif; ?>
        </div>
        <div class="max-w-xl max-lg:text-center max-lg:mx-auto">
            <p class="eyebrow">Our story</p>
            <h2><?= e($section['title']) ?></h2>
            <hr class="gold-rule max-lg:mx-auto">
            <p class="lead max-lg:mx-auto"><?= e(HomePage::setting($section, 'body')) ?></p>
            <?php if ($figures): ?>
                <div class="home-stats mt-6">
                    <?php foreach ($figures as $label => $n): ?><div><b><?= (int) $n ?></b><span class="text-sm text-grey-text"><?= e($label) ?></span></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (HomePage::setting($section, 'cta_label')): ?><a href="<?= e(HomePage::setting($section, 'cta_url', '#')) ?>" class="home-btn home-btn--outline mt-6"><?= e(HomePage::setting($section, 'cta_label')) ?></a><?php endif; ?>
            <?php if (cfg('abiha.show_sample_labels')): ?><div class="mt-4"><span class="sample-flag">SAMPLE COPY</span></div><?php endif; ?>
        </div>
    </div>
</section>
