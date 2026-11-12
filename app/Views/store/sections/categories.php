<?php /* Category tiles: a tall picture with the name and a "Shop now" link under it. Two across on phones, four on desktop. */ ?>
<section class="section <?= e($toneClass ?? '') ?>">
    <div class="container">
        <div class="home-head">
            <?php if ($section['subtitle']): ?><p class="eyebrow !m-0"><?= e($section['subtitle']) ?></p><?php endif; ?>
            <h2><?= e($section['title']) ?></h2>
            <hr class="gold-rule">
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-6">
            <?php foreach ($categories as $c): ?>
                <a href="<?= e(route('category', ['slug' => $c['slug']])) ?>" class="home-tile group">
                    <div class="home-tile__img"><?php if ($c['image']): ?><?= App\Support\Img::picture($c['image'], '', '(min-width:1024px) 25vw, 50vw') ?><?php else: ?><?= App\Core\View::partial('components/placeholder') ?><?php endif; ?></div>
                    <div class="mt-3 text-center">
                        <h3 class="!text-xl !mb-1"><?= e($c['name']) ?></h3>
                        <span class="text-sm font-semibold underline underline-offset-4 decoration-gold">Shop now</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
