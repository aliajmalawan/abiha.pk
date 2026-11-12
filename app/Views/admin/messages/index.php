<?php
/** Contact messages (port of admin/messages/index.blade.php). @var array $messages (Pager) @var ?string $status */
use App\Core\View;
?>
<h1 class="!text-2xl">Contact messages</h1>
<div class="flex gap-2 my-4 text-sm">
    <a href="<?= e(route('admin.messages.index')) ?>" class="badge <?= !$status ? '' : 'badge--ivory' ?> no-underline">All</a>
    <?php foreach (['new' => 'New', 'read' => 'Read', 'replied' => 'Replied'] as $k => $l): ?><a href="<?= e(route('admin.messages.index', ['status' => $k])) ?>" class="badge <?= $status === $k ? '' : 'badge--ivory' ?> no-underline"><?= e($l) ?></a><?php endforeach; ?>
</div>
<div class="card !p-0 overflow-x-auto"><table class="table"><thead><tr><th>From</th><th>Subject</th><th>Received</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($messages['items'] as $m): ?>
        <tr><td><a class="link <?= $m['status'] === 'new' ? 'font-bold' : '' ?>" href="<?= e(route('admin.messages.show', ['id' => $m['id']])) ?>"><?= e($m['name']) ?></a><br><span class="text-xs text-grey-text"><?= e($m['email']) ?></span></td>
            <td><?= e(str_limit($m['subject'] ?: $m['message'], 60)) ?></td><td><?= e(dt($m['created_at'], 'j M Y, g:i a')) ?></td>
            <td><span class="badge <?= $m['status'] === 'new' ? 'badge--warning' : ($m['status'] === 'replied' ? 'badge--success' : 'badge--ivory') ?>"><?= e(ucfirst((string) $m['status'])) ?></span></td></tr>
    <?php endforeach; ?>
    <?php if (!$messages['items']): ?><tr><td colspan="4" class="text-center py-10 muted">No messages.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="mt-6"><?= View::partial('admin/partials/pagination', ['pager' => $messages]) ?></div>
