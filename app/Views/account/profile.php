<?php
/** Profile & password (port of resources/views/account/profile.blade.php). */
use App\Core\View;

$field = static fn (array $a): string => View::partial('auth/partials/field', $a);
$me = $user ?? [];
?>
<div class="grid gap-8 xl:grid-cols-2">
    <form method="POST" action="<?= e(route('account.profile.update')) ?>" class="card space-y-4" novalidate data-validate>
        <?= csrf_field() ?> <?= method_field('PUT') ?>
        <?= View::partial('auth/partials/form-errors') ?>
        <h2 class="!text-xl">Your details</h2>
        <?= $field(['name' => 'name', 'label' => 'Full name', 'value' => $me['name'] ?? null, 'required' => true, 'autocomplete' => 'name']) ?>
        <?= $field(['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $me['email'] ?? null, 'required' => true, 'autocomplete' => 'email']) ?>
        <?= $field(['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'value' => $me['phone'] ?? null, 'autocomplete' => 'tel']) ?>
        <?= $field(['name' => 'email_password', 'label' => 'Current password', 'type' => 'password', 'autocomplete' => 'current-password', 'help' => 'Only needed if you change your email address.']) ?>
        <button class="btn">Save changes</button>
        <?php if (flash('status') && !errors()): ?><p class="text-sm text-success" role="status"><?= e(flash('status')) ?></p><?php endif; ?>
    </form>

    <form method="POST" action="<?= e(route('account.password.update')) ?>" class="card space-y-4" novalidate data-validate>
        <?= csrf_field() ?> <?= method_field('PUT') ?>
        <h2 class="!text-xl">Change password</h2>
        <?= $field(['name' => 'current_password', 'label' => 'Current password', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
        <?= $field(['name' => 'password', 'label' => 'New password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'help' => 'At least 8 characters, with letters and numbers.']) ?>
        <?= $field(['name' => 'password_confirmation', 'label' => 'Confirm new password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
        <button class="btn">Update password</button>
    </form>
</div>
