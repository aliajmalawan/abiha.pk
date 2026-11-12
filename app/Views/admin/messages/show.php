<?php
/** One contact message (port of admin/messages/show.blade.php). Plain text only: customer input is never rendered as HTML. @var array $message */
?>
<a href="<?= e(route('admin.messages.index')) ?>" class="link text-sm inline-flex items-center gap-1 mb-4"><?= icon('chevron-left', 14) ?> Messages</a>
<article class="card max-w-3xl">
    <h1 class="!text-xl"><?= e($message['subject'] ?: 'Message from ' . $message['name']) ?></h1>
    <p class="text-sm text-grey-text"><?= e($message['name']) ?> · <a class="link" href="mailto:<?= e($message['email']) ?>"><?= e($message['email']) ?></a><?php if ($message['phone']): ?> · <?= e($message['phone']) ?><?php endif; ?> · <?= e(dt($message['created_at'], 'j M Y, g:i a')) ?></p>
    <div class="mt-4 whitespace-pre-wrap text-sm leading-relaxed"><?= e($message['message']) ?></div>
    <div class="flex flex-wrap gap-3 mt-6">
        <a class="btn btn--sm" href="mailto:<?= e($message['email']) ?>?subject=<?= e(rawurlencode('Re: ' . ($message['subject'] ?: 'Your message'))) ?>">Reply by email</a>
        <form method="POST" action="<?= e(route('admin.messages.update', ['id' => $message['id']])) ?>"><?= csrf_field() ?><?= method_field('PATCH') ?><input type="hidden" name="status" value="replied"><button class="btn btn--sm btn--outline">Mark as replied</button></form>
        <form method="POST" action="<?= e(route('admin.messages.destroy', ['id' => $message['id']])) ?>" data-confirm="Delete this message?"><?= csrf_field() ?><?= method_field('DELETE') ?><button class="btn btn--sm btn--ghost text-danger">Delete</button></form>
    </div>
</article>
