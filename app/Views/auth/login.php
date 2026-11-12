<?php
/** Sign in (port of resources/views/auth/login.blade.php). */
use App\Core\View;

$field = static fn (array $a): string => View::partial('auth/partials/field', $a);
?>
<div class="container py-12 lg:py-20 max-w-md">
    <h1 class="!text-3xl text-center">Sign in</h1>
    <?php if (flash('status')): ?><div class="alert alert--success mt-4" role="status"><?= e(flash('status')) ?></div><?php endif; ?>
    <form method="POST" action="<?= e(route('login')) ?>" class="card mt-6 space-y-4" novalidate data-validate>
        <?= csrf_field() ?>
        <?= View::partial('auth/partials/form-errors') ?>
        <?= $field(['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'autocomplete' => 'email', 'attrs' => ['autofocus' => 'autofocus']]) ?>
        <?= $field(['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
        <div class="flex items-center justify-between text-sm">
            <label class="check"><input type="checkbox" name="remember" value="1"> Remember me</label>
            <a class="link inline-block py-2" href="<?= e(route('password.request')) ?>">Forgot password?</a>
        </div>
        <button class="btn btn--block">Sign in</button>
    </form>
    <p class="text-center text-sm mt-6">New to ABIHA®? <a class="link" href="<?= e(route('register')) ?>">Create an account</a></p>
</div>
