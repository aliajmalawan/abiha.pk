<?php
/**
 * Choose a new password (port of resources/views/auth/reset-password.blade.php).
 *
 * @var string $token
 * @var string|null $email
 */
use App\Core\View;

$field = static fn (array $a): string => View::partial('auth/partials/field', $a);
?>
<div class="container py-12 lg:py-20 max-w-md">
    <h1 class="!text-3xl text-center">Choose a new password</h1>
    <form method="POST" action="<?= e(route('password.update')) ?>" class="card mt-6 space-y-4" novalidate data-validate>
        <?= csrf_field() ?>
        <?= View::partial('auth/partials/form-errors') ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <?= $field(['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $email, 'required' => true, 'autocomplete' => 'email']) ?>
        <?= $field(['name' => 'password', 'label' => 'New password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'help' => 'At least 8 characters, with letters and numbers.']) ?>
        <?= $field(['name' => 'password_confirmation', 'label' => 'Confirm new password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
        <button class="btn btn--block">Reset password</button>
    </form>
</div>
