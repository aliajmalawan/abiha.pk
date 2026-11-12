<?php
/** Port of resources/views/vendor/pagination/abiha.blade.php. @var array $pager (App\Services\Admin\Pager::query) */
use App\Services\Admin\Pager;

if ($pager['last'] <= 1) {
    return;
}
?>
<nav role="navigation" aria-label="Pagination" class="flex flex-wrap items-center justify-center gap-2">
    <?php if ($pager['page'] <= 1): ?>
        <span class="btn btn--outline btn--sm" aria-disabled="true"><?= icon('chevron-left', 16) ?> Prev</span>
    <?php else: ?>
        <a href="<?= e(Pager::url($pager, $pager['page'] - 1)) ?>" rel="prev" class="btn btn--outline btn--sm"><?= icon('chevron-left', 16) ?> Prev</a>
    <?php endif; ?>

    <?php foreach (Pager::elements($pager) as $element): ?>
        <?php if (is_string($element)): ?><span class="px-2 text-grey-text" aria-hidden="true"><?= e($element) ?></span><?php endif; ?>
        <?php if (is_array($element)): ?>
            <?php foreach ($element as $page): ?>
                <?php if ($page === $pager['page']): ?>
                    <span aria-current="page" class="btn btn--sm !px-0 w-10"><?= $page ?></span>
                <?php else: ?>
                    <a href="<?= e(Pager::url($pager, $page)) ?>" class="btn btn--outline btn--sm !px-0 w-10" aria-label="Go to page <?= $page ?>"><?= $page ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($pager['page'] < $pager['last']): ?>
        <a href="<?= e(Pager::url($pager, $pager['page'] + 1)) ?>" rel="next" class="btn btn--outline btn--sm">Next <?= icon('chevron-right', 16) ?></a>
    <?php else: ?>
        <span class="btn btn--outline btn--sm" aria-disabled="true">Next <?= icon('chevron-right', 16) ?></span>
    <?php endif; ?>
</nav>
