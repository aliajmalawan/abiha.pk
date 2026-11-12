<?php /* Any other HTTP error without its own page (405, 401...): standalone, like Laravel's minimal error page. */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($title ?? 'Error') ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/abiha/abiha.css')) ?>">
</head>
<body class="min-h-screen grid place-items-center p-6 text-center">
    <main>
        <p class="eyebrow">Error <?= (int) ($status ?? 400) ?></p>
        <h1 class="!text-3xl !mt-3"><?= e($title ?? 'Error') ?></h1>
        <a href="<?= e(url('/')) ?>" class="btn mt-8">Back to home</a>
    </main>
</body>
</html>
