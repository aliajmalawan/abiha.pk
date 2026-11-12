<?php $banner = $banner ?? []; ?>
<section class="section--dark on-dark relative overflow-hidden">
    <?php if ($banner['image']): ?><?= App\Support\Img::picture($banner['image'], '', '100vw', false, false, ['class' => 'absolute inset-0 size-full object-cover opacity-40']) ?><?php endif; ?>
    <div class="container relative py-20 lg:py-28 text-center max-w-3xl">
        <?php if ($banner['eyebrow']): ?><p class="eyebrow"><?= e($banner['eyebrow']) ?></p><?php endif; ?>
        <h2 class="!text-white"><?= e($banner['title']) ?></h2>
        <?php if ($banner['subtitle']): ?><p class="mx-auto max-w-xl text-white/80"><?= e($banner['subtitle']) ?></p><?php endif; ?>
        <?php if ($banner['cta_label']): ?><a href="<?= e(url($banner['cta_url'] ?: '/shop')) ?>" class="home-btn home-btn--light mt-4"><?= e($banner['cta_label']) ?></a><?php endif; ?>
        <?php if ($banner['is_sample'] && cfg('abiha.show_sample_labels')): ?><div class="mt-4"><span class="sample-flag">SAMPLE BANNER</span></div><?php endif; ?>
    </div>
</section>
