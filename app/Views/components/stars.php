<?php
/** Star rating (port of <x-stars>). Vars: $rating (0-5), $size = 16, $class. */
$rating = $rating ?? 0;
$size = (int) ($size ?? 16);
?>
<span class="<?= e(trim('inline-flex items-center gap-0.5 text-gold ' . ($class ?? ''))) ?>" role="img" aria-label="<?= e($rating) ?> out of 5 stars">
    <?php for ($i = 1; $i <= 5; $i++): ?>
        <svg width="<?= $size ?>" height="<?= $size ?>" viewBox="0 0 24 24" aria-hidden="true" fill="<?= $i <= round((float) $rating) ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"><path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.9L12 17.8 5.8 21.100 7 14.200 2 9.300l6.900-1z"/></svg>
    <?php endfor; ?>
</span>
