<?php
/**
 * Error summary at the top of a form after a failed submit (port of <x-form-errors>). Each item jumps to its field.
 * Keys that belong to page-level alerts (cart, order, ...) are left to the flash partial.
 */
$skip = ['cart', 'wishlist', 'order', 'coupon', 'delete', 'status', 'payment', 'inventory'];
$bag = array_filter(errors(), static fn ($k) => !in_array((string) $k, $skip, true), ARRAY_FILTER_USE_KEY);
?>
<?php if ($bag): ?>
    <div class="error-summary" role="alert" tabindex="-1" data-error-summary>
        <strong><?= count($bag) === 1 ? 'There is 1 problem to fix:' : 'There are ' . count($bag) . ' problems to fix:' ?></strong>
        <ul>
            <?php foreach ($bag as $k => $m): ?>
                <li><a href="#f-<?= e(str_replace('.', '-', (string) $k)) ?>"><?= e(is_array($m) ? reset($m) : $m) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
