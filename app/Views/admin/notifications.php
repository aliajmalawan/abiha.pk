<?php
/** The signed-in person's alerts (port of admin/notifications.blade.php). @var array $notifications (Pager, data decoded) @var bool $hasUnread */
use App\Core\View;
use App\Services\Admin\Display;
?>
<div class="flex items-center justify-between gap-3">
    <h1 class="!text-2xl">Alerts</h1>
    <?php if ($hasUnread): ?>
        <form method="POST" action="<?= e(route('admin.notifications.read-all')) ?>"><?= csrf_field() ?><button class="btn btn--outline btn--sm">Mark all as read</button></form>
    <?php endif; ?>
</div>

<div class="card !p-0 mt-4 max-w-3xl divide-y divide-line">
    <?php foreach ($notifications['items'] as $n): ?>
        <form method="POST" action="<?= e(route('admin.notifications.read', ['id' => $n['id']])) ?>">
            <?= csrf_field() ?>
            <button class="w-full text-left p-4 flex gap-3 hover:bg-ivory"<?php if ($n['read_at'] === null): ?> aria-label="Unread: <?= e($n['data']['title'] ?? '') ?>"<?php endif; ?>>
                <span class="mt-1.5 size-2 shrink-0 rounded-full <?= $n['read_at'] ? 'bg-line' : 'bg-gold' ?>" aria-hidden="true"></span>
                <span class="flex-1">
                    <span class="block <?= $n['read_at'] ? '' : 'font-semibold' ?>"><?= e($n['data']['title'] ?? 'Alert') ?></span>
                    <span class="block text-sm text-grey-text"><?= e($n['data']['body'] ?? '') ?></span>
                    <span class="block text-xs text-grey-text mt-1"><?= e(Display::ago($n['created_at'])) ?></span>
                </span>
            </button>
        </form>
    <?php endforeach; ?>
    <?php if (!$notifications['items']): ?>
        <p class="p-6 muted text-sm">No alerts yet. New orders and bank transfers waiting to be verified will appear here.</p>
    <?php endif; ?>
</div>
<div class="mt-4"><?= View::partial('admin/partials/pagination', ['pager' => $notifications]) ?></div>
