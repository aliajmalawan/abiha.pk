<?php
/** Contact form (port of resources/views/storefront/contact.blade.php). */
use App\Core\View;
use App\Support\Settings;

$field = static fn (array $a): string => View::partial('auth/partials/field', $a);
$me = $user ?? null;
$ci = array_filter(['Email' => Settings::get('contact.email'), 'Phone' => Settings::get('contact.phone'), 'Address' => Settings::get('contact.address'), 'Hours' => Settings::get('contact.hours')]);
?>
<div class="container py-12 lg:py-16 max-w-2xl">
    <h1 class="!text-3xl lg:!text-4xl">Contact us</h1>
    <p class="lead">Questions about an order or a product? Send us a message.</p>
    <?php if (flash('status')): ?><div class="alert alert--success mt-4" role="status"><?= e(flash('status')) ?></div><?php endif; ?>
    <?php if ($ci): ?><dl class="grid gap-x-8 gap-y-2 text-sm mt-4 sm:grid-cols-2"><?php foreach ($ci as $label => $value): ?><div><dt class="eyebrow !text-[10px]"><?= e($label) ?></dt><dd class="whitespace-pre-line"><?= e(is_scalar($value) ? $value : '') ?></dd></div><?php endforeach; ?></dl><?php endif; ?>

    <form method="POST" action="<?= e(route('contact.send')) ?>" class="card mt-6 space-y-4" novalidate data-validate>
        <?= csrf_field() ?>
        <?= View::partial('auth/partials/form-errors') ?>
        <div class="grid gap-4 sm:grid-cols-2">
            <?= $field(['name' => 'name', 'label' => 'Your name', 'value' => $me['name'] ?? null, 'required' => true, 'autocomplete' => 'name']) ?>
            <?= $field(['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $me['email'] ?? null, 'required' => true, 'autocomplete' => 'email']) ?>
            <?= $field(['name' => 'phone', 'label' => 'Phone (optional)', 'type' => 'tel', 'value' => $me['phone'] ?? null, 'autocomplete' => 'tel']) ?>
            <?= $field(['name' => 'subject', 'label' => 'Subject (optional)']) ?>
        </div>
        <div class="field"><label class="label" for="f-message">Message <span class="req" aria-hidden="true">*</span></label>
            <textarea class="textarea" id="f-message" name="message" required minlength="10"<?= has_error('message') ? ' aria-invalid="true"' : '' ?>><?= e(is_scalar(old('message')) ? old('message') : '') ?></textarea>
            <?php if (has_error('message')): ?><span class="error-text"><?= e(error('message')) ?></span><?php endif; ?></div>
        <?php /* Honeypot */ ?>
        <div class="sr-only" aria-hidden="true"><label>Leave blank <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <button class="btn">Send message</button>
    </form>
</div>
