import Alpine from 'alpinejs';

window.Alpine = Alpine;

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/** JSON request helper. Resolves { ok, status, data } and never throws on HTTP errors. */
async function request(url, { method = 'POST', body } = {}) {
    try {
        const res = await fetch(url, {
            method,
            headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body ? JSON.stringify(body) : undefined,
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));
        return { ok: res.ok, status: res.status, data };
    } catch (e) {
        return { ok: false, status: 0, data: { message: 'Network error. Please try again.' } };
    }
}

const money = (n) => `${window.ABIHA?.currency ?? 'PKR'} ${Number(n).toLocaleString('en-US')}`;

/* ---------- global stores ---------- */
Alpine.store('ui', {
    toast: null,
    timer: null,
    ms: 4500,
    /** Short, polite confirmation. Errors stay longer. Hovering or focusing the toast keeps it on screen. */
    notify(message, type = 'success', link = null) {
        this.ms = type === 'error' ? 7000 : 4500;
        this.toast = null;                            // re-create the toast so its progress bar restarts
        Alpine.nextTick(() => { this.toast = { message, type, link }; this.start(); });
    },
    start() { clearTimeout(this.timer); this.timer = setTimeout(() => (this.toast = null), this.ms); },
    pause() { clearTimeout(this.timer); },
    dismiss() { clearTimeout(this.timer); this.toast = null; },
});

/** Cart drawer: the bag slides in from the right instead of navigating away. Content is server-rendered HTML. */
Alpine.store('drawer', {
    open: false,
    loading: false,
    html: '',
    async show() {
        this.open = true;
        await this.refresh();
    },
    async refresh() {
        this.loading = this.html === '';
        try {
            const res = await fetch(window.ABIHA?.cartDrawerUrl ?? '/cart/drawer', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            this.html = res.ok ? await res.text() : '<p class="p-6">Sorry, your bag could not be loaded. <a class="link" href="/cart">Open the full bag</a>.</p>';
        } catch (e) {
            this.html = '<p class="p-6">Network error. <a class="link" href="/cart">Open the full bag</a>.</p>';
        }
        this.loading = false;
    },
    close() { this.open = false; },
});

/** Accessible replacement for window.confirm(): <form @submit="$store.confirm.ask($event, {title, message, label})"> */
Alpine.store('confirm', {
    open: false,
    title: '',
    message: '',
    label: 'Confirm',
    form: null,
    ask(e, opts = {}) {
        e.preventDefault();
        this.form = e.target;
        this.title = opts.title ?? 'Are you sure?';
        this.message = opts.message ?? '';
        this.label = opts.label ?? 'Confirm';
        this.open = true;
    },
    accept() {
        const f = this.form;
        this.open = false;
        this.form = null;
        f?.submit();                                  // direct submit: skips the listener that asked
    },
    cancel() { this.open = false; this.form = null; },
});

Alpine.store('cart', { count: window.ABIHA?.cartCount ?? 0 });
Alpine.store('wish', {
    ids: window.ABIHA?.wishlistIds ?? [],
    has(id) { return this.ids.includes(id); },
});

Alpine.store('quick', {
    open: false,
    loading: false,
    html: '',
    async show(url) {
        this.open = true;
        this.loading = true;
        this.html = '';
        try {
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            this.html = res.ok ? await res.text() : '<p class="p-8">Sorry, this product could not be loaded.</p>';
        } catch (e) {
            this.html = '<p class="p-8">Network error. Please try again.</p>';
        }
        this.loading = false;
    },
    close() { this.open = false; this.html = ''; },
});

/* ---------- wishlist heart (used on cards, PDP, quick view) ---------- */
Alpine.data('wishButton', (id, url) => ({
    busy: false,
    get active() { return Alpine.store('wish').has(id); },
    async toggle() {
        if (this.busy) return;
        this.busy = true;
        const { ok, data } = await request(url);
        this.busy = false;
        if (!ok) return Alpine.store('ui').notify(data.message ?? 'Could not update your wishlist.', 'error');
        const ids = Alpine.store('wish').ids;
        Alpine.store('wish').ids = data.added ? [...ids, id] : ids.filter((x) => x !== id);
        Alpine.store('ui').notify(data.message);
    },
}));

/* ---------- product form: variant selection drives price, stock and SKU ---------- */
Alpine.data('productForm', (cfg) => ({
    productId: cfg.productId,
    hasVariants: cfg.payload.hasVariants,
    variants: cfg.payload.variants,
    base: cfg.payload.base,
    addUrl: cfg.addUrl,
    cartUrl: cfg.cartUrl,
    size: null,
    color: null,
    qty: 1,
    busy: false,
    justAdded: false,
    error: '',

    init() {
        // Single-option products: preselect so the customer is not forced to click.
        if (this.sizes.length === 1) this.size = this.sizes[0];
        if (this.colors.length === 1) this.color = this.colors[0].name;
    },

    get sizes() { return [...new Set(this.variants.map((v) => v.size).filter(Boolean))]; },
    get colors() {
        const seen = new Map();
        this.variants.forEach((v) => v.color && !seen.has(v.color) && seen.set(v.color, { name: v.color, hex: v.hex }));
        return [...seen.values()];
    },

    /** Variants consistent with the current picks. */
    match(size, color) {
        return this.variants.filter((v) => (size == null || v.size === size) && (color == null || v.color === color));
    },
    /** Can this option still be bought given the OTHER selection? (disables sold-out combos) */
    optionAvailable(type, value) {
        const list = type === 'size' ? this.match(value, this.color) : this.match(this.size, value);
        return list.some((v) => v.stock > 0);
    },

    get complete() {
        return !this.hasVariants || ((this.sizes.length === 0 || !!this.size) && (this.colors.length === 0 || !!this.color));
    },
    get variant() {
        if (!this.hasVariants || !this.complete) return null;
        return this.match(this.size, this.color)[0] ?? null;
    },
    get price() { return this.variant ? this.variant.price : (this.hasVariants ? Math.min(...this.variants.map((v) => v.price)) : this.base.price); },
    get compare() { return this.variant ? this.variant.compare : (this.hasVariants ? null : this.base.compare); },
    get fromLabel() { return this.hasVariants && !this.variant && new Set(this.variants.map((v) => v.price)).size > 1; },
    get stock() { return this.variant ? this.variant.stock : this.base.stock; },
    get sku() { return this.variant ? this.variant.sku : this.base.sku; },
    get discount() { return this.compare && this.compare > this.price ? Math.round((1 - this.price / this.compare) * 100) : 0; },
    get inStock() { return this.stock > 0; },
    get canAdd() { return this.complete && this.inStock && !this.busy; },
    get stockLabel() {
        if (!this.complete) return 'Select options';
        if (this.stock <= 0) return 'Out of stock';
        return this.stock <= 5 ? `Only ${this.stock} left` : 'In stock';
    },
    money,

    pick(type, value) {
        this[type] = this[type] === value ? null : value;
        // If the new pick makes the other selection impossible, clear it.
        if (this.size && this.color && this.match(this.size, this.color).length === 0) {
            type === 'size' ? (this.color = null) : (this.size = null);
        }
        this.qty = Math.min(this.qty, Math.max(1, this.stock || 1));
        this.error = '';
    },
    inc() { if (this.qty < Math.min(20, this.stock || 1)) this.qty++; },
    dec() { if (this.qty > 1) this.qty--; },

    async add(buyNow = false) {
        if (!this.complete) { this.error = 'Please choose a size and colour.'; return; }
        if (!this.canAdd) return;
        this.busy = true;
        this.error = '';
        const { ok, data } = await request(this.addUrl, { body: { product_id: this.productId, variant_id: this.variant?.id ?? null, quantity: this.qty, buy_now: buyNow } });
        this.busy = false;
        if (!ok) { this.error = data.message ?? (data.errors ? Object.values(data.errors)[0][0] : 'Could not add to bag.'); return; }
        Alpine.store('cart').count = data.count;
        if (data.redirect) { window.location.href = data.redirect; return; }
        this.justAdded = true;
        setTimeout(() => (this.justAdded = false), 1800);
        Alpine.store('quick').close();
        Alpine.store('drawer').show();               // the drawer is the confirmation
    },
}));

/* ---------- quick add from product cards (simple products only) ---------- */
Alpine.data('quickAdd', (productId, addUrl, cartUrl) => ({
    busy: false,
    done: false,
    async add() {
        if (this.busy) return;
        this.busy = true;
        const { ok, data } = await request(addUrl, { body: { product_id: productId, quantity: 1 } });
        this.busy = false;
        if (!ok) return Alpine.store('ui').notify(data.message ?? 'Could not add to bag.', 'error');
        Alpine.store('cart').count = data.count;
        this.done = true;
        setTimeout(() => (this.done = false), 1800);
        Alpine.store('drawer').show();
    },
}));

/* ---------- cart drawer lines: quantity / remove without leaving the page ---------- */
Alpine.data('drawerLine', (updateUrl, removeUrl) => ({
    busy: false,
    async set(qty) {
        if (this.busy) return;
        this.busy = true;
        const { ok, data } = await request(updateUrl, { method: 'PATCH', body: { quantity: qty } });
        if (!ok) { this.busy = false; return Alpine.store('ui').notify(data.message ?? 'Could not update quantity.', 'error'); }
        Alpine.store('cart').count = data.count;
        await Alpine.store('drawer').refresh();
    },
    async remove() {
        if (this.busy) return;
        this.busy = true;
        const { ok, data } = await request(removeUrl, { method: 'DELETE' });
        if (!ok) { this.busy = false; return Alpine.store('ui').notify('Could not remove that item.', 'error'); }
        Alpine.store('cart').count = data.count;
        await Alpine.store('drawer').refresh();
    },
}));

/* ---------- product image gallery with hover zoom ---------- */
Alpine.data('gallery', (images) => ({
    images,
    active: 0,
    zoom: false,
    ox: 50,
    oy: 50,
    move(e) {
        const r = e.currentTarget.getBoundingClientRect();
        this.ox = ((e.clientX - r.left) / r.width) * 100;
        this.oy = ((e.clientY - r.top) / r.height) * 100;
    },
    next() { this.active = (this.active + 1) % this.images.length; },
    prev() { this.active = (this.active - 1 + this.images.length) % this.images.length; },
}));

/* ---------- header search with live suggestions ---------- */
Alpine.data('searchBox', (suggestUrl) => ({
    open: false,
    q: '',
    results: [],
    loading: false,
    timer: null,
    toggle() { this.open = !this.open; if (this.open) this.$nextTick(() => this.$refs.input.focus()); },
    lookup() {
        clearTimeout(this.timer);
        if (this.q.trim().length < 2) { this.results = []; return; }
        this.timer = setTimeout(async () => {
            this.loading = true;
            try {
                const res = await fetch(`${suggestUrl}?q=${encodeURIComponent(this.q.trim())}`, { headers: { Accept: 'application/json' } });
                this.results = (await res.json()).results ?? [];
            } catch (e) { this.results = []; }
            this.loading = false;
        }, 220);
    },
}));

/* ---------- cart page: quantity updates & coupon ---------- */
Alpine.data('cartLine', (url) => ({
    busy: false,
    async set(qty) {
        if (this.busy) return;
        this.busy = true;
        const { ok, data } = await request(url, { method: 'PATCH', body: { quantity: qty } });
        if (ok) return window.location.reload();
        this.busy = false;
        Alpine.store('ui').notify(data.message ?? 'Could not update quantity.', 'error');
    },
}));

/* ---------- checkout: live shipping/total preview (server recomputes and enforces on submit) ---------- */
Alpine.data('checkout', (cfg) => ({
    subtotal: cfg.subtotal,
    discount: cfg.discount,
    methods: cfg.methods,
    shipping: cfg.shipping,
    payment: cfg.payment,
    province: cfg.province || '',
    city: cfg.city || '',
    norm(v) { return String(v ?? '').trim().toLowerCase(); },
    /** Same precedence as ShippingService::ruleFor: city rule, then province rule, then the method default. */
    rule(m) {
        const c = this.norm(this.city), p = this.norm(this.province), rules = m.rules || [];
        if (c) {
            const hit = rules.find((r) => r.city && this.norm(r.city) === c && (!r.province || !p || this.norm(r.province) === p));
            if (hit) return hit;
        }
        return p ? rules.find((r) => !r.city && r.province && this.norm(r.province) === p) : undefined;
    },
    /** PKR cost, or null when the method is not offered to this destination. */
    quote(m) {
        const after = this.subtotal - this.discount, r = this.rule(m);
        if (!r) return m.free_over !== null && after >= m.free_over ? 0 : m.cost;
        if (!r.is_available) return null;
        const free = r.free_over ?? m.free_over;
        return free !== null && after >= free ? 0 : r.cost;
    },
    quoteText(code) {
        const m = this.methods.find((x) => x.code === code);
        const q = m ? this.quote(m) : 0;
        return q === null ? 'Not available here' : (q === 0 ? 'Free' : money(q));
    },
    /** Delivery estimate for the chosen destination: the rule's own estimate, else the method's (same as ShippingService::etaFor). */
    etaText(code) {
        const m = this.methods.find((x) => x.code === code);
        if (!m) return '';
        const r = this.rule(m);
        return (r && r.eta) || m.eta || '';
    },
    get method() { return this.methods.find((m) => m.code === this.shipping); },
    get unavailable() { return !!this.method && this.quote(this.method) === null; },
    get shippingCost() { return this.method ? (this.quote(this.method) ?? 0) : 0; },
    get total() { return this.subtotal - this.discount + this.shippingCost; },
    money,
}));

/* ---------- FAQ page: search hides the questions that do not contain the typed words ---------- */
Alpine.data('faq', () => ({
    q: '',
    match(el) { const t = this.q.trim().toLowerCase(); return t === '' || t.split(/\s+/).every((w) => el.textContent.toLowerCase().includes(w)); },
    groupVisible(el) { return this.q.trim() === '' || [...el.querySelectorAll('[data-faq]')].some((d) => this.match(d)); },
    none() { return ![...document.querySelectorAll('[data-faq]')].some((d) => this.match(d)); },
}));
/* ---------- newsletter (progressive enhancement) ---------- */
/* ---------- admin product form: photo order (first = main photo) and a pre-upload check ---------- */
Alpine.data('imageOrder', (items) => ({
    items,
    move(i, by) {
        const j = i + by;
        if (j < 0 || j >= this.items.length) return;
        [this.items[i], this.items[j]] = [this.items[j], this.items[i]];
        this.$nextTick(() => this.$refs.list?.querySelectorAll('[data-focus-move]')[j]?.focus());
    },
    makeMain(i) { if (i > 0) this.items.unshift(this.items.splice(i, 1)[0]); },
}));
Alpine.data('uploadCheck', (maxMb = 4, maxFiles = 12) => ({
    files: [], tooMany: false, over: false,
    check(e) {
        const list = [...e.target.files];
        this.tooMany = list.length > maxFiles;
        this.files = list.map((f) => {
            const type = ['image/jpeg', 'image/png', 'image/webp'].includes(f.type);
            const size = f.size <= maxMb * 1024 * 1024;
            return { name: f.name, mb: (f.size / 1048576).toFixed(1), url: type ? URL.createObjectURL(f) : null, problem: !type ? 'Not a JPG, PNG or WebP image' : !size ? 'Larger than ' + maxMb + ' MB' : '' };
        });
    },
    get blocked() { return this.tooMany || this.files.some((f) => f.problem); },
}));

Alpine.data('newsletter', (url) => ({
    email: '',
    state: 'idle',
    message: '',
    async submit() {
        this.state = 'busy';
        const { ok, data } = await request(url, { body: { email: this.email } });
        this.state = ok ? 'done' : 'error';
        this.message = ok ? data.message : (data.errors?.email?.[0] ?? 'Please enter a valid email address.');
        if (ok) this.email = '';
    },
}));

/* ======================================================================
   Accessibility & polish
   ====================================================================== */
const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ---------- dialogs: x-trap="open" ----------
   While open: focus moves inside, Tab/Shift+Tab loop within the dialog, the page behind stops scrolling,
   and on close focus returns to whatever opened it. Escape is handled by each dialog's own @keydown.escape. */
let scrollLocks = 0;
const lockScroll = (on) => {
    scrollLocks = Math.max(0, scrollLocks + (on ? 1 : -1));
    document.documentElement.classList.toggle('scroll-locked', scrollLocks > 0);
};
const FOCUSABLE = 'a[href],button:not([disabled]),textarea:not([disabled]),input:not([type="hidden"]):not([disabled]),select:not([disabled]),[tabindex]:not([tabindex="-1"])';

Alpine.directive('trap', (el, { expression }, { effect, evaluateLater, cleanup }) => {
    const isOpen = evaluateLater(expression);
    let opener = null;
    let onKey = null;

    const focusables = () => [...el.querySelectorAll(FOCUSABLE)].filter((n) => n.getClientRects().length > 0 && getComputedStyle(n).visibility !== 'hidden');
    const release = () => {
        if (!onKey) return;
        el.removeEventListener('keydown', onKey);
        onKey = null;
        lockScroll(false);
        opener?.focus?.({ preventScroll: true });
        opener = null;
    };

    effect(() => isOpen((open) => {
        if (open && !onKey) {
            opener = document.activeElement;
            lockScroll(true);
            onKey = (e) => {
                if (e.key !== 'Tab') return;
                const items = focusables();
                if (!items.length) { e.preventDefault(); el.focus(); return; }
                const first = items[0], last = items[items.length - 1];
                if (e.shiftKey && (document.activeElement === first || document.activeElement === el)) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            };
            el.addEventListener('keydown', onKey);
            // wait a frame so x-show has made the dialog visible before focusing into it
            setTimeout(() => (el.querySelector('[data-autofocus]') ?? focusables()[0] ?? el).focus({ preventScroll: true }), 40);
        } else if (!open) {
            release();
        }
    }));
    cleanup(release);
});

/* ---------- scroll reveal: [data-reveal] fades up once; [data-reveal-group] staggers its children ---------- */
function initReveal() {
    const items = [...document.querySelectorAll('[data-reveal]')];
    document.querySelectorAll('[data-reveal-group]').forEach((g) =>
        g.querySelectorAll('[data-reveal]').forEach((c, i) => c.style.setProperty('--reveal-delay', `${Math.min(i, 5) * 70}ms`)));

    const showAll = () => items.forEach((n) => n.classList.add('is-visible'));
    if (reducedMotion() || !('IntersectionObserver' in window)) return showAll();

    const io = new IntersectionObserver((entries) => entries.forEach((e) => {
        if (e.isIntersecting) { e.target.classList.add('is-visible'); io.unobserve(e.target); }
    }), { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
    items.forEach((n) => io.observe(n));
    setTimeout(showAll, 3500);                       // failsafe: content is never left hidden
}

/* ---------- submit buttons show a spinner and cannot be double-clicked ---------- */
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || e.defaultPrevented || form.hasAttribute('data-no-loading')) return;
    const btn = e.submitter ?? form.querySelector('button:not([type="button"])');
    if (!btn || btn.hasAttribute('data-no-loading')) return;
    // after the browser has read the submitter's value
    setTimeout(() => {
        btn.classList.add('is-loading');
        btn.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button:not([type="button"])').forEach((b) => (b.disabled = true));
    }, 0);
});
// Back/forward cache: restore buttons that were mid-submit when the user left the page.
window.addEventListener('pageshow', (e) => {
    if (!e.persisted) return;
    document.querySelectorAll('.is-loading[aria-busy]').forEach((b) => {
        b.classList.remove('is-loading');
        b.removeAttribute('aria-busy');
        b.disabled = false;
    });
    document.querySelectorAll('form button:disabled').forEach((b) => (b.disabled = false));
});

/* ---------- forms with [data-validate]: plain-language messages on blur and on submit ---------- */
const MESSAGES = {
    valueMissing: (el) => (el.type === 'checkbox' ? 'Please tick this box to continue.' : el.tagName === 'SELECT' ? 'Please choose an option.' : 'Please fill in this field.'),
    typeMismatch: (el) => (el.type === 'email' ? 'Enter a valid email address, like name@example.com.' : el.type === 'url' ? 'Enter a full web address starting with https://' : 'Please check this value.'),
    tooShort: (el) => `Please use at least ${el.minLength} characters.`,
    patternMismatch: () => 'That does not look right. Please check the format.',
    rangeUnderflow: (el) => `This must be at least ${el.min}.`,
    rangeOverflow: (el) => `This must be at most ${el.max}.`,
};
const messageFor = (el) => {
    for (const key of Object.keys(MESSAGES)) if (el.validity[key]) return MESSAGES[key](el);
    return el.validationMessage || 'Please check this field.';
};
const errorId = (el) => `ve-${el.id || el.name}`;
function showError(el, message) {
    let box = document.getElementById(errorId(el));
    if (!box) {
        box = document.createElement('span');
        box.id = errorId(el);
        box.className = 'error-text';
        box.dataset.client = '1';
        (el.closest('.field') ?? el.parentElement).appendChild(box);
    }
    box.textContent = message;
    el.setAttribute('aria-invalid', 'true');
    const ids = new Set((el.getAttribute('aria-describedby') ?? '').split(' ').filter(Boolean));
    ids.add(box.id);
    el.setAttribute('aria-describedby', [...ids].join(' '));
}
function clearError(el) {
    const box = document.getElementById(errorId(el));
    if (box?.dataset.client) box.remove();
    if (el.checkValidity()) el.removeAttribute('aria-invalid');
}
const validated = (el) => el?.form?.hasAttribute('data-validate') && el.willValidate;

document.addEventListener('focusout', (e) => {
    if (!validated(e.target) || e.target.value === '' && !e.target.required) return;
    e.target.checkValidity() ? clearError(e.target) : showError(e.target, messageFor(e.target));
});
document.addEventListener('input', (e) => {
    if (validated(e.target) && e.target.getAttribute('aria-invalid') === 'true' && e.target.checkValidity()) clearError(e.target);
});
// capture phase: runs before any other submit handler so a failed form never shows a spinner
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-validate')) return;
    if (e.submitter?.hasAttribute('formnovalidate')) return;   // e.g. the coupon button on checkout: no need for the whole form to be complete
    let first = null;
    form.querySelectorAll('input,select,textarea').forEach((el) => {
        if (el.willValidate && !el.checkValidity()) { showError(el, messageFor(el)); first ??= el; }
    });
    if (first) { e.preventDefault(); e.stopImmediatePropagation(); first.focus(); }
}, true);

/* ---------- [data-confirm]: any form or submit button can ask first, with no inline JavaScript (CSP-friendly) ---------- */
const confirmOpts = (el) => ({ title: el.dataset.confirm, message: el.dataset.confirmText ?? '', label: el.dataset.confirmLabel ?? 'Yes, continue' });
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    Alpine.store('confirm').ask(e, confirmOpts(form));
}, true);
document.addEventListener('click', (e) => {
    const btn = e.target.closest?.('button[data-confirm]');
    if (!btn || !btn.form) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    Alpine.store('confirm').ask({ preventDefault() {}, target: btn.form }, confirmOpts(btn));
}, true);

document.addEventListener('DOMContentLoaded', () => {
    initReveal();
    // After a server-side validation failure, bring the error summary into view and announce it.
    const summary = document.querySelector('[data-error-summary]');
    // wait for load + a frame: the browser applies the form's own autofocus after DOMContentLoaded and would steal focus
    if (summary) window.addEventListener('load', () => requestAnimationFrame(() => summary.focus()));
});

// A long side menu (the admin sidebar) keeps its scroll position from page to page, so you never have to scroll it again.
// On the first visit it scrolls the current page's link into view instead.
document.querySelectorAll('[data-keep-scroll]').forEach((el) => {
    const key = 'scroll:' + el.dataset.keepScroll;
    const read = () => { try { return sessionStorage.getItem(key); } catch { return null; } };
    const write = () => { try { sessionStorage.setItem(key, String(el.scrollTop)); } catch { /* storage blocked: fine */ } };
    const apply = () => {
        const saved = read();
        if (saved !== null) { el.scrollTop = Number(saved) || 0; return; }
        el.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'center' });
    };
    apply();
    window.addEventListener('load', apply);   // the sidebar can be hidden (display:none) on a phone until opened, so try again once the page is ready
    el.addEventListener('scroll', write, { passive: true });
    document.addEventListener('click', (e) => { if (el.contains(e.target)) write(); }, true);
    window.addEventListener('pagehide', write);
});

Alpine.start();
