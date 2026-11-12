<?php /** Port of resources/views/vendor/pagination/abiha.blade.php. @var App\Support\Paginator $paginator */ ?>
<?php if ($paginator->hasPages()): ?>
    <nav role="navigation" aria-label="Pagination" class="flex flex-wrap items-center justify-center gap-2">
        <?php if ($paginator->onFirstPage()): ?>
            <span class="btn btn--outline btn--sm" aria-disabled="true"><?= icon('chevron-left', 16) ?> Prev</span>
        <?php else: ?>
            <a href="<?= e($paginator->previousPageUrl()) ?>" rel="prev" class="btn btn--outline btn--sm"><?= icon('chevron-left', 16) ?> Prev</a>
        <?php endif; ?>

        <?php foreach ($paginator->elements() as $element): ?>
            <?php if (is_string($element)): ?><span class="px-2 text-grey-text" aria-hidden="true"><?= e($element) ?></span><?php endif; ?>
            <?php if (is_array($element)): ?>
                <?php foreach ($element as $page => $url): ?>
                    <?php if ($page == $paginator->currentPage): ?>
                        <span aria-current="page" class="btn btn--sm !px-0 w-10"><?= (int) $page ?></span>
                    <?php else: ?>
                        <a href="<?= e($url) ?>" class="btn btn--outline btn--sm !px-0 w-10" aria-label="Go to page <?= (int) $page ?>"><?= (int) $page ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($paginator->hasMorePages()): ?>
            <a href="<?= e($paginator->nextPageUrl()) ?>" rel="next" class="btn btn--outline btn--sm">Next <?= icon('chevron-right', 16) ?></a>
        <?php else: ?>
            <span class="btn btn--outline btn--sm" aria-disabled="true">Next <?= icon('chevron-right', 16) ?></span>
        <?php endif; ?>
    </nav>
<?php endif; ?>
