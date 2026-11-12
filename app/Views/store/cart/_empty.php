<?php
/**
 * Port of <x-empty-state>: ivory icon disc, short heading, one clear next step.
 * Vars: $icon, $title, $text, $action, $href, $compact (bool), $class (extra classes), $attrs (extra raw attribute string).
 */
$compact = !empty($compact);
?>
<div class="<?= e(trim('empty' . ($compact ? ' empty--compact' : '') . ' ' . ($class ?? ''))) ?>"<?= $attrs ?? '' ?>>
    <span class="empty__icon" aria-hidden="true"><?= icon($icon ?? 'info', 30) ?></span>
    <h2><?= e($title) ?></h2>
    <?php if (!empty($text)): ?><p><?= e($text) ?></p><?php endif; ?>
    <?php if (!empty($action) && !empty($href)): ?>
        <div class="mt-2 flex flex-wrap justify-center gap-3">
            <a href="<?= e($href) ?>" class="btn <?= $compact ? 'btn--sm' : '' ?>"><?= e($action) ?></a>
        </div>
    <?php endif; ?>
</div>
