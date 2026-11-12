<section class="section <?= e($toneClass ?? '') ?>">
    <div class="container">
        <div class="home-head">
            <?php if ($section['subtitle']): ?><p class="eyebrow !m-0"><?= e($section['subtitle']) ?></p><?php endif; ?>
            <h2><?= e($section['title']) ?></h2>
            <hr class="gold-rule">
        </div>
        <div class="grid grid--3 mt-10">
            <?php foreach ($testimonials as $t): ?>
                <figure class="card !p-8 flex flex-col gap-4 relative">
                    <span class="absolute right-6 top-3 font-display text-7xl leading-none text-gold/30" aria-hidden="true">&ldquo;</span>
                    <?= App\Core\View::partial('components/stars', ['rating' => (int) $t['rating']]) ?>
                    <blockquote class="text-base leading-relaxed">“<?= e($t['body']) ?>”</blockquote>
                    <figcaption class="mt-auto text-sm">
                        <strong class="font-display"><?= e($t['name']) ?></strong><?php if ($t['location']): ?><span class="muted"> · <?= e($t['location']) ?></span><?php endif; ?>
                        <?php if ($t['is_sample'] && cfg('abiha.show_sample_labels')): ?><span class="sample-flag ml-2">SAMPLE</span><?php endif; ?>
                    </figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
    </div>
</section>
