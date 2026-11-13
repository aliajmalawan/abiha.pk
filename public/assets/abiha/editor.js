/*
 * Simple formatting buttons for the long text boxes in the admin (<textarea data-rich>): bold, italic, headings, lists, quote, link, clear.
 * No libraries. The text stays plain HTML in the box; the server cleans it again on save, so only safe tags
 * (p, h2-h4, ul, ol, li, strong, em, a, br, blockquote) are ever kept. A "Preview" button shows roughly how it will look.
 */
(() => {
    'use strict';
    const BTN = [
        ['b', 'Bold', '<strong>', '</strong>', 'B'],
        ['i', 'Italic', '<em>', '</em>', 'I'],
        ['h2', 'Heading', '<h2>', '</h2>', 'H2'],
        ['h3', 'Sub-heading', '<h3>', '</h3>', 'H3'],
        ['ul', 'Bullet list', null, null, 'Bullets'],
        ['ol', 'Numbered list', null, null, 'Numbers'],
        ['q', 'Quote', '<blockquote>', '</blockquote>', 'Quote'],
        ['a', 'Link', null, null, 'Link'],
        ['p', 'Turn blank-line separated text into paragraphs', null, null, 'Paragraphs'],
        ['x', 'Remove formatting from the selection', null, null, 'Clear'],
    ];

    const changed = (ta) => { ta.focus(); ta.dispatchEvent(new Event('input', { bubbles: true })); };

    function wrap(ta, open, close) {
        const [s, e] = [ta.selectionStart, ta.selectionEnd];
        const sel = ta.value.slice(s, e) || 'text';
        ta.setRangeText(open + sel + close, s, e, 'select');
        changed(ta);
    }
    function list(ta, tag) {
        const [s, e] = [ta.selectionStart, ta.selectionEnd];
        const sel = ta.value.slice(s, e) || 'First item\nSecond item';
        const items = sel.split(/\n+/).map((l) => l.trim()).filter(Boolean)
            .map((l) => '  <li>' + l.replace(/^[-*•]\s*|^\d+[.)]\s*/, '') + '</li>');
        ta.setRangeText('<' + tag + '>\n' + items.join('\n') + '\n</' + tag + '>', s, e, 'select');
        changed(ta);
    }
    function paragraphs(ta) {
        const [s, e] = [ta.selectionStart, ta.selectionEnd];
        const all = s === e;
        const text = all ? ta.value : ta.value.slice(s, e);
        const out = text.split(/\n{2,}/).map((b) => b.trim()).filter(Boolean)
            .map((b) => (/^<(p|h\d|ul|ol|blockquote)/i.test(b) ? b : '<p>' + b.replace(/\n/g, '<br>\n') + '</p>')).join('\n\n');
        if (all) { ta.value = out; } else { ta.setRangeText(out, s, e, 'select'); }
        changed(ta);
    }
    function link(ta) {
        const url = window.prompt('Link address (https://... or /shop):', 'https://');
        if (!url || !/^(https?:\/\/|\/|mailto:|tel:)/i.test(url)) { return; }
        wrap(ta, '<a href="' + url.replace(/"/g, '&quot;') + '">', '</a>');
    }
    function clear(ta) {
        const [s, e] = [ta.selectionStart, ta.selectionEnd];
        if (s === e) { return; }
        ta.setRangeText(ta.value.slice(s, e).replace(/<\/?[a-z][^>]*>/gi, ''), s, e, 'select');
        changed(ta);
    }

    function enhance(ta) {
        if (ta.dataset.richReady) { return; }
        ta.dataset.richReady = '1';
        const compact = ta.hasAttribute('data-rich-compact');
        const box = document.createElement('div');
        box.className = 'rte';
        const bar = document.createElement('div');
        bar.className = 'rte__bar';
        bar.setAttribute('role', 'toolbar');
        bar.setAttribute('aria-label', 'Text formatting');
        for (const [key, label, open, close, text] of BTN) {
            if (compact && (key === 'h2' || key === 'p' || key === 'q')) { continue; }
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'rte__btn rte__btn--' + key;
            b.title = label;
            b.setAttribute('aria-label', label);
            b.textContent = text;
            b.addEventListener('click', () => {
                if (ta.hidden) { return; }
                if (open) { wrap(ta, open, close); }
                else if (key === 'ul' || key === 'ol') { list(ta, key); }
                else if (key === 'a') { link(ta); }
                else if (key === 'p') { paragraphs(ta); }
                else { clear(ta); }
            });
            bar.append(b);
        }
        const prev = document.createElement('button');
        prev.type = 'button';
        prev.className = 'rte__btn rte__btn--preview';
        prev.textContent = 'Preview';
        prev.setAttribute('aria-pressed', 'false');
        bar.append(prev);
        const view = document.createElement('div');
        view.className = 'rte__view prose-abiha';
        view.hidden = true;
        ta.parentNode.insertBefore(box, ta);
        box.append(bar, ta, view);
        prev.addEventListener('click', () => {
            const on = view.hidden;
            // an approximation of the page: the server decides what is finally kept
            const html = ta.value.replace(/<script[\s\S]*?<\/script>/gi, '').replace(/\son\w+="[^"]*"/gi, '');
            view.innerHTML = on ? (html.trim() || '<p class="muted">Nothing written yet.</p>') : '';
            view.hidden = !on;
            ta.hidden = on;
            bar.querySelectorAll('.rte__btn:not(.rte__btn--preview)').forEach((x) => { x.disabled = on; });
            prev.setAttribute('aria-pressed', String(on));
            prev.textContent = on ? 'Back to editing' : 'Preview';
        });
    }

    const run = () => document.querySelectorAll('textarea[data-rich]').forEach(enhance);
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', run); } else { run(); }
})();
