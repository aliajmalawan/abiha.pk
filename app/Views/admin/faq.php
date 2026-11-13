<?php
/**
 * Admin → FAQ editor. @var list<array{title:string,items:list<array{q:string,a:string}>}> $groups @var bool $custom @var array<string,string> $tokens
 */
use App\Services\Admin\Form;

$data = ['groups' => $groups];
?>
<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="!text-2xl !mb-1">FAQ</h1>
        <p class="help !mb-0">The questions and answers on your <a class="link" href="<?= e(route('page', ['slug' => 'faq'])) ?>" target="_blank" rel="noopener">FAQ page</a>.
            <?= $custom ? 'You are showing <strong>your own</strong> FAQ.' : 'Now showing the <strong>built-in</strong> FAQ: change anything below and save to make it yours.' ?></p>
    </div>
    <?php if ($custom): ?>
        <form method="POST" action="<?= e(route('admin.faq.reset')) ?>" data-confirm="Throw away your changes and show the built-in FAQ again?">
            <?= csrf_field() ?><button class="btn btn--sm btn--outline">Use the built-in FAQ</button>
        </form>
    <?php endif; ?>
</div>

<form method="POST" action="<?= e(route('admin.faq.update')) ?>" class="mt-4 space-y-5" novalidate
      x-data="{ groups: <?= Form::js($data['groups']) ?>,
                addGroup() { if (this.groups.length < 12) this.groups.push({ title: '', items: [{ q: '', a: '' }] }); },
                addItem(g) { if (g.items.length < 30) g.items.push({ q: '', a: '' }); },
                moveItem(g, i, by) { const j = i + by; if (j < 0 || j >= g.items.length) return; [g.items[i], g.items[j]] = [g.items[j], g.items[i]]; },
                moveGroup(i, by) { const j = i + by; if (j < 0 || j >= this.groups.length) return; [this.groups[i], this.groups[j]] = [this.groups[j], this.groups[i]]; } }">
    <?= csrf_field() ?><?= method_field('PUT') ?>

    <template x-for="(g, gi) in groups" :key="gi">
        <section class="card space-y-4">
            <div class="flex flex-wrap items-end gap-3">
                <label class="field w-full sm:w-auto sm:flex-1"><span class="label">Group title</span><span class="help">Shown as a heading on the page, e.g. Payment.</span>
                    <input class="input" type="text" maxlength="60" x-model="g.title" :name="`groups[${gi}][title]`" placeholder="e.g. Ordering"></label>
                <div class="flex gap-1">
                    <button type="button" class="btn btn--sm btn--outline" @click="moveGroup(gi, -1)" :disabled="gi === 0" aria-label="Move this group up">&uarr;</button>
                    <button type="button" class="btn btn--sm btn--outline" @click="moveGroup(gi, 1)" :disabled="gi === groups.length - 1" aria-label="Move this group down">&darr;</button>
                    <button type="button" class="act act--del" @click="if (confirm('Remove this whole group and its questions?')) groups.splice(gi, 1)">Remove group</button>
                </div>
            </div>
            <template x-for="(it, ii) in g.items" :key="ii">
                <div class="rounded-md border border-line p-4 space-y-3 bg-ivory">
                    <label class="field"><span class="label">Question</span>
                        <input class="input" type="text" maxlength="200" x-model="it.q" :name="`groups[${gi}][items][${ii}][q]`" placeholder="e.g. How long does delivery take?"></label>
                    <label class="field"><span class="label">Answer</span>
                        <textarea class="textarea !min-h-[110px]" maxlength="3000" x-model="it.a" :name="`groups[${gi}][items][${ii}][a]`" placeholder="Write the answer in plain words."></textarea></label>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="btn btn--sm btn--outline" @click="moveItem(g, ii, -1)" :disabled="ii === 0" aria-label="Move question up">&uarr; Up</button>
                        <button type="button" class="btn btn--sm btn--outline" @click="moveItem(g, ii, 1)" :disabled="ii === g.items.length - 1" aria-label="Move question down">&darr; Down</button>
                        <button type="button" class="act act--del" @click="g.items.splice(ii, 1)">Remove question</button>
                    </div>
                </div>
            </template>
            <button type="button" class="btn btn--sm btn--outline" @click="addItem(g)"><?= icon('plus', 14) ?> Add a question</button>
        </section>
    </template>

    <div class="flex flex-wrap items-center gap-3">
        <button type="button" class="btn btn--outline" @click="addGroup()"><?= icon('plus', 14) ?> Add a group</button>
    </div>

    <details class="card !p-4">
        <summary class="cursor-pointer font-semibold">Words that become links or live values inside an answer</summary>
        <p class="help mt-2">Type these exactly (with the curly brackets) inside an answer; the page replaces them with the real link or value.</p>
        <ul class="mt-2 grid gap-1 text-sm sm:grid-cols-2">
            <?php foreach ($tokens as $k => $v): ?><li><code class="font-semibold"><?= e($k) ?></code> <span class="text-grey-text">&mdash; <?= e($v) ?></span></li><?php endforeach; ?>
        </ul>
        <p class="help mt-2">Answers are plain text (no formatting). Do not put prices, delivery times or promises here unless they are true for your shop.</p>
    </details>

    <div class="pf-bar no-print">
        <div class="pf-bar__info"><span class="pf-bar__dot" aria-hidden="true"></span><p><span class="pf-bar__label">FAQ</span><strong><span x-text="groups.reduce((n, g) => n + g.items.filter((i) => i.q.trim()).length, 0)"></span> questions</strong></p></div>
        <div class="pf-bar__actions">
            <a href="<?= e(route('admin.content')) ?>" class="pf-bar__cancel">Back</a>
            <button type="submit" class="pf-bar__save"><?= icon('check', 18) ?> Save FAQ</button>
        </div>
    </div>
</form>
