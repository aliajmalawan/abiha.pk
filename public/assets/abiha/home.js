/*
 * ABIHA home page depth and motion. Plain JavaScript, no libraries, no inline code (fits the site's content security policy).
 * It only sets CSS custom properties; every transform lives in theme.css (".home-3d" rules), so the page looks exactly the
 * same without this file, and nothing here runs for people who asked their system for less motion.
 *
 *  - [data-tilt]      a card follows the pointer in 3D (fine pointers only): --tx / --ty in -1..1, --gx / --gy for the light spot
 *  - [data-hero]      the hero picture and its text card move a little with the pointer (--px / --py) and with scrolling (--sy)
 *  - [data-parallax]  --sy = how far the element has travelled through the viewport (0 entering at the bottom, 1 leaving at the top)
 *  - [data-count]     a figure counts up from 0 to its real value the first time it scrolls into view
 */
(() => {
    'use strict';
    const root = document.querySelector('.home-3d');
    if (!root || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
    const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

    /* ------------------------------ one frame loop: targets ease towards their value ------------------------------ */
    const jobs = new Map();     // element -> { vars: {name: target}, now: {name: current} }
    let raf = 0;
    const tick = () => {
        raf = 0;
        let busy = false;
        jobs.forEach((j, el) => {
            let settled = true;
            for (const k in j.vars) {
                const t = j.vars[k];
                const c = j.now[k] ?? t;
                const n = Math.abs(t - c) < 0.002 ? t : c + (t - c) * j.ease;
                if (n !== c) settled = false;
                j.now[k] = n;
                el.style.setProperty(k, n.toFixed(4));
            }
            if (settled && j.drop) {
                jobs.delete(el);
                for (const k in j.vars) el.style.removeProperty(k);
                el.classList.remove('is-tilting');
            } else if (!settled) {
                busy = true;
            }
        });
        if (busy) raf = requestAnimationFrame(tick);
    };
    const set = (el, vars, ease = 0.14, drop = false) => {
        const j = jobs.get(el) || { vars: {}, now: {}, ease };
        Object.assign(j.vars, vars);
        j.ease = ease;
        j.drop = drop;
        jobs.set(el, j);
        if (!raf) raf = requestAnimationFrame(tick);
    };

    /* ------------------------------ tilt ------------------------------ */
    if (finePointer) {
        root.addEventListener('pointermove', (e) => {
            const el = e.target.closest('[data-tilt]');
            if (!el || e.pointerType !== 'mouse') return;
            const r = el.getBoundingClientRect();
            if (!r.width || !r.height) return;
            const x = clamp((e.clientX - r.left) / r.width, 0, 1);
            const y = clamp((e.clientY - r.top) / r.height, 0, 1);
            el.classList.add('is-tilting');
            set(el, { '--tx': (x - 0.5) * 2, '--ty': (y - 0.5) * 2, '--gx': x * 100, '--gy': y * 100 }, 0.22);
        }, { passive: true });
        root.addEventListener('pointerout', (e) => {
            const el = e.target.closest('[data-tilt]');
            if (!el || (e.relatedTarget && el.contains(e.relatedTarget))) return;
            set(el, { '--tx': 0, '--ty': 0 }, 0.1, true);
        }, { passive: true });
    }

    /* ------------------------------ hero: pointer ------------------------------ */
    const hero = root.querySelector('[data-hero]');
    if (hero && finePointer) {
        hero.addEventListener('pointermove', (e) => {
            if (e.pointerType !== 'mouse') return;
            const r = hero.getBoundingClientRect();
            set(hero, { '--px': clamp(((e.clientX - r.left) / r.width - 0.5) * 2, -1, 1), '--py': clamp(((e.clientY - r.top) / r.height - 0.5) * 2, -1, 1) }, 0.08);
        }, { passive: true });
        hero.addEventListener('pointerleave', () => set(hero, { '--px': 0, '--py': 0 }, 0.06), { passive: true });
    }

    /* ------------------------------ scroll parallax (only for what is on screen) ------------------------------ */
    const moving = new Set();
    const items = [...root.querySelectorAll('[data-parallax]'), ...(hero ? [hero] : [])];
    const place = () => {
        const vh = window.innerHeight || 1;
        moving.forEach((el) => {
            const r = el.getBoundingClientRect();
            // 0 when the top edge enters at the bottom of the window, 1 when the bottom edge leaves at the top
            const p = clamp((vh - r.top) / (vh + r.height), 0, 1);
            el.style.setProperty('--sy', p.toFixed(4));
        });
    };
    let scrollRaf = 0;
    const onScroll = () => { if (!scrollRaf) scrollRaf = requestAnimationFrame(() => { scrollRaf = 0; place(); }); };
    if (items.length && 'IntersectionObserver' in window) {
        const io = new IntersectionObserver((entries) => {
            entries.forEach((en) => en.isIntersecting ? moving.add(en.target) : moving.delete(en.target));
            place();
        }, { rootMargin: '10% 0px' });
        items.forEach((el) => io.observe(el));
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll, { passive: true });
    }

    /* ------------------------------ figures that count up ------------------------------ */
    const counters = [...root.querySelectorAll('[data-count]')];
    if (counters.length && 'IntersectionObserver' in window) {
        const run = (el) => {
            const end = parseInt(el.dataset.count, 10);
            if (!Number.isFinite(end) || end <= 0) return;
            const t0 = performance.now();
            const dur = 1100 + Math.min(end, 200) * 2;
            const frame = (t) => {
                const p = clamp((t - t0) / dur, 0, 1);
                const eased = 1 - Math.pow(1 - p, 3);
                el.textContent = String(Math.round(end * eased));
                if (p < 1) requestAnimationFrame(frame);
            };
            el.textContent = '0';
            requestAnimationFrame(frame);
        };
        const io = new IntersectionObserver((entries, obs) => {
            entries.forEach((en) => { if (en.isIntersecting) { run(en.target); obs.unobserve(en.target); } });
        }, { threshold: 0.6 });
        counters.forEach((el) => io.observe(el));
    }
})();
