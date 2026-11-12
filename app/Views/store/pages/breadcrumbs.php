<?php
/** Visible breadcrumb trail (port of <x-breadcrumbs>), fed by the same data as the BreadcrumbList JSON-LD. $class = extra classes. */
$crumbs = App\Support\Seo::crumbs();
if (count($crumbs) <= 1) {
    return;
}
$lastIdx = count($crumbs) - 1;
?>
<nav aria-label="Breadcrumb" class="<?= e(trim('text-xs text-grey-text ' . ($class ?? ''))) ?>">
        <ol class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
            <?php foreach ($crumbs as $i => $c): ?>
                <li class="inline-flex items-center gap-1.5">
                    <?php if ($i !== $lastIdx && $c['url']): ?><a href="<?= e($c['url']) ?>" class="hover:underline inline-block py-1.5"><?= e($c['name']) ?></a><span aria-hidden="true">/</span>
                    <?php else: ?><span class="text-ink py-1.5" aria-current="page"><?= e($c['name']) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>
