<?php
/**
 * Admin → Content overview. @var list<array{title:string,note:string,items:list<array<string,mixed>>}> $sections @var int $done @var int $total
 */
$pct = $total > 0 ? (int) round($done * 100 / $total) : 0;
?>
<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="!text-2xl !mb-1">Content overview</h1>
        <p class="help !mb-0">Everything you can change on your website, where to change it, and what still needs writing. Nothing needs a developer.</p>
    </div>
    <a href="<?= e(route('home')) ?>" target="_blank" rel="noopener" class="btn btn--sm btn--outline">View website</a>
</div>

<section class="card mt-4 flex flex-wrap items-center gap-4" aria-label="Progress">
    <div class="flex-1 min-w-[14rem]">
        <p class="eyebrow !m-0">Ready for customers</p>
        <p class="font-display text-2xl !mb-2"><b><?= (int) $done ?></b> of <?= (int) $total ?> things done</p>
        <div class="h-2 rounded bg-line" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>" aria-label="<?= $pct ?> percent done"><div class="h-full rounded bg-gold" style="width:<?= $pct ?>%"></div></div>
    </div>
    <p class="text-sm text-grey-text max-w-md !mb-0">Items marked <strong class="text-ink">Needs attention</strong> are the ones customers would notice first. Press the button next to an item to fix it.</p>
</section>

<div class="mt-6 space-y-6">
    <?php foreach ($sections as $sec): ?>
        <section class="card !p-0 overflow-hidden" aria-labelledby="c-<?= e(preg_replace('/[^a-z0-9]+/', '-', strtolower($sec['title']))) ?>">
            <header class="px-5 pt-5 pb-3 border-b border-line">
                <h2 id="c-<?= e(preg_replace('/[^a-z0-9]+/', '-', strtolower($sec['title']))) ?>" class="!text-lg !mb-0"><?= e($sec['title']) ?></h2>
                <p class="help !mb-0"><?= e($sec['note']) ?></p>
            </header>
            <ul class="divide-y divide-line">
                <?php foreach ($sec['items'] as $i): ?>
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4">
                        <span class="shrink-0" aria-hidden="true"><?php if ($i['status'] === 'ok'): ?><span class="grid size-8 place-items-center rounded-full bg-success-bg text-success" style="background:var(--success-bg);color:var(--success)"><?= icon('check', 16) ?></span>
                            <?php elseif ($i['status'] === 'todo'): ?><span class="grid size-8 place-items-center rounded-full" style="background:var(--warning-bg);color:var(--warning)"><?= icon('circle-alert', 16) ?></span>
                            <?php else: ?><span class="grid size-8 place-items-center rounded-full bg-ivory text-grey-text"><?= icon('info', 16) ?></span><?php endif; ?></span>
                        <div class="flex-1 min-w-[14rem]">
                            <p class="font-semibold !mb-0"><?= e($i['label']) ?>
                                <?php if ($i['status'] === 'todo'): ?><span class="badge badge--warning ml-2">Needs attention</span>
                                <?php elseif ($i['status'] === 'ok'): ?><span class="badge badge--success ml-2">Done</span><?php endif; ?></p>
                            <p class="text-sm text-grey-text !mb-0"><?= e($i['detail']) ?></p>
                        </div>
                        <a href="<?= e($i['url']) ?>" class="btn btn--sm <?= $i['status'] === 'todo' ? '' : 'btn--outline' ?>"><?= e($i['cta']) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>

    <section class="card">
        <h2 class="!text-lg">Where each kind of content lives</h2>
        <dl class="grid gap-x-8 gap-y-3 text-sm md:grid-cols-2">
            <div><dt class="font-semibold">Products, photos, prices, stock, videos</dt><dd class="text-grey-text">Products, Categories, Collections, Inventory</dd></div>
            <div><dt class="font-semibold">Home page pictures and blocks</dt><dd class="text-grey-text">Banners and Homepage</dd></div>
            <div><dt class="font-semibold">Policies, About us, any extra page</dt><dd class="text-grey-text">Pages (with bold, headings, lists and links buttons)</dd></div>
            <div><dt class="font-semibold">Questions and answers</dt><dd class="text-grey-text">FAQ</dd></div>
            <div><dt class="font-semibold">Menu links, footer links</dt><dd class="text-grey-text">Menus and Pages ("Link from footer")</dd></div>
            <div><dt class="font-semibold">Phone, email, address, social links, logo, favicon</dt><dd class="text-grey-text">General settings</dd></div>
            <div><dt class="font-semibold">Customer messages, reviews, newsletter list</dt><dd class="text-grey-text">Messages, Reviews, Newsletter</dd></div>
            <div><dt class="font-semibold">Visitors and traffic</dt><dd class="text-grey-text">Analytics</dd></div>
        </dl>
    </section>
</div>
