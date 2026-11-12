<?php
/**
 * Visible breadcrumb trail (port of <x-breadcrumbs>), fed by the same data as the BreadcrumbList structured data
 * (App\Support\Seo::breadcrumbs). Optional $class (default spacing classes are added by the caller, e.g. "mb-4").
 */
$crumbs = App\Support\Seo::crumbs();
$class = trim('text-xs text-grey-text ' . ($class ?? ''));
?>
<?php if (count($crumbs) > 1): ?>
    <nav aria-label="Breadcrumb" class="<?= e($class) ?>">
        <ol class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
            <?php foreach ($crumbs as $i => $c): $last = $i === count($crumbs) - 1; ?>
                <li class="inline-flex items-center gap-1.5">
                    <?php if (!$last && $c['url']): ?><a href="<?= e($c['url']) ?>" class="hover:underline inline-block py-1.5"><?= e($c['name']) ?></a><span aria-hidden="true">/</span>
                    <?php else: ?><span class="text-ink py-1.5" aria-current="page"><?= e($c['name']) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>
<?php endif; ?>
