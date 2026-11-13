/*
 * ABIHA media: a full-screen photo viewer (lightbox) for the product gallery, and click-to-load video players.
 * Plain JavaScript, no libraries, no inline code (works with the site's content security policy).
 *
 *  - Lightbox: the gallery root carries data-lightbox='[{"src","thumb","alt"}...]'; any [data-lightbox-open] button inside it
 *    opens the viewer at the photo that is showing. Keys: Esc closes, arrows move, + / - / 0 zoom. Touch: swipe, double tap.
 *  - Video: [data-video-embed] frames show a poster and a play button; the YouTube / Vimeo player is only created on click.
 */
(() => {
    'use strict';

    /* ---------------------------------- video players ---------------------------------- */
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-video-play]');
        if (!btn) return;
        const frame = btn.closest('[data-video-embed]');
        if (!frame) return;
        const iframe = document.createElement('iframe');
        iframe.src = frame.dataset.videoEmbed;
        iframe.title = frame.dataset.videoTitle || 'Product video';
        iframe.allow = 'autoplay; fullscreen; picture-in-picture; encrypted-media';
        iframe.allowFullscreen = true;
        iframe.loading = 'lazy';
        iframe.referrerPolicy = 'strict-origin-when-cross-origin';
        iframe.className = 'pdp-video__iframe';
        frame.replaceChildren(iframe);
        frame.classList.add('is-playing');
        iframe.focus();
    });

    /* ---------------------------------- lightbox ---------------------------------- */
    let box = null;       // the open viewer's elements and state
    let opener = null;

    const el = (tag, cls, attrs = {}) => {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, v);
        return n;
    };
    const icon = (path) => `<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${path}</svg>`;
    const ICON = { close: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>', prev: '<path d="m15 18-6-6 6-6"/>', next: '<path d="m9 18 6-6-6-6"/>' };

    function open(items, start, from) {
        if (box || !items.length) return;
        opener = from || document.activeElement;
        const root = el('div', 'lb', { role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Product photos' });
        const bar = el('div', 'lb__bar');
        const count = el('span', 'lb__count', { 'aria-live': 'polite' });
        const close = el('button', 'lb__btn', { type: 'button', 'aria-label': 'Close photo viewer' });
        close.innerHTML = icon(ICON.close);
        bar.append(count, close);

        const stage = el('div', 'lb__stage');
        const fig = el('figure', 'lb__fig');
        const img = el('img', 'lb__img', { alt: '', decoding: 'async', draggable: 'false' });
        const cap = el('figcaption', 'lb__cap');
        fig.append(img, cap);
        const prev = el('button', 'lb__nav lb__nav--prev', { type: 'button', 'aria-label': 'Previous photo' });
        const next = el('button', 'lb__nav lb__nav--next', { type: 'button', 'aria-label': 'Next photo' });
        prev.innerHTML = icon(ICON.prev);
        next.innerHTML = icon(ICON.next);
        stage.append(prev, fig, next);

        const strip = el('div', 'lb__thumbs', { role: 'tablist', 'aria-label': 'Photos' });
        const thumbs = items.map((it, i) => {
            const t = el('button', 'lb__thumb', { type: 'button', role: 'tab', 'aria-label': `Photo ${i + 1}` });
            const ti = el('img', '', { src: it.thumb || it.src, alt: '', loading: 'lazy', decoding: 'async' });
            t.append(ti);
            t.addEventListener('click', () => go(i));
            strip.append(t);
            return t;
        });
        if (items.length < 2) { prev.hidden = next.hidden = true; strip.hidden = true; }

        root.append(bar, stage, strip);
        document.body.append(root);
        document.documentElement.classList.add('scroll-locked');
        box = { root, img, cap, count, thumbs, items, i: 0, zoom: 1, ox: 50, oy: 50, prev, next, close, x0: null, y0: null, last: 0 };

        const go = (n) => {
            box.i = (n + items.length) % items.length;
            const it = items[box.i];
            reset();
            img.src = it.src;
            img.alt = it.alt || '';
            cap.textContent = it.alt || '';
            count.textContent = `${box.i + 1} / ${items.length}`;
            thumbs.forEach((t, k) => { t.setAttribute('aria-selected', String(k === box.i)); t.classList.toggle('is-on', k === box.i); });
            thumbs[box.i]?.scrollIntoView({ block: 'nearest', inline: 'center' });
            // warm the neighbours so the arrows feel instant
            [box.i + 1, box.i - 1].forEach((k) => { const o = items[(k + items.length) % items.length]; if (o && o !== it) new Image().src = o.src; });
        };
        const reset = () => { box.zoom = 1; box.ox = box.oy = 50; paint(); };
        const paint = () => {
            img.style.transform = box.zoom > 1 ? `scale(${box.zoom})` : '';
            img.style.transformOrigin = `${box.ox}% ${box.oy}%`;
            root.classList.toggle('is-zoomed', box.zoom > 1);
        };
        const setZoom = (z, e) => {
            if (e) {
                const r = img.getBoundingClientRect();
                box.ox = Math.min(100, Math.max(0, ((e.clientX - r.left) / r.width) * 100));
                box.oy = Math.min(100, Math.max(0, ((e.clientY - r.top) / r.height) * 100));
            }
            box.zoom = Math.min(3, Math.max(1, z));
            paint();
        };
        box.go = go;

        prev.addEventListener('click', () => go(box.i - 1));
        next.addEventListener('click', () => go(box.i + 1));
        close.addEventListener('click', shut);
        root.addEventListener('click', (e) => { if (e.target === root || e.target === stage || e.target === fig) shut(); });
        img.addEventListener('click', (e) => setZoom(box.zoom > 1 ? 1 : 2, e));
        img.addEventListener('mousemove', (e) => { if (box.zoom > 1) setZoom(box.zoom, e); });
        // touch: swipe to change photo, double tap to zoom
        stage.addEventListener('touchstart', (e) => { if (e.touches.length === 1) { box.x0 = e.touches[0].clientX; box.y0 = e.touches[0].clientY; } }, { passive: true });
        stage.addEventListener('touchend', (e) => {
            if (box.x0 === null) return;
            const t = e.changedTouches[0];
            const dx = t.clientX - box.x0, dy = t.clientY - box.y0;
            box.x0 = box.y0 = null;
            if (box.zoom === 1 && Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) { go(box.i + (dx < 0 ? 1 : -1)); return; }
            const now = Date.now();
            if (now - box.last < 300) { setZoom(box.zoom > 1 ? 1 : 2.2, t); box.last = 0; } else { box.last = now; }
        });
        root.addEventListener('keydown', onKey);
        go(start);
        close.focus();
    }

    function onKey(e) {
        if (!box) return;
        if (e.key === 'Escape') { e.preventDefault(); shut(); }
        else if (e.key === 'ArrowRight') { e.preventDefault(); box.go(box.i + 1); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); box.go(box.i - 1); }
        else if (e.key === '+' || e.key === '=') { box.zoom = Math.min(3, box.zoom + 0.5); box.img.style.transform = `scale(${box.zoom})`; box.root.classList.add('is-zoomed'); }
        else if (e.key === '-') { box.zoom = Math.max(1, box.zoom - 0.5); box.img.style.transform = box.zoom > 1 ? `scale(${box.zoom})` : ''; box.root.classList.toggle('is-zoomed', box.zoom > 1); }
        else if (e.key === '0') { box.zoom = 1; box.img.style.transform = ''; box.root.classList.remove('is-zoomed'); }
        else if (e.key === 'Tab') {
            // keep keyboard focus inside the viewer
            const f = [...box.root.querySelectorAll('button:not([hidden])')];
            if (!f.length) return;
            const first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    }

    function shut() {
        if (!box) return;
        box.root.remove();
        box = null;
        document.documentElement.classList.remove('scroll-locked');
        if (opener && document.contains(opener)) opener.focus();
        opener = null;
    }

    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-lightbox-open]');
        if (!trigger) return;
        const root = trigger.closest('[data-lightbox]');
        if (!root) return;
        let items;
        try { items = JSON.parse(root.dataset.lightbox || '[]'); } catch { items = []; }
        const active = window.Alpine && typeof window.Alpine.$data === 'function' ? Number(window.Alpine.$data(root)?.active ?? 0) : 0;
        open(items, Number.isFinite(active) ? active : 0, trigger);
    });

    // a click on the big photo itself also opens the viewer (on screens that cannot hover; on desktop the hover zoom stays)
    document.addEventListener('click', (e) => {
        const media = e.target.closest('[data-lightbox] .pdp-media');
        if (!media || e.target.closest('button,a') || window.matchMedia('(hover:hover)').matches) return;
        media.querySelector('[data-lightbox-open]')?.click();
    });
})();
