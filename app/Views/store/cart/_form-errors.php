<?php
/** Port of <x-form-errors>: summary at the top of a form after a failed submit. Vars: $class (optional). */
$keys = array_values(array_filter(array_keys(errors()), static fn ($k) => !in_array($k, ['cart', 'wishlist', 'order', 'coupon', 'delete', 'status', 'payment', 'inventory'], true)));
if ($keys): ?>
    <div class="<?= e(trim('error-summary ' . ($class ?? ''))) ?>" role="alert" tabindex="-1" data-error-summary>
        <strong><?= count($keys) === 1 ? 'There is 1 problem to fix:' : 'There are ' . count($keys) . ' problems to fix:' ?></strong>
        <ul>
            <?php foreach ($keys as $k): ?>
                <li><a href="#f-<?= e(str_replace('.', '-', $k)) ?>"><?= e(error($k)) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif;
