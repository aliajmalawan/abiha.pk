<?php
/** Newsletter subscribers (port of admin/newsletter/index.blade.php). @var array $subscribers (Pager) @var int $activeCount @var string $q */
use App\Core\View;
?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="!text-2xl !mb-0">Newsletter <span class="text-grey-text text-base font-normal">(<?= (int) $activeCount ?> active)</span></h1>
    <a href="<?= e(route('admin.newsletter.export')) ?>" class="btn btn--sm btn--outline">Export CSV</a>
</div>
<form method="GET" class="my-4 flex gap-2 max-w-md" role="search"><input name="q" value="<?= e($q) ?>" class="input !min-h-[40px]" placeholder="Search email" aria-label="Search"><button class="btn btn--sm">Search</button></form>
<div class="card !p-0 overflow-x-auto"><table class="table"><thead><tr><th>Email</th><th>Subscribed</th><th>Status</th><th class="num">Actions</th></tr></thead><tbody>
    <?php foreach ($subscribers['items'] as $s): ?>
        <tr><td><?= e($s['email']) ?></td><td><?= e(dt($s['subscribed_at'], 'j M Y')) ?></td>
            <td><span class="badge <?= $s['unsubscribed_at'] ? 'badge--ivory' : 'badge--success' ?>"><?= $s['unsubscribed_at'] ? 'Unsubscribed' : 'Active' ?></span></td>
            <td class="num"><form method="POST" action="<?= e(route('admin.newsletter.destroy', ['id' => $s['id']])) ?>" data-confirm="Remove this subscriber?"><?= csrf_field() ?><?= method_field('DELETE') ?><button class="underline text-danger">Remove</button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$subscribers['items']): ?><tr><td colspan="4" class="text-center py-10 muted">No subscribers yet.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="mt-6"><?= View::partial('admin/partials/pagination', ['pager' => $subscribers]) ?></div>
