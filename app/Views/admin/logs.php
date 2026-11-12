<?php
/** Activity log (port of admin/logs.blade.php). @var array $logs (Pager) @var array $filters */
use App\Core\View;
use App\Services\Admin\Display;

$q = is_scalar($filters['q'] ?? null) ? (string) $filters['q'] : '';
?>
<h1 class="!text-2xl">Activity log</h1>
<p class="help mb-4">Who changed what. Entries cannot be edited or deleted.</p>
<form method="GET" class="mb-4 flex gap-2 max-w-md" role="search"><input name="q" value="<?= e($q) ?>" class="input !min-h-[40px]" placeholder="Filter by action, e.g. order or product" aria-label="Filter"><button class="btn btn--sm">Filter</button></form>
<div class="card !p-0 overflow-x-auto"><table class="table"><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Subject</th><th>Details</th><th>IP</th></tr></thead><tbody>
    <?php foreach ($logs['items'] as $l): ?>
        <?php $props = $l['properties'] !== null ? json_decode((string) $l['properties'], true) : null; ?>
        <tr><td class="whitespace-nowrap"><?= e(dt($l['created_at'], 'j M Y, g:i a')) ?></td><td><?= e($l['user_name'] ?? 'System') ?></td><td><span class="badge badge--ivory"><?= e($l['action']) ?></span></td>
            <td class="text-grey-text"><?= $l['subject_type'] ? e(Display::basename($l['subject_type']) . ' #' . $l['subject_id']) : '-' ?></td>
            <td class="text-xs text-grey-text max-w-xs truncate" title="<?= e(json_encode($props)) ?>"><?= $props ? e(mb_strlen($j = (string) json_encode($props, JSON_UNESCAPED_UNICODE)) > 80 ? rtrim(mb_substr($j, 0, 80)) . '...' : $j) : '' ?></td><td class="text-xs"><?= e($l['ip_address']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$logs['items']): ?><tr><td colspan="6" class="text-center py-10 muted">No activity yet.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="mt-6"><?= View::partial('admin/partials/pagination', ['pager' => $logs]) ?></div>
