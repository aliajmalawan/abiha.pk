<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Back shortly</title>
    {{-- Standalone on purpose: when something is broken (database down, bad config) this page must not depend on either. --}}
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen grid place-items-center p-6 text-center">
    <main>
        <img src="{{ asset('assets/brand/abiha-logo-full-300.png') }}" alt="ABIHA®" width="150" height="108" class="mx-auto">
        <h1 class="!text-3xl !mt-6">Back shortly</h1>
        <p class="lead mx-auto mt-3">We are making a quick improvement and will be back in a few minutes.</p>
        <a href="/" class="btn mt-8">Back to home</a>
    </main>
</body>
</html>
