<?php
/**
 * Pager (port of resources/views/vendor/pagination/abiha.blade.php, Laravel's window of 3 pages on each side).
 *
 * @var int    $page
 * @var int    $lastPage
 * @var string $base  listing URL without ?page
 */
if ($lastPage <= 1) {
    return;
}
$link = static fn (int $p): string => $base . ($p > 1 ? (str_contains($base, '?') ? '&' : '?') . 'page=' . $p : '');
$pages = [];
if ($lastPage <= 13) {
    $pages = range(1, $lastPage);
} else {
    $from = max(1, $page - 3);
    $to = min($lastPage, $page + 3);
    if ($from <= 4) { $from = 1; $to = max($to, 8); }
    if ($to >= $lastPage - 3) { $to = $lastPage; $from = min($from, $lastPage - 7); }
    $pages = array_merge($from > 1 ? [1, 2, '...'] : [], range($from, $to), $to < $lastPage ? ['...', $lastPage - 1, $lastPage] : []);
}
?>
<nav role="navigation" aria-label="Pagination" class="flex flex-wrap items-center justify-center gap-2">
    <?php if ($page <= 1): ?>
        <span class="btn btn--outline btn--sm" aria-disabled="true"><?= icon('chevron-left', 16) ?> Prev</span>
    <?php else: ?>
        <a href="<?= e($link($page - 1)) ?>" rel="prev" class="btn btn--outline btn--sm"><?= icon('chevron-left', 16) ?> Prev</a>
    <?php endif; ?>
    <?php foreach ($pages as $p): ?>
        <?php if ($p === '...'): ?><span class="px-2 text-grey-text" aria-hidden="true">...</span>
        <?php elseif ($p === $page): ?><span aria-current="page" class="btn btn--sm !px-0 w-10"><?= $p ?></span>
        <?php else: ?><a href="<?= e($link((int) $p)) ?>" class="btn btn--outline btn--sm !px-0 w-10" aria-label="Go to page <?= $p ?>"><?= $p ?></a><?php endif; ?>
    <?php endforeach; ?>
    <?php if ($page < $lastPage): ?>
        <a href="<?= e($link($page + 1)) ?>" rel="next" class="btn btn--outline btn--sm">Next <?= icon('chevron-right', 16) ?></a>
    <?php else: ?>
        <span class="btn btn--outline btn--sm" aria-disabled="true">Next <?= icon('chevron-right', 16) ?></span>
    <?php endif; ?>
</nav>
