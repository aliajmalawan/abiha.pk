<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Staff sign in | ABIHA® Admin</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('assets/brand/favicon-32.png') }}">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen grid place-items-center bg-ivory p-4">
    <main class="w-full max-w-sm">
        <div class="text-center mb-6"><x-logo :width="150" /><p class="eyebrow mt-2">Staff area</p></div>
        <form method="POST" action="{{ route('admin.login.store') }}" class="card space-y-4" novalidate>
            @csrf
            <h1 class="!text-xl !mb-0">Sign in</h1>
            <x-field name="email" label="Email" type="email" required autocomplete="username" autofocus />
            <x-field name="password" label="Password" type="password" required autocomplete="current-password" />
            <button class="btn btn--block">Sign in</button>
        </form>
        <p class="text-center text-sm mt-6"><a class="link" href="{{ route('home') }}">← Back to the website</a></p>
    </main>
</body>
</html>
