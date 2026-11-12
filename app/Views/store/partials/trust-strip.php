<?php $trust = App\Support\TrustStrip::items(); ?>
<?php if (count($trust) >= 2): ?>
    <section class="home-trust" aria-label="Shopping with us">
        <div class="container">
            <ul class="home-trust__card home-trust__card--<?= min(count($trust), 4) ?>">
                <?php foreach ($trust as $t): ?>
                    <li class="contents">
                        <?php if ($t['href']): ?><a href="<?= e($t['href']) ?>" class="home-trust__item"><?php else: ?><div class="home-trust__item"><?php endif; ?>
                            <span class="home-trust__icon"><?= icon($t['icon'], 22) ?></span>
                            <span class="min-w-0"><span class="block text-sm font-semibold"><?= e($t['title']) ?></span><span class="block text-xs text-grey-text"><?= e($t['text']) ?></span></span>
                        <?php if ($t['href']): ?></a><?php else: ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
<?php endif; ?>
