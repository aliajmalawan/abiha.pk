<?php
/**
 * Storefront flash messages (port of partials/flash.blade.php): the "status" flash, an "error" flash, and the
 * cart / wishlist / order validation errors.
 */
$status = flash('status');
$flashError = flash('error');
$keyed = array_values(array_filter(array_map('error', ['cart', 'wishlist', 'order'])));
?>
<?php if ($status || $flashError || $keyed): ?>
    <div class="container pt-4">
        <?php if ($status): ?><div class="alert alert--success" role="status"><?= e($status) ?></div><?php endif; ?>
        <?php if ($flashError): ?><div class="alert alert--danger mt-2" role="alert"><?= e($flashError) ?></div><?php endif; ?>
        <?php foreach ($keyed as $message): ?>
            <div class="alert alert--danger mt-2" role="alert"><?= e($message) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
