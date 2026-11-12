<?php
/** Create account (port of resources/views/auth/register.blade.php). */
use App\Core\View;

$field = static fn (array $a): string => View::partial('auth/partials/field', $a);
?>
<div class="container py-12 lg:py-20 max-w-md">
    <h1 class="!text-3xl text-center">Create your account</h1>
    <form method="POST" action="<?= e(route('register')) ?>" class="card mt-6 space-y-4" novalidate data-validate>
        <?= csrf_field() ?>
        <?= View::partial('auth/partials/form-errors') ?>
        <?= $field(['name' => 'name', 'label' => 'Full name', 'required' => true, 'autocomplete' => 'name', 'attrs' => ['autofocus' => 'autofocus']]) ?>
        <?= $field(['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'autocomplete' => 'email']) ?>
        <?= $field(['name' => 'phone', 'label' => 'Phone (optional)', 'type' => 'tel', 'autocomplete' => 'tel']) ?>
        <?= $field(['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'help' => 'At least 8 characters, with letters and numbers.']) ?>
        <?= $field(['name' => 'password_confirmation', 'label' => 'Confirm password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
        <button class="btn btn--block">Create account</button>
    </form>
    <p class="text-center text-sm mt-6">Already registered? <a class="link" href="<?= e(route('login')) ?>">Sign in</a></p>
</div>
