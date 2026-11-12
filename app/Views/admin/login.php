<?php
/** Staff sign-in page (port of admin/login.blade.php). Standalone page: no admin chrome, no scripts. */
use App\Services\Admin\Form;
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Staff sign in | ABIHA® Admin</title>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('assets/brand/favicon-32.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/abiha/abiha.css')) ?>">
</head>
<body class="min-h-screen grid place-items-center bg-ivory p-4">
    <main class="w-full max-w-sm">
        <div class="text-center mb-6"><?= logo(150) ?><p class="eyebrow mt-2">Staff area</p></div>
        <form method="POST" action="<?= e(route('admin.login.store')) ?>" class="card space-y-4" novalidate>
            <?= csrf_field() ?>
            <h1 class="!text-xl !mb-0">Sign in</h1>
            <?= Form::field('email', 'Email', null, ['type' => 'email', 'required' => true, 'autocomplete' => 'username', 'attrs' => ['autofocus' => 'autofocus']]) ?>
            <?= Form::field('password', 'Password', null, ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
            <button class="btn btn--block">Sign in</button>
        </form>
        <p class="text-center text-sm mt-6"><a class="link" href="<?= e(route('home')) ?>">← Back to the website</a></p>
    </main>
</body>
</html>
