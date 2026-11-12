<?php /* Collections: a tall picture, the name, the admin's description and a live piece count. */ ?>
<section class="section <?= e($toneClass ?? '') ?>">
    <div class="container">
        <div class="home-head">
            <?php if ($section['subtitle']): ?><p class="eyebrow !m-0"><?= e($section['subtitle']) ?></p><?php endif; ?>
            <h2><?= e($section['title']) ?></h2>
            <hr class="gold-rule">
        </div>
        <div class="grid grid-cols-2 <?= count($collections) >= 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3' ?> gap-3 sm:gap-6">
            <?php foreach ($collections as $col): $n = (int) $col['active_products_count']; ?>
                <a href="<?= e(route('collection', ['slug' => $col['slug']])) ?>" class="home-col group">
                    <div class="home-col__img"><?php if ($col['image']): ?><?= App\Support\Img::picture($col['image'], '', '(min-width:1024px) 25vw, 50vw') ?><?php else: ?><?= App\Core\View::partial('components/placeholder') ?><?php endif; ?></div>
                    <div class="mt-3 text-center">
                        <h3 class="!text-xl !mb-1"><?= e($col['name']) ?></h3>
                        <?php if ($col['description']): ?><p class="text-sm muted !mb-1 line-clamp-2"><?= e($col['description']) ?></p><?php endif; ?>
                        <span class="text-sm font-semibold underline underline-offset-4 decoration-gold"><?= $n ?> <?= $n === 1 ? 'piece' : 'pieces' ?> · Shop the edit</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
