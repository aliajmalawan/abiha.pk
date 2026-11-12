<?php
/**
 * Storefront header (port of resources/views/partials/header.blade.php): announce bar, sticky header with the mega menu,
 * search overlay (Alpine searchBox) and the mobile drawer.
 *
 * @var list<array{id:int,name:string,slug:string,children:list<array>}> $navCategories
 * @var int $cartCount
 * @var array{text:?string,url:?string} $announcement
 */
use App\Auth\Auth;
use App\Support\Menus;

$navCategories = $navCategories ?? [];
$announcement = $announcement ?? ['text' => null, 'url' => null];
$cartCount = (int) ($cartCount ?? 0);
$simpleLinks = Menus::get('header');       // editable under Admin -> Menus
$topPhone = trim((string) setting('contact.phone', cfg('abiha.contact.phone')));
$topEmail = trim((string) setting('contact.email', cfg('abiha.contact.email')));
$signedIn = Auth::check();
$request = App\Core\View::shared('request');
$fullUrl = $request instanceof App\Core\Request ? rtrim($request->fullUrl(), '/') : '';
$firstCatId = $navCategories[0]['id'] ?? null;
?>
<?php if (!empty($announcement['text']) || $topPhone !== '' || $topEmail !== ''): ?>
    <div class="announce">
        <div class="announce__in">
            <?php if ($topPhone !== '' || $topEmail !== ''): ?>
                <p class="announce__contact">
                    <?php if ($topPhone !== ''): ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $topPhone)) ?>" class="announce__link"><?= icon('phone', 14) ?><span><?= e($topPhone) ?></span></a><?php endif; ?>
                    <?php if ($topEmail !== ''): ?><a href="mailto:<?= e($topEmail) ?>" class="announce__link"><?= icon('mail', 14) ?><span><?= e($topEmail) ?></span></a><?php endif; ?>
                </p>
            <?php endif; ?>
            <?php if (!empty($announcement['text'])): ?>
                <p class="announce__text"><?php if (!empty($announcement['url'])): ?><a href="<?= e($announcement['url']) ?>" class="hover:underline"><?= e($announcement['text']) ?></a><?php else: ?><?= e($announcement['text']) ?><?php endif; ?></p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<header class="sticky top-0 z-[100] border-b border-line bg-ivory"
        x-data="{ open: false, mega: false, searchOpen: false, mobileCat: null, megaCat: <?= $firstCatId !== null ? (int) $firstCatId : 'null' ?> }"
        @keydown.escape.window="open = false; mega = false"
        @click.outside="mega = false"
        @mouseleave="mega = false">
    <div class="container flex items-center justify-between gap-4" style="min-height:64px">
        <?php /* Mobile menu button */ ?>
        <button class="btn btn--ghost lg:hidden !px-2" @click="open = true" aria-label="Open menu" :aria-expanded="open">
            <?= icon('menu', 24) ?>
        </button>

        <a href="<?= e(route('home')) ?>" aria-label="ABIHA home" class="shrink-0 -my-2">
            <?= logo(76) ?>
        </a>

        <?php /* Desktop nav */ ?>
        <nav class="hidden lg:flex items-center gap-8" aria-label="Primary">
            <div class="relative" @mouseenter="mega = true">
                <button type="button" class="nav-link inline-flex items-center gap-1" @click="mega = ! mega" :aria-expanded="mega" aria-controls="mega-menu" <?php if (route_is('shop', 'category', 'collection')): ?> aria-current="page" <?php endif; ?>>
                    Shop <?= icon('chevron-down', 14) ?>
                </button>
            </div>
            <?php foreach ($simpleLinks as $l): ?>
                <a class="nav-link" href="<?= e($l['href']) ?>" @mouseenter="mega = false" <?php if ($fullUrl === rtrim(url($l['href']), '/')): ?> aria-current="page" <?php endif; ?>><?= e($l['label']) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="flex items-center gap-0.5 sm:gap-1">
            <button class="btn btn--ghost !px-2.5" @click="searchOpen = true; $nextTick(() => document.getElementById('site-search')?.focus())" aria-label="Search"><?= icon('search') ?></button>
            <a class="btn btn--ghost !px-2.5 relative hidden sm:inline-flex" href="<?= e(route('wishlist')) ?>" aria-label="Wishlist">
                <?= icon('heart') ?>
                <span x-data x-cloak x-show="$store.wish.ids.length" x-text="$store.wish.ids.length" class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-gold text-ink text-[11px] font-bold grid place-content-center"></span>
            </a>
            <a class="btn btn--ghost !px-2.5" href="<?= e($signedIn ? route('account.dashboard') : route('login')) ?>" aria-label="<?= $signedIn ? 'My account' : 'Sign in' ?>"><?= icon('user') ?></a>
            <a class="btn btn--ghost !px-2.5 relative" href="<?= e(route('cart')) ?>" aria-label="Shopping bag" aria-haspopup="dialog" @click.prevent="$store.drawer.show()">
                <?= icon('shopping-bag') ?>
                <span x-data x-text="$store.cart.count" x-show="$store.cart.count > 0" <?php if ($cartCount < 1): ?> x-cloak <?php endif; ?> class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-ink text-white text-[11px] font-bold grid place-content-center"></span>
            </a>
        </div>
    </div>

    <?php /* Mega menu: categories on the left, the chosen category's sub-categories on the right, a "New arrivals" card at the end */ ?>
    <div id="mega-menu" x-cloak x-show="mega" x-transition.opacity.duration.150ms class="mega hidden lg:block absolute inset-x-0 top-full">
        <div class="container mega__in">
            <ul class="mega__cats" aria-label="Categories">
                <?php foreach ($navCategories as $cat): ?>
                    <li>
                        <a href="<?= e(route('category', $cat)) ?>" class="mega__cat" :class="{ 'is-on': megaCat === <?= (int) $cat['id'] ?> }"
                           @mouseenter="megaCat = <?= (int) $cat['id'] ?>" @focus="megaCat = <?= (int) $cat['id'] ?>" @click="mega = false">
                            <span><?= e($cat['name']) ?></span><?= icon('chevron-right', 16) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="mega__panel">
                <?php foreach ($navCategories as $i => $cat): ?>
                    <div x-show="megaCat === <?= (int) $cat['id'] ?>" <?php if ($i > 0): ?> x-cloak <?php endif; ?>>
                        <p class="mega__title"><?= e($cat['name']) ?></p>
                        <hr class="gold-rule !my-3">
                        <?php if (!empty($cat['children'])): ?>
                            <ul class="mega__cols">
                                <?php foreach ($cat['children'] as $child): ?>
                                    <li><a href="<?= e(route('category', $child)) ?>" @click="mega = false"><?= e($child['name']) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="mega__note">Browse everything in <?= e($cat['name']) ?>.</p>
                        <?php endif; ?>
                        <a href="<?= e(route('category', $cat)) ?>" class="mega__all" @click="mega = false">View all <?= e($cat['name']) ?> <?= icon('arrow-right', 16) ?></a>
                    </div>
                <?php endforeach; ?>
            </div>

            <a href="<?= e(route('shop', ['sort' => 'newest'])) ?>" @click="mega = false" class="mega__promo group">
                <span class="eyebrow">Just in</span>
                <span class="mega__promo-title">New arrivals</span>
                <span class="mega__promo-text">See what's new this season.</span>
                <span class="mega__promo-link">Shop now <?= icon('arrow-right', 16, 'transition group-hover:translate-x-1') ?></span>
            </a>
        </div>
    </div>

    <?php /* Search overlay */ ?>
    <div x-cloak x-show="searchOpen" class="fixed inset-0 z-[250]" role="dialog" aria-modal="true" aria-label="Search" x-transition:enter="transition-opacity duration-150" x-transition:enter-start="opacity-0" x-transition:leave="transition-opacity duration-100" x-transition:leave-end="opacity-0"
         x-data="searchBox('<?= e(route('search.suggest')) ?>')" @keydown.escape.window="searchOpen = false">
        <div class="absolute inset-0 dialog-scrim" @click="searchOpen = false"></div>
        <div class="relative bg-white" tabindex="-1" x-trap="searchOpen">
            <div class="container py-6">
                <form action="<?= e(route('shop')) ?>" method="GET" role="search" class="flex items-center gap-3">
                    <?= icon('search', 22) ?>
                    <input id="site-search" x-model="q" @input="lookup()" name="q" type="search" placeholder="Search products" autocomplete="off"
                           class="flex-1 min-h-[52px] text-lg bg-transparent outline-none placeholder:text-grey-text" aria-label="Search products">
                    <button type="button" class="btn btn--ghost !px-2" @click="searchOpen = false" aria-label="Close search"><?= icon('x', 22) ?></button>
                </form>
                <div class="mt-4 border-t border-line pt-4" x-show="q.trim().length >= 2">
                    <div x-show="loading && results.length === 0" class="grid gap-3 md:grid-cols-2" aria-busy="true" aria-label="Searching"><?php for ($i = 0; $i < 4; $i++): ?><div class="flex items-center gap-4 p-2"><span class="skeleton size-14"></span><span class="flex-1 space-y-2"><span class="skeleton skeleton--line w-2/3"></span><span class="skeleton skeleton--line w-1/4"></span></span></div><?php endfor; ?></div>
                    <p x-show="!loading && results.length === 0" class="muted text-sm" role="status">No matches yet. Press Enter to search the whole shop.</p>
                    <ul class="grid gap-1 md:grid-cols-2">
                        <template x-for="r in results" :key="r.url">
                            <li><a :href="r.url" class="flex items-center gap-4 rounded-md p-2 no-underline hover:bg-ivory">
                                <span class="size-14 shrink-0 rounded bg-ivory border border-line overflow-hidden grid place-content-center"><img x-show="r.image" :src="r.image" alt="" class="size-full object-cover"></span>
                                <span><span class="block font-semibold text-sm" x-text="r.name"></span><span class="block text-xs text-grey-text" x-text="r.price"></span></span>
                            </a></li>
                        </template>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <?php /* Mobile drawer */ ?>
    <div x-cloak x-show="open" class="fixed inset-0 z-[200] lg:hidden" role="dialog" aria-modal="true" aria-label="Menu">
        <div class="absolute inset-0 dialog-scrim" @click="open = false" x-show="open" x-transition:enter="transition-opacity duration-200" x-transition:enter-start="opacity-0" x-transition:leave="transition-opacity duration-150" x-transition:leave-end="opacity-0"></div>
        <div class="absolute inset-y-0 left-0 w-[88%] max-w-sm bg-ivory flex flex-col overflow-y-auto" tabindex="-1" x-trap="open" x-show="open"
             x-transition:enter="transition-transform duration-300 ease-out" x-transition:enter-start="-translate-x-full" x-transition:leave="transition-transform duration-200 ease-in" x-transition:leave-end="-translate-x-full">
            <div class="flex items-center justify-between p-4 border-b border-line">
                <?= logo(72, true) ?>
                <button class="btn btn--ghost !px-2" @click="open = false" aria-label="Close menu"><?= icon('x', 24) ?></button>
            </div>
            <nav class="flex-1 p-4" aria-label="Mobile">
                <?php foreach ($navCategories as $cat): $cid = (int) $cat['id']; ?>
                    <div class="border-b border-line">
                        <button type="button" class="w-full flex items-center justify-between py-4 font-display font-semibold uppercase tracking-wider text-sm"
                                @click="mobileCat = mobileCat === <?= $cid ?> ? null : <?= $cid ?>" :aria-expanded="mobileCat === <?= $cid ?>">
                            <?= e($cat['name']) ?> <?= icon('chevron-down', 18, ['class' => 'transition-transform', 'x-bind:class' => "mobileCat === {$cid} && 'rotate-180'"]) ?>
                        </button>
                        <ul x-show="mobileCat === <?= $cid ?>" class="pb-3 pl-3 space-y-1 text-sm">
                            <li><a class="block py-2 font-semibold" href="<?= e(route('category', $cat)) ?>">All <?= e($cat['name']) ?></a></li>
                            <?php foreach ($cat['children'] as $child): ?><li><a class="block py-2 text-grey-text" href="<?= e(route('category', $child)) ?>"><?= e($child['name']) ?></a></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($simpleLinks as $l): ?>
                    <a href="<?= e($l['href']) ?>" class="block py-4 border-b border-line font-display font-semibold uppercase tracking-wider text-sm"><?= e($l['label']) ?></a>
                <?php endforeach; ?>
                <a href="<?= e(route('wishlist')) ?>" class="block py-4 border-b border-line font-display font-semibold uppercase tracking-wider text-sm">Wishlist</a>
            </nav>
            <div class="p-4 border-t border-line">
                <?php if ($signedIn): ?>
                    <a href="<?= e(route('account.dashboard')) ?>" class="btn btn--outline btn--block">My account</a>
                <?php else: ?>
                    <a href="<?= e(route('login')) ?>" class="btn btn--block">Sign in</a>
                    <p class="text-center text-sm mt-3 muted">New here? <a class="link" href="<?= e(route('register')) ?>">Create an account</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>
