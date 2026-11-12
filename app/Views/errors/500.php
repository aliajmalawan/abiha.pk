<?php /* Standalone on purpose: when something is broken (database down, bad config) this page must not depend on either. */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Something went wrong</title>
    <link rel="stylesheet" href="<?= e(asset('assets/abiha/abiha.css')) ?>">
</head>
<body class="min-h-screen grid place-items-center p-6 text-center">
    <main>
        <img src="<?= e(asset('assets/brand/abiha-logo-full-300.png')) ?>" alt="ABIHA®" width="150" height="108" class="mx-auto">
        <h1 class="!text-3xl !mt-6">Something went wrong</h1>
        <p class="lead mx-auto mt-3">We are sorry: something went wrong on our side. Nothing was lost, and our team can look into it. Please try again in a few minutes.</p>
        <?php if (!empty($ref)): ?><p class="muted text-sm mt-3">Reference: <?= e($ref) ?></p><?php endif; ?>
        <?php if (!empty($debug)): ?><pre class="text-left text-xs mt-6 p-4 bg-white border border-line overflow-auto max-w-3xl"><?= e($debug) ?></pre><?php endif; ?>
        <a href="<?= e(url('/')) ?>" class="btn mt-8">Back to home</a>
    </main>
</body>
</html>
