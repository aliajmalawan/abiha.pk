<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'Admin') | ABIHA® Admin</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('assets/brand/favicon-32.png') }}">
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">window.ABIHA = {currency: 'PKR', cartCount: 0, wishlistIds: []};</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ivory" x-data="{ nav: false }" @keydown.escape.window="nav = false">
@php
    $groups = [
        'Overview' => [['admin.dashboard', 'Dashboard', 'layout-dashboard', 'dashboard.view'], ['admin.reports.index', 'Reports', 'chart-column', 'reports.view']],
        'Catalogue' => [
            ['admin.products.index', 'Products', 'package', 'products.manage'], ['admin.categories.index', 'Categories', 'tag', 'categories.manage'],
            ['admin.collections.index', 'Collections', 'tag', 'categories.manage'], ['admin.attributes.index', 'Attributes', 'sliders-horizontal', 'products.manage'],
            ['admin.inventory.index', 'Inventory', 'package', 'inventory.manage'],
        ],
        'Sales' => [
            ['admin.orders.index', 'Orders', 'shopping-bag', 'orders.view'], ['admin.shipping.index', 'Shipping', 'truck', 'shipping.manage'],
            ['admin.shipments.index', 'Shipments', 'package', 'orders.view'],
            ['admin.payments.edit', 'Payments', 'credit-card', 'settings.manage'], ['admin.payments.transactions', 'Payment history', 'credit-card', 'payments.verify'],
        ],
        'Marketing' => [
            ['admin.coupons.index', 'Coupons', 'tag', 'coupons.manage'], ['admin.discounts.index', 'Sale pricing', 'tag', 'coupons.manage'],
            ['admin.featured.index', 'Featured products', 'star', 'products.manage'], ['admin.banners.index', 'Banners', 'eye', 'content.manage'],
            ['admin.newsletter.index', 'Newsletter', 'mail', 'messages.manage'],
        ],
        'Customers' => [
            ['admin.customers.index', 'Customers', 'users', 'customers.manage'], ['admin.reviews.index', 'Reviews', 'star', 'reviews.manage'],
            ['admin.messages.index', 'Messages', 'mail', 'messages.manage'],
        ],
        'Website' => [
            ['admin.home.index', 'Homepage', 'house', 'content.manage'], ['admin.pages.index', 'Pages', 'pencil', 'content.manage'],
            ['admin.testimonials.index', 'Testimonials', 'star', 'content.manage'], ['admin.menus.edit', 'Menus', 'menu', 'content.manage'],
        ],
        'System' => [
            ['admin.settings.edit', 'General settings', 'settings', 'settings.manage'], ['admin.staff.index', 'Staff & roles', 'users', 'staff.manage'],
            ['admin.logs.index', 'Activity log', 'bell', 'logs.view'],
        ],
    ];@endphp
<div class="lg:grid lg:grid-cols-[250px_1fr] min-h-screen">
    {{-- Sidebar --}}
    <div x-cloak :class="nav ? 'fixed inset-0 z-50' : 'hidden lg:block'" class="no-print">
        <div class="absolute inset-0 bg-ink/50 lg:hidden" @click="nav = false"></div>
        <aside data-keep-scroll="admin-nav" class="relative h-full w-[250px] bg-white border-r border-line overflow-y-auto lg:sticky lg:top-0 lg:h-screen">
            <div class="p-4 border-b border-line flex items-center justify-between">
                <a href="{{ route('admin.dashboard') }}"><x-logo :width="96" /></a>
                <button class="lg:hidden btn btn--ghost !px-2" @click="nav = false" aria-label="Close menu"><x-icon name="x" /></button>
            </div>
            <nav class="p-3 space-y-5" aria-label="Admin">
                @foreach($groups as $heading => $items)
                    @php $visible = collect($items)->filter(fn ($i) => auth()->user()->can($i[3])); @endphp
                    @if($visible->isNotEmpty())
                        <div>
                            <p class="eyebrow px-3 mb-1 !text-[10px]">{{ $heading }}</p>
                            @foreach($visible as [$route, $label, $icon])
                                @php $active = request()->routeIs($route) || (substr_count($route, '.') > 1 && request()->routeIs(\Illuminate\Support\Str::beforeLast($route, '.').'.*')); @endphp
                                <a href="{{ route($route) }}" @if($active) aria-current="page" @endif
                                   class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-semibold no-underline {{ $active ? 'bg-ink text-white' : 'text-ink hover:bg-ivory' }}"><x-icon :name="$icon" :size="17" /> {{ $label }}</a>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </nav>
        </aside>
    </div>

    <div class="min-w-0">
        <header class="no-print sticky top-0 z-40 bg-white border-b border-line">
            <div class="flex items-center justify-between gap-3 px-4 lg:px-8 h-14">
                <button class="lg:hidden btn btn--ghost !px-2" @click="nav = true" aria-label="Open menu"><x-icon name="menu" :size="22" /></button>
                <a href="{{ route('home') }}" target="_blank" rel="noopener" class="link hit text-sm hidden sm:inline">View website</a>
                <div class="flex items-center gap-3 ml-auto text-sm">
                    @php $unreadAlerts = auth()->user()->unreadNotifications()->count(); @endphp
                    <a href="{{ route('admin.notifications.index') }}" class="relative hit grid place-content-center min-w-[40px] min-h-[40px]" aria-label="Alerts{{ $unreadAlerts ? ', '.$unreadAlerts.' unread' : '' }}"><x-icon name="bell" :size="20" />@if($unreadAlerts)<span class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 grid place-content-center rounded-full bg-gold text-ink text-[10px] font-bold" aria-hidden="true">{{ $unreadAlerts > 99 ? '99+' : $unreadAlerts }}</span>@endif</a>
                    <span class="text-grey-text hidden sm:inline">{{ auth()->user()->name }} · {{ auth()->user()->role?->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn--outline btn--sm">Sign out</button></form>
                </div>
            </div>
        </header>

        <main id="main" class="p-4 lg:p-8 max-w-[1400px]">
            @if(session('status'))<div class="alert alert--success mb-4" role="status">{{ session('status') }}</div>@endif
            @foreach(['delete', 'inventory', 'status', 'payment', 'role', 'value'] as $k)
                @error($k)<div class="alert alert--danger mb-4" role="alert">{{ $message }}</div>@enderror
            @endforeach
            @yield('content')
        </main>
    </div>
</div>
@include('partials.confirm-dialog')
</body>
</html>
