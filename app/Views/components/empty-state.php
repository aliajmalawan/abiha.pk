<?php
/**
 * One calm empty state for the whole site (port of <x-empty-state>): ivory icon disc, short heading, one clear next step.
 * Vars: $title, $icon = 'info', $text, $action + $href, $secondary + $secondaryHref, $compact (bool), $class, $slot (raw HTML).
 */
$icon = $icon ?? 'info';
$compact = !empty($compact);
$action = $action ?? null;
$href = $href ?? null;
$secondary = $secondary ?? null;
$secondaryHref = $secondaryHref ?? null;
$slot = $slot ?? '';
$cls = trim('empty' . ($compact ? ' empty--compact' : '') . (!empty($class) ? ' ' . $class : ''));
?>
<div class="<?= e($cls) ?>">
    <span class="empty__icon" aria-hidden="true"><?= icon($icon, 30) ?></span>
    <h2><?= e($title) ?></h2>
    <?php if (!empty($text)): ?><p><?= e($text) ?></p><?php endif; ?>
    <?php if (($action && $href) || trim($slot) !== ''): ?>
        <div class="mt-2 flex flex-wrap justify-center gap-3">
            <?php if ($action && $href): ?><a href="<?= e($href) ?>" class="btn <?= $compact ? 'btn--sm' : '' ?>"><?= e($action) ?></a><?php endif; ?>
            <?php if ($secondary && $secondaryHref): ?><a href="<?= e($secondaryHref) ?>" class="btn btn--outline <?= $compact ? 'btn--sm' : '' ?>"><?= e($secondary) ?></a><?php endif; ?>
            <?= $slot ?>
        </div>
    <?php endif; ?>
</div>
