<?php /* Category tiles: a tall picture with the name and a "Shop now" link under it. Two across on phones, four on desktop. */ ?>
<section class="section <?= e($toneClass ?? '') ?>">
    <div class="container">
        <div class="home-head" data-reveal>
            <?php if ($section['subtitle']): ?><p class="eyebrow !m-0"><?= e($section['subtitle']) ?></p><?php endif; ?>
            <h2><?= e($section['title']) ?></h2>
            <hr class="gold-rule">
        </div>
        <?php $cn = count($categories); $cols = in_array($cn, [3, 6, 9], true) ? 'lg:grid-cols-3' : ($cn === 5 ? 'lg:grid-cols-5' : 'lg:grid-cols-4'); /* full rows: 6 tiles are 3 x 2, never 4 + 2 */ ?>
        <div data-reveal-group class="grid grid-cols-2 <?= $cols ?> gap-3 sm:gap-6">
            <?php foreach ($categories as $c): ?>
                <a href="<?= e(route('category', ['slug' => $c['slug']])) ?>" class="home-tile group" data-reveal data-tilt>
                    <div class="home-tile__img"><?php if ($c['image']): ?><?= App\Support\Img::picture($c['image'], '', '(min-width:1024px) 25vw, 50vw') ?><?php else: ?><?= App\Core\View::partial('components/placeholder') ?><?php endif; ?></div>
                    <div class="mt-3 text-center">
                        <h3 class="!text-xl !mb-1"><?= e($c['name']) ?></h3>
                        <?php if ((int) ($c['active_products_count'] ?? 0) > 0): ?><span class="home-tile__count"><?= (int) $c['active_products_count'] ?> <?= (int) $c['active_products_count'] === 1 ? 'piece' : 'pieces' ?></span><?php endif; ?>
                        <span class="text-sm font-semibold underline underline-offset-4 decoration-gold">Shop now</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
