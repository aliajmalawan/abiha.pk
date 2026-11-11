// End-to-end QA in a real (headless) Edge/Chrome over the DevTools protocol. No npm dependencies.
//
//   node tests/browser/qa.mjs <base-url> --config qa.json [--shots dir] [--only desktop,tablet,mobile]
//
// config: { customer: {email,password}, admin: {email,password}, product: "slug", category: "slug", page: "slug", coupon: "CODE" }
// For every viewport it (1) audits each page for horizontal overflow, broken images, console errors, failed requests, a single <h1>,
// tiny tap targets; and (2) drives the real UI with real mouse/keyboard events: navigation, search, filters, wishlist, cart, coupon,
// checkout (cash on delivery), order tracking, customer login/logout, and the admin area.
// Run it against a SCRATCH database: it places orders and edits data.
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const args = process.argv.slice(2);
const opt = (name, d) => { const i = args.indexOf(`--${name}`); return i >= 0 ? args[i + 1] : d; };
const base = (args.find((a) => /^https?:/.test(a)) ?? 'http://127.0.0.1:8102').replace(/\/$/, '');
const cfg = JSON.parse(fs.readFileSync(opt('config', 'qa.json'), 'utf8'));
const shots = opt('shots', null);
if (shots) fs.mkdirSync(shots, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// The sizes the brief lists: phones 360 / 390 / 430, tablets 768 / 1024, desktops 1280 / 1440 / 1920 (plus an 820px tablet).
const VIEWPORTS = {
    'small-phone': { w: 360, h: 640, mobile: true, dpr: 2 }, mobile: { w: 390, h: 844, mobile: true, dpr: 2 }, 'phone-430': { w: 430, h: 932, mobile: true, dpr: 2 },
    'tablet-768': { w: 768, h: 1024, mobile: true, dpr: 2 }, tablet: { w: 820, h: 1180, mobile: true, dpr: 2 }, 'laptop-1024': { w: 1024, h: 768, mobile: false, dpr: 1 },
    'desktop-1280': { w: 1280, h: 800, mobile: false, dpr: 1 }, desktop: { w: 1440, h: 900, mobile: false, dpr: 1 }, 'wide-1920': { w: 1920, h: 1080, mobile: false, dpr: 1 },
};
const only = (opt('only', null) ?? Object.keys(VIEWPORTS).join(',')).split(',');

const browser = [process.env.BROWSER_PATH, 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium'].find((p) => p && fs.existsSync(p));
if (!browser) { console.error('No Edge/Chrome found. Set BROWSER_PATH.'); process.exit(2); }

class Tab {
    constructor(ws) {
        this.ws = ws; this.id = 0; this.pending = new Map(); this.errors = []; this.failed = [];
        ws.onmessage = (m) => {
            const d = JSON.parse(m.data);
            if (d.id && this.pending.has(d.id)) { this.pending.get(d.id)(d); this.pending.delete(d.id); return; }
            const p = d.params ?? {};
            if (d.method === 'Runtime.exceptionThrown') this.errors.push(`exception: ${p.exceptionDetails?.exception?.description ?? p.exceptionDetails?.text}`.slice(0, 300));
            if (d.method === 'Runtime.consoleAPICalled' && p.type === 'error') this.errors.push(`console.error: ${p.args.map((a) => a.value ?? a.description).join(' ')}`.slice(0, 300));
            if (d.method === 'Log.entryAdded' && p.entry.level === 'error' && !/favicon/.test(p.entry.url ?? '')) this.errors.push(`${p.entry.source}: ${p.entry.text} ${p.entry.url ?? ''}`.slice(0, 300));
            if (d.method === 'Network.responseReceived' && p.response.status >= 400 && !/favicon\.ico/.test(p.response.url)) this.failed.push(`${p.response.status} ${p.response.url.replace(base, '')}`);
            if (d.method === 'Network.loadingFailed' && !p.canceled) this.failed.push(`FAILED ${p.errorText} ${p.requestId}`);
        };
    }
    send(method, params = {}) { return new Promise((res) => { const n = ++this.id; this.pending.set(n, res); this.ws.send(JSON.stringify({ id: n, method, params })); }); }
    async eval(expression) { const r = await this.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }); if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description ?? 'eval failed'); return r.result?.result?.value; }
    async nav(url) { this.errors.length = 0; this.failed.length = 0; await this.send('Page.navigate', { url: url.startsWith('http') ? url : base + url }); await this.loaded(); }
    async loaded() { for (const t0 = Date.now(); Date.now() - t0 < 30000;) { if ((await this.eval('document.readyState').catch(() => '')) === 'complete') break; await sleep(150); } await sleep(700); }
    async waitFor(expr, ms = 8000) { for (const t0 = Date.now(); Date.now() - t0 < ms;) { if (await this.eval(`!!(${expr})`).catch(() => false)) return true; await sleep(150); } return false; }
    async point(sel) {
        const r = await this.eval(`(() => { const el = document.querySelector(${JSON.stringify(sel)}); if (!el) return null; el.scrollIntoView({ block: 'center', inline: 'center', behavior: 'instant' }); /* the site scrolls smoothly: jump, then measure */ const b = el.getBoundingClientRect();
            const x = b.left + b.width / 2, y = b.top + b.height / 2; const top = document.elementFromPoint(x, y);
            return { x, y, w: b.width, h: b.height, visible: b.width > 0 && b.height > 0, covered: !(top === el || el.contains(top) || (top && top.contains(el))), by: top ? top.tagName + '.' + String(top.className).slice(0, 40) : 'nothing' }; })()`);
        if (!r) throw new Error(`not found: ${sel}`);
        return r;
    }
    async click(sel) {
        await sleep(120);
        const p = await this.point(sel);
        if (!p.visible) throw new Error(`not visible: ${sel}`);
        if (p.covered) throw new Error(`covered by ${p.by}: ${sel}`);
        for (const type of ['mouseMoved', 'mousePressed', 'mouseReleased']) await this.send('Input.dispatchMouseEvent', { type, x: p.x, y: p.y, button: 'left', clickCount: 1 });
        await sleep(250);
    }
    /** Click the first visible element matching `sel` whose text matches `re` (tagged so the normal real-mouse click is used). */
    async clickText(sel, re) {
        const ok = await this.eval(`(() => { document.querySelectorAll('[data-qa]').forEach(e => e.removeAttribute('data-qa')); const el = [...document.querySelectorAll(${JSON.stringify(sel)})].find(e => new RegExp(${JSON.stringify(re)}, 'i').test((e.innerText || e.getAttribute('aria-label') || '').trim()) && e.getBoundingClientRect().width > 0); if (!el) return false; el.setAttribute('data-qa', '1'); return true; })()`);
        if (!ok) throw new Error(`no visible ${sel} matching /${re}/; candidates: ` + JSON.stringify(await this.eval(`[...document.querySelectorAll(${JSON.stringify(sel)})].map(e => (e.innerText || '').trim().slice(0, 24) + ' w=' + Math.round(e.getBoundingClientRect().width))`)) + ' at ' + (await this.url()));
        await this.click('[data-qa="1"]');
    }
    async type(sel, text) { await this.click(sel); await this.eval(`document.querySelector(${JSON.stringify(sel)}).select?.()`); await this.send('Input.insertText', { text }); await sleep(80); }
    async key(key, code = key) { const vk = key === 'Enter' ? 13 : key === 'Escape' ? 27 : 0;
        await this.send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: vk, ...(key === 'Enter' ? { text: String.fromCharCode(13) } : {}) });
        await this.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: vk }); await sleep(250); }
    async select(sel, value) { await this.eval(`(() => { const s = document.querySelector(${JSON.stringify(sel)}); s.value = ${JSON.stringify(value)}; s.dispatchEvent(new Event('input', { bubbles: true })); s.dispatchEvent(new Event('change', { bubbles: true })); })()`); }
    async shot(name) { if (!shots) return; const r = await this.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(shots, `${name}.png`), Buffer.from(r.result.data, 'base64')); }
    url() { return this.eval('location.href'); }
}

async function openBrowser(port, vp) {
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'abiha-qa-'));
    const proc = spawn(browser, ['--headless=new', '--disable-gpu', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore' });
    let targets;
    for (let i = 0; i < 60; i++) { try { targets = await (await fetch(`http://127.0.0.1:${port}/json`)).json(); if (targets.length) break; } catch { /* starting */ } await sleep(250); }
    const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
    const tab = new Tab(ws);
    for (const d of ['Page', 'Runtime', 'Network', 'Log']) await tab.send(`${d}.enable`);
    await tab.send('Emulation.setFocusEmulationEnabled', { enabled: true });
    await tab.send('Emulation.setDeviceMetricsOverride', { width: vp.w, height: vp.h, deviceScaleFactor: vp.dpr, mobile: vp.mobile });
    if (vp.mobile) await tab.send('Emulation.setTouchEmulationEnabled', { enabled: true });
    return { tab, close: async () => { await tab.send('Browser.close').catch(() => {}); proc.kill(); await sleep(1200); try { fs.rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 400 }); } catch { /* temp */ } } };
}

const AUDIT = `(async () => {
  const H = document.documentElement; const out = {};
  const step = Math.max(300, innerHeight * 0.8); for (let y = 0; y < H.scrollHeight; y += step) { scrollTo({ top: y, behavior: 'instant' }); await new Promise(r => setTimeout(r, 120)); } scrollTo({ top: 0, behavior: 'instant' }); await new Promise(r => setTimeout(r, 500));
  out.overflow = H.scrollWidth - innerWidth;
  out.offenders = out.overflow > 1 ? [...document.querySelectorAll('body *')].filter(e => { const b = e.getBoundingClientRect(); return b.width > 0 && b.right > innerWidth + 1 && getComputedStyle(e).position !== 'fixed' && !e.closest('[x-cloak],[hidden]'); }).slice(0, 4).map(e => e.tagName.toLowerCase() + '.' + String(e.className).split(' ').slice(0, 3).join('.')) : [];
  out.brokenImages = [...document.images].filter(i => i.complete && i.naturalWidth === 0 && i.getBoundingClientRect().width > 0).map(i => i.currentSrc || i.src).slice(0, 3);
  out.h1 = document.querySelectorAll('h1').length;
  out.noAlt = [...document.images].filter(i => !i.hasAttribute('alt')).length;
  const vis = e => { const b = e.getBoundingClientRect(); const s = getComputedStyle(e); return b.width > 0 && b.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && !e.closest('[x-cloak],[hidden],.sr-only'); };
  out.smallTargets = [...document.querySelectorAll('a[href],button,input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select,textarea,[role=button]')].filter(e => { if (!vis(e)) return false; const b = e.getBoundingClientRect(); if (e.matches('a') && getComputedStyle(e).display === 'inline' && e.closest('p,li,span,nav ol,.prose-abiha,footer')) return false; return b.width < 24 || b.height < 24; }).slice(0, 5).map(e => (e.tagName + ' "' + (e.innerText || e.getAttribute('aria-label') || e.name || '').trim().slice(0, 24) + '" ' + Math.round(e.getBoundingClientRect().width) + 'x' + Math.round(e.getBoundingClientRect().height)));
  out.tinyText = [...document.querySelectorAll('p,li,a,span,label,td,th,button')].filter(e => vis(e) && e.children.length === 0 && e.innerText.trim().length > 2 && parseFloat(getComputedStyle(e).fontSize) < 11).length;
  out.viewportMeta = !!document.querySelector('meta[name=viewport]');
  out.title = document.title; out.empty = document.body.innerText.trim().length < 40;
  return out; })()`;

const results = [];
const warnings = [];
const rec = (vp, group, name, ok, detail = '') => { results.push({ vp, group, name, ok, detail }); if (!ok) console.log(`  FAIL [${vp}] ${group}: ${name} ${detail}`); };
const grep = opt('grep', null);
async function step(vp, group, name, fn) {
    if (grep && group !== 'page' && !name.includes(grep)) return; /* --grep "text": run only matching interaction steps */
    try { const r = await fn(); if (r === false) rec(vp, group, name, false); else rec(vp, group, name, true, typeof r === 'string' ? r : ''); } catch (e) { rec(vp, group, name, false, String(e.message).slice(0, 220)); } }

async function auditPage(tab, vp, pathname, label = pathname) {
    await step(vp, 'page', label, async () => {
        await tab.nav(pathname);
        const a = await tab.eval(AUDIT);
        const problems = [];
        if (a.overflow > 1) problems.push(`horizontal overflow ${a.overflow}px (${a.offenders.join(', ')})`);
        if (a.brokenImages.length) problems.push(`broken images: ${a.brokenImages.join(', ')}`);
        if (!a.viewportMeta) problems.push('no viewport meta');
        if (a.noAlt) problems.push(`${a.noAlt} images without alt`);
        if (a.empty) problems.push('page is blank');
        if (!/^\/(admin\/)?(orders\/\d+|account)/.test(pathname) && a.h1 !== 1 && !pathname.startsWith('/admin')) problems.push(`${a.h1} <h1>`);
        if (tab.errors.length) problems.push(`console: ${tab.errors.slice(0, 2).join(' | ')}`);
        if (tab.failed.length) problems.push(`requests: ${tab.failed.slice(0, 3).join(', ')}`);
        // Sizing is advisory (collected as warnings, summarised at the end); everything else above is a hard failure.
        if (a.smallTargets.length) warnings.push({ vp, page: label, text: `tap targets under 24px: ${a.smallTargets.join('; ')}` });
        if (a.tinyText) warnings.push({ vp, page: label, text: `${a.tinyText} text nodes under 11px` });
        await tab.shot(`${vp}-${label.replace(/[^\w]+/g, '_') || 'home'}`);
        if (problems.length) throw new Error(problems.join(' || '));
        return a.title;
    });
}

async function runViewport(name, vp, port) {
    console.log(`\n=== ${name} ${vp.w}x${vp.h} ===`);
    const { tab, close } = await openBrowser(port, vp);
    try {
        const mobile = vp.w < 1024;

        /* ---------- public pages ---------- */
        for (const p of ['/', '/shop', `/category/${cfg.category}`, `/products/${cfg.product}`, '/wishlist', '/login', '/register', '/contact', '/track-order', '/shipping-returns', `/pages/${cfg.page}`, '/forgot-password']) await auditPage(tab, name, p);
        await step(name, 'page', '404 page is branded', async () => { await tab.nav('/no/such/page'); return (await tab.eval(`document.body.innerText.includes('Page not found') && !!document.querySelector('header')`)) || false; });

        /* ---------- navigation ---------- */
        await step(name, 'nav', mobile ? 'menu button opens the drawer and a category link works' : 'primary nav links are visible and work', async () => {
            await tab.nav('/');
            if (mobile) {
                await tab.click('button[aria-label="Open menu"]');
                if (!(await tab.waitFor(`[role=dialog] a[href*="/category/"], [aria-label="Menu"] a[href*="/category/"]`, 3000)) && !(await tab.waitFor(`document.querySelectorAll('a[href*="/category/"]').length && [...document.querySelectorAll('a[href*="/category/"]')].some(a => a.getBoundingClientRect().width > 0)`, 3000))) throw new Error('drawer did not open');
                await tab.shot(`${name}-menu-open`);
                await tab.key('Escape');
                const stillOpen = await tab.eval(`[...document.querySelectorAll('a[href*="/category/"]')].some(a => { const r = a.getBoundingClientRect(); const s = getComputedStyle(a); return r.width > 0 && r.left >= 0 && r.right <= innerWidth && s.visibility !== 'hidden' && a.closest('[role=dialog]') })`);
                if (stillOpen) throw new Error('Escape did not close the drawer');
            } else {
                const links = await tab.eval(`[...document.querySelectorAll('nav[aria-label=Primary] a')].filter(a => a.getBoundingClientRect().width > 0).length`);
                if (links < 2) throw new Error(`only ${links} visible nav links`);
                // Category mega menu with REAL mouse input: hovering opens it, clicking the button keeps it open (it must not toggle shut), Escape closes it.
                const megaVisible = () => tab.eval(`(() => { const m = document.getElementById('mega-menu'); if (!m) return false; const b = m.getBoundingClientRect(); return b.width > 0 && b.height > 0 && getComputedStyle(m).display !== 'none' && getComputedStyle(m).opacity !== '0'; })()`);
                await tab.click('nav[aria-label=Primary] button');
                await sleep(400);
                if (!(await megaVisible())) throw new Error('the category mega menu did not open when hovering/clicking Shop');
                const cats = await tab.eval(`[...document.querySelectorAll('#mega-menu a[href*="/category/"]')].filter(a => a.getBoundingClientRect().width > 0).length`);
                if (cats < 2) throw new Error(`mega menu shows only ${cats} category links`);
                await tab.shot(`${name}-mega-menu`);
                await tab.key('Escape');
                await sleep(400);
                if (await megaVisible()) throw new Error('Escape did not close the mega menu');
                await tab.click(`nav[aria-label=Primary] a[href*="/shop"], nav[aria-label=Primary] a[href*="/category/"]`);
                if (!/\/(shop|category)/.test(await tab.url())) throw new Error('nav link did not navigate');
            }
        });

        /* ---------- search ---------- */
        await step(name, 'search', 'open, type, see suggestions, press Enter', async () => {
            await tab.nav('/');
            await tab.click('button[aria-label="Search"]');
            await tab.waitFor(`document.getElementById('site-search') && document.getElementById('site-search').getBoundingClientRect().width > 0`, 3000);
            await tab.type('#site-search', 'a');
            await tab.send('Input.insertText', { text: 'l' });
            if (!(await tab.waitFor(`document.querySelectorAll('[role=listbox] a, [x-ref=results] a, a[href*="/products/"]').length > 0 && [...document.querySelectorAll('a[href*="/products/"]')].some(a => a.getBoundingClientRect().width > 0 && a.closest('[x-show],[role=dialog],form,div'))`, 6000))) throw new Error('no suggestions appeared');
            await tab.shot(`${name}-search`);
            await tab.key('Enter');
            await tab.loaded();
            if (!(await tab.url()).includes('q=')) throw new Error('Enter did not run the search');
        });

        /* ---------- filters ---------- */
        await step(name, 'filters', 'price filter narrows the list and can be cleared', async () => {
            await tab.nav('/shop');
            const total = await tab.eval(`document.querySelectorAll('.product-card').length`);
            if (total < 4) throw new Error(`only ${total} products`);
            if (mobile) { await tab.click('button.lg\\:hidden[class*="btn--outline"]'); await tab.waitFor(`document.getElementById('max') && document.getElementById('max').getBoundingClientRect().width > 0`, 3000); await tab.shot(`${name}-filters-open`); }
            await tab.type('#max', '3000');
            await tab.clickText('form#filters button', '^Apply$');
            await tab.loaded();
            const after = await tab.eval(`document.querySelectorAll('.product-card').length`);
            const prices = await tab.eval(`[...document.querySelectorAll('.product-card .price')].map(p => parseInt((p.innerText.match(/[\d,]+/) ?? ['0'])[0].replace(/,/g, '')))`);
            if (!(await tab.url()).includes('max=3000')) throw new Error('filter not applied to URL');
            if (after >= total) throw new Error(`list did not shrink (${total} → ${after})`);
            if (prices.some((p) => p > 3000 && !Number.isNaN(p))) throw new Error('a product above the max is listed: ' + prices);
        });
        await step(name, 'filters', 'sort dropdown re-sorts', async () => {
            await tab.nav('/shop');
            await tab.select('#sort', 'price_asc');
            await tab.loaded();
            const prices = (await tab.eval(`[...document.querySelectorAll('.product-card .price')].map(p => parseInt((p.innerText.match(/[\d,]+/) ?? ['0'])[0].replace(/,/g, '')))`)).filter((n) => !Number.isNaN(n));
            const sorted = [...prices].sort((a, b) => a - b);
            if (!(await tab.url()).includes('sort=price_asc')) throw new Error('sort not in URL');
            if (JSON.stringify(prices) !== JSON.stringify(sorted)) throw new Error(`not ascending: ${prices}`);
        });

        /* ---------- shop page: every filter, pagination, labels, quick view, quick add (real catalogue) ---------- */
        const openFilters = async () => {
            if (mobile) { await tab.click('button.lg\\:hidden[class*="btn--outline"]'); await tab.waitFor(`document.getElementById('max') && document.getElementById('max').getBoundingClientRect().width > 0`, 3000); }
        };
        const tickAndApply = async (inputSel) => {
            await tab.nav('/shop');
            const total = await tab.eval(`document.querySelectorAll('.product-card').length`);
            await openFilters();
            const found = await tab.eval(`(() => { document.querySelectorAll('[data-qa]').forEach(e => e.removeAttribute('data-qa')); const i = document.querySelector(${JSON.stringify(inputSel)}); const l = i?.closest('label'); if (!l) return false; l.setAttribute('data-qa', '1'); return true; })()`);
            if (!found) throw new Error('filter control not found: ' + inputSel);
            await tab.click('[data-qa="1"]');
            await tab.clickText('form#filters button', '^Apply$');
            await tab.loaded();
            return { total, after: await tab.eval(`document.querySelectorAll('.product-card').length`), url: decodeURIComponent(await tab.url()) };
        };
        await step(name, 'shop', 'size filter narrows to products that really have that size', async () => {
            const r = await tickAndApply('input[name="size[]"]');
            if (!r.url.includes('size[]=')) throw new Error('size not in the URL: ' + r.url);
            if (r.after < 1 || r.after >= r.total) throw new Error(`size filter gave ${r.after} of ${r.total}`);
        });
        await step(name, 'shop', 'colour filter narrows to products that really have that colour', async () => {
            const r = await tickAndApply('input[name="color[]"]');
            if (!r.url.includes('color[]=')) throw new Error('colour not in the URL: ' + r.url);
            if (r.after < 1 || r.after >= r.total) throw new Error(`colour filter gave ${r.after} of ${r.total}`);
        });
        await step(name, 'shop', 'availability filter keeps only products that can be bought', async () => {
            const r = await tickAndApply('input[name="availability"]');
            if (!r.url.includes('availability=in_stock')) throw new Error('availability not in the URL');
            if (r.after < 1) throw new Error('nothing in stock?');
            if (await tab.eval(`[...document.querySelectorAll('.product-card .badge')].some(b => /sold out/i.test(b.innerText))`)) throw new Error('a sold-out product is listed under "in stock only"');
        });
        await step(name, 'shop', 'sale filter shows only products carrying a Sale label', async () => {
            const r = await tickAndApply('input[name="sale"]');
            if (!r.url.includes('sale=1')) throw new Error('sale not in the URL');
            if (r.after < 1) throw new Error('no sale products in the sample catalogue');
            const unlabeled = await tab.eval(`[...document.querySelectorAll('.product-card')].filter(c => !/sale/i.test(c.querySelector('.badge')?.innerText ?? '')).length`);
            if (unlabeled) throw new Error(unlabeled + ' product(s) in the sale filter have no Sale label');
        });
        await step(name, 'shop', 'sale and new-arrival labels appear on cards, and sale cards show the old price', async () => {
            await tab.nav('/shop?sort=newest');
            const labels = await tab.eval(`[...document.querySelectorAll('.product-card .badge')].map(b => b.innerText.trim())`);
            if (!labels.some((l) => /^sale/i.test(l))) throw new Error('no Sale label in: ' + labels.join(' | '));
            if (!labels.some((l) => /^new/i.test(l))) throw new Error('no New label in: ' + labels.join(' | '));
            if (!(await tab.eval(`!!document.querySelector('.product-card s')`))) throw new Error('a sale card does not show the struck-through old price');
        });
        await step(name, 'shop', 'sorting options use the expected names and Latest applies', async () => {
            await tab.nav('/shop');
            const opts = await tab.eval(`[...document.querySelectorAll('#sort option')].map(o => o.innerText.trim())`);
            for (const want of ['Latest', 'Price: low to high', 'Price: high to low', 'Popularity']) if (!opts.includes(want)) throw new Error('missing sort option "' + want + '" in ' + opts.join(', '));
            await tab.select('#sort', 'newest');
            await tab.loaded();
            if (!(await tab.url()).includes('sort=newest')) throw new Error('Latest did not apply');
        });
        await step(name, 'shop', 'pagination moves between pages with different products and keeps the sort', async () => {
            await tab.nav('/shop?sort=price_asc');
            const first = await tab.eval(`document.querySelector('.product-card .product-card__title a').innerText`);
            if (!(await tab.eval(`!!document.querySelector('nav[aria-label=Pagination] a[rel=next]')`))) throw new Error('no next-page link (is there more than one page of products?)');
            await tab.click('nav[aria-label=Pagination] a[rel=next]');
            await tab.loaded();
            const url = await tab.url();
            if (!/page=2/.test(url) || !url.includes('sort=price_asc')) throw new Error('page 2 URL lost the sort: ' + url);
            const second = await tab.eval(`document.querySelector('.product-card .product-card__title a').innerText`);
            if (second === first) throw new Error('page 2 shows the same first product');
            await tab.click('nav[aria-label=Pagination] a[rel=prev]');
            await tab.loaded();
            if ((await tab.eval(`document.querySelector('.product-card .product-card__title a').innerText`)) !== first) throw new Error('Prev did not return to page 1');
        });
        await step(name, 'shop', 'quick add from a card puts the product in the bag', async () => {
            await tab.nav('/shop');
            const before = await tab.eval(`Alpine.store('cart').count`);
            await tab.eval(`(() => { document.querySelectorAll('[data-qa]').forEach(e => e.removeAttribute('data-qa')); [...document.querySelectorAll('.product-card button')].find(b => /quick add/i.test(b.innerText))?.setAttribute('data-qa', '1'); })()`);
            if (!(await tab.eval(`!!document.querySelector('[data-qa="1"]')`))) throw new Error('no card offers Quick add');
            await tab.click('[data-qa="1"]');
            if (!(await tab.waitFor(`Alpine.store('cart').count > ${before}`, 6000))) throw new Error('bag count did not increase');
            await tab.key('Escape');
        });
        await step(name, 'shop', 'quick view opens with details and a price, and Escape closes it', async () => {
            await tab.nav('/shop');
            const nameOnCard = await tab.eval(`document.querySelector('.product-card:has(button[aria-label^="Quick view"]) .product-card__title a').innerText.trim()`);
            await tab.eval(`(() => { document.querySelectorAll('[data-qa]').forEach(e => e.removeAttribute('data-qa')); document.querySelector('.product-card button[aria-label^="Quick view"]')?.setAttribute('data-qa', '1'); })()`);
            await tab.click('[data-qa="1"]');
            const open = await tab.waitFor(`(() => { const d = document.querySelector('[aria-labelledby=quick-title]'); return d && d.getBoundingClientRect().width > 0 && d.innerText.length > 40; })()`, 8000);
            if (!open) throw new Error('quick view did not open');
            await sleep(500);
            const text = await tab.eval(`document.querySelector('[aria-labelledby=quick-title]').innerText`);
            if (!text.includes(nameOnCard)) throw new Error('quick view does not show the product ' + nameOnCard + ' but: ' + text.slice(0, 80));
            if (!/PKR\s*[\d,]+/.test(text)) throw new Error('quick view shows no price: ' + text.slice(0, 120));
            await tab.shot(`${name}-quick-view`);
            await tab.key('Escape');
            await sleep(500);
            if (await tab.eval(`(() => { const d = document.querySelector('[aria-labelledby=quick-title]'); return d && d.getBoundingClientRect().width > 0; })()`)) throw new Error('Escape did not close quick view');
        });

        /* ---------- wishlist, product page, cart ---------- */
        await step(name, 'wishlist', 'heart toggles on a card and the item shows on the wishlist page', async () => {
            await tab.nav('/shop');
            const href = await tab.eval(`document.querySelector('.product-card .product-card__title a').getAttribute('href')`);
            await tab.click('.product-card .product-card__wish');
            if (!(await tab.waitFor(`document.querySelector('.product-card .product-card__wish').getAttribute('aria-pressed') === 'true'`, 4000))) throw new Error('heart did not switch on');
            await tab.nav('/wishlist');
            if (!(await tab.eval(`!!document.querySelector('a[href="${href}"]')`))) throw new Error('item missing from the wishlist page');
        });
        await step(name, 'product', 'gallery switches image and add-to-bag updates the bag count and opens the drawer', async () => {
            await tab.nav(`/products/${cfg.product}`);
            if (await tab.eval(`document.querySelectorAll('[role=tab]').length > 1`)) {
                await tab.click('[role=tablist] [role=tab]:nth-child(2)');
                if (!(await tab.eval(`document.querySelector('[role=tab]:nth-child(2)').getAttribute('aria-selected') === 'true'`))) throw new Error('thumbnail did not select');
            }
            // choose the first available option of every variant group (colour/size), exactly as a shopper would
            const groups = await tab.eval(`document.querySelectorAll('[role=radiogroup]').length`);
            for (let g = 0; g < groups; g++) {
                await tab.eval(`(() => { document.querySelectorAll('[data-qa]').forEach(e => e.removeAttribute('data-qa')); const r = [...document.querySelectorAll('[role=radiogroup]')][${g}].querySelector('[role=radio]:not([disabled]):not(.line-through)'); r?.setAttribute('data-qa', '1'); })()`);
                await tab.click('[data-qa="1"]');
            }
            await sleep(300);
            await tab.shot(`${name}-product`);
            const before = await tab.eval(`Alpine.store('cart').count`);
            await tab.clickText('button.btn', '^\s*Add to bag');
            if (!(await tab.waitFor(`Alpine.store('cart').count > ${before}`, 6000))) throw new Error('bag count did not increase');
            if (!(await tab.waitFor(`[...document.querySelectorAll('[role=dialog]')].some(d => d.getBoundingClientRect().width > 0 && /bag/i.test(d.innerText))`, 4000))) throw new Error('bag drawer did not open');
            // the drawer must finish loading and list the item with working controls (not stay on its skeleton)
            if (!(await tab.waitFor(`(() => { const d = [...document.querySelectorAll('[role=dialog]')].find(d => d.getBoundingClientRect().width > 0 && /bag/i.test(d.innerText)); return d && ![...d.querySelectorAll('.skeleton')].some(k => k.getBoundingClientRect().height > 0) && /Subtotal/.test(d.innerText); })()`, 8000))) throw new Error('bag drawer never finished loading its items: ' + JSON.stringify(await tab.eval(`(() => { const d = [...document.querySelectorAll('[role=dialog]')].find(d => /bag/i.test(d.innerText)); return d ? { text: d.innerText.slice(0, 160), skeletons: d.querySelectorAll('.skeleton').length, links: d.querySelectorAll('a').length, html: d.innerHTML.length } : 'no dialog'; })()`)) + ' failed: ' + tab.failed.join(','));
            await tab.shot(`${name}-after-add`);
            const total = (await tab.eval(`[...document.querySelectorAll('[role=dialog]')].find(d => d.getBoundingClientRect().width > 0 && /bag/i.test(d.innerText)).innerText`)).includes('PKR');
            if (!total) throw new Error('no prices in the bag drawer');
            await tab.key('Escape');
            if (await tab.eval(`[...document.querySelectorAll('[role=dialog]')].some(d => d.getBoundingClientRect().width > 0 && /bag/i.test(d.innerText))`)) throw new Error('Escape did not close the bag drawer');
        });
        if (cfg.variantProduct) await step(name, 'product', 'a variant product needs its options chosen, then adds the right variant', async () => {
            await tab.nav(`/products/${cfg.variantProduct}`);
            const before = await tab.eval(`Alpine.store('cart').count`);
            await tab.clickText('button.btn', '^\s*(Add to bag|Select)');
            if (await tab.eval(`Alpine.store('cart').count`) !== before) throw new Error('added to the bag without choosing options');
            const groups = await tab.eval(`document.querySelectorAll('[role=radiogroup]').length`);
            if (groups < 1) throw new Error('no option selectors on a variant product');
            for (let g = 0; g < groups; g++) {
                await tab.eval(`(() => { document.querySelectorAll('[data-qa]').forEach(e => e.removeAttribute('data-qa')); const r = [...document.querySelectorAll('[role=radiogroup]')][${g}].querySelector('[role=radio]:not([disabled]):not(.line-through)'); r?.setAttribute('data-qa', '1'); })()`);
                await tab.click('[data-qa="1"]');
            }
            await tab.shot(`${name}-variant-chosen`);
            await tab.clickText('button.btn', '^\s*Add to bag');
            if (!(await tab.waitFor(`Alpine.store('cart').count > ${before}`, 6000))) throw new Error('bag count did not increase after choosing options');
        });
        /* ---------- product page: variants drive SKU / price / stock; size guide, specifications, delivery and returns, zoom ---------- */
        const variantData = () => tab.eval(`(() => { const d = Alpine.$data(document.querySelector('[x-data^="productForm"]')); return JSON.parse(JSON.stringify({ variants: d.variants, sizes: d.sizes, colors: d.colors })); })()`);
        const shownState = () => tab.eval(`(() => { const add = [...document.querySelectorAll('button.btn')].find(b => /add to bag|sold out/i.test(b.innerText)); return { sku: document.querySelector('[x-text="sku"]')?.innerText.trim(), price: document.querySelector('.price [x-text="money(price)"]')?.innerText.trim(), stock: document.querySelector('[x-text="stockLabel"]')?.innerText.trim(), addDisabled: !!add?.disabled, addText: add?.innerText.trim() }; })()`);
        const tagAndClick = async (js) => {
            await tab.eval(`(() => { document.querySelectorAll('[data-qa]').forEach(e => e.removeAttribute('data-qa')); const el = (${js})(); el?.setAttribute('data-qa', '1'); })()`);
            if (!(await tab.eval(`!!document.querySelector('[data-qa="1"]')`))) throw new Error('control not found');
            await tab.click('[data-qa="1"]');
        };
        const swatch = (color) => tagAndClick(`() => [...document.querySelectorAll('[role=radio].swatch')].find(b => b.getAttribute('aria-label').startsWith(${JSON.stringify(color)}))`);
        const chip = (size) => tagAndClick(`() => [...document.querySelectorAll('[role=radio].chip')].find(b => b.innerText.trim() === ${JSON.stringify(size)})`);
        const money = (n) => 'PKR ' + Number(n).toLocaleString('en-US');
        const stockWord = (n) => (n <= 0 ? 'Out of stock' : n <= 5 ? `Only ${n} left` : 'In stock');

        if (cfg.variantProduct) {
            await step(name, 'product', 'choosing a size and colour shows that variant\'s own SKU, price and stock', async () => {
                await tab.nav(`/products/${cfg.variantProduct}`);
                const { variants } = await variantData();
                const chosen = variants.filter((v) => v.stock > 0).slice(0, 3);
                if (chosen.length < 2) throw new Error('need at least two in-stock variants in the data');
                const seen = new Set();
                for (const v of chosen) {
                    await tab.nav(`/products/${cfg.variantProduct}`);
                    if (v.color) await swatch(v.color);
                    if (v.size) await chip(v.size);
                    const s = await shownState();
                    if (s.sku !== v.sku) throw new Error(`picked ${v.size ?? ''} ${v.color ?? ''}: SKU shows ${s.sku}, expected ${v.sku}`);
                    if (!s.price.includes(money(v.price).replace('PKR ', ''))) throw new Error(`picked ${v.size ?? ''} ${v.color ?? ''}: price shows ${s.price}, expected ${money(v.price)}`);
                    if (s.stock !== stockWord(v.stock)) throw new Error(`stock label "${s.stock}", expected "${stockWord(v.stock)}"`);
                    if (s.addDisabled) throw new Error('Add to bag is disabled for an in-stock variant');
                    seen.add(s.sku);
                }
                if (seen.size < 2) throw new Error('different variants showed the same SKU');
            });
            await step(name, 'product', 'a sold-out variant cannot be chosen, and the server also refuses it', async () => {
                await tab.nav(`/products/${cfg.variantProduct}`);
                const { variants } = await variantData();
                const gone = variants.find((v) => v.stock <= 0);
                if (!gone) return 'skipped: no sold-out variant in the data';
                if (gone.color) await swatch(gone.color);
                const disabled = await tab.eval(`(() => { const c = [...document.querySelectorAll('[role=radio].chip')].find(b => b.innerText.trim() === ${JSON.stringify(gone.size)}); return c ? c.disabled : null; })()`);
                if (gone.size && disabled !== true) throw new Error(`the sold-out size ${gone.size} (in ${gone.color ?? 'this colour'}) can still be clicked`);
                const before = await tab.eval(`Alpine.store('cart').count`);
                const status = await tab.eval(`fetch(${JSON.stringify(base + '/cart/items')}, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ product_id: Alpine.$data(document.querySelector('[x-data^="productForm"]')).productId, variant_id: ${gone.id}, quantity: 1 }) }).then(r => r.status)`);
                if (status !== 422) throw new Error(`the server answered ${status} to a sold-out variant, expected 422`);
                if ((await tab.eval(`Alpine.store('cart').count`)) !== before) throw new Error('the bag count changed');
            });
        }
        await step(name, 'product', 'size guide opens in an accessible pop-up and Escape closes it', async () => {
            await tab.nav(`/products/${cfg.variantProduct ?? cfg.product}`);
            const has = await tab.eval(`[...document.querySelectorAll('button')].some(b => /^size guide$/i.test(b.innerText.trim()) && b.getBoundingClientRect().width > 0)`);
            if (!has) return 'skipped: no size guide is configured';
            await tab.clickText('button', '^Size guide$');
            if (!(await tab.waitFor(`(() => { const d = document.querySelector('[aria-labelledby=size-guide-title]'); return d && d.getBoundingClientRect().width > 0 && d.innerText.length > 20; })()`, 4000))) throw new Error('the size guide did not open');
            await tab.shot(`${name}-size-guide`);
            await tab.key('Escape');
            await sleep(500);
            if (await tab.eval(`(() => { const d = document.querySelector('[aria-labelledby=size-guide-title]'); return d && d.getBoundingClientRect().width > 0; })()`)) throw new Error('Escape did not close the size guide');
            if (!(await tab.eval(`/size guide/i.test(document.activeElement?.innerText ?? '')`))) throw new Error('focus did not return to the Size guide link');
        });
        await step(name, 'product', 'specifications, delivery and return information open as accordion panels', async () => {
            await tab.nav(`/products/${cfg.product}`);
            const heads = await tab.eval(`[...document.querySelectorAll('h2 > button')].map(b => b.innerText.trim())`);
            if (!heads.some((h) => /^Description/.test(h))) throw new Error('no Description panel: ' + heads.join(' | '));
            for (const label of ['Specifications', 'Delivery information', 'Return policy']) {
                if (!heads.some((h) => h.startsWith(label))) continue;
                await tab.clickText('h2 > button', '^' + label);
                await sleep(300);
                const ok = await tab.eval(`(() => { const b = [...document.querySelectorAll('h2 > button')].find(x => x.innerText.trim().startsWith(${JSON.stringify(label)})); const panel = b.closest('div').querySelector('div[x-show]'); return b.getAttribute('aria-expanded') === 'true' && panel.getBoundingClientRect().height > 0 && panel.innerText.trim().length > 3; })()`);
                if (!ok) throw new Error(`the ${label} panel did not open with content`);
            }
            if (!heads.some((h) => h.startsWith('Specifications'))) return 'skipped specifications: the product has none';
        });
        await step(name, 'product', 'hovering the main photo zooms it (desktop only)', async () => {
            if (mobile) return 'skipped: no hover on touch screens';
            await tab.nav(`/products/${cfg.product}`);
            if (!(await tab.eval(`!!document.querySelector('[x-data^=gallery] img')`))) return 'skipped: the product has no photo';
            await tab.eval(`(() => { document.querySelectorAll('[data-qa]').forEach(e => e.removeAttribute('data-qa')); document.querySelector('[x-data^=gallery] .relative.overflow-hidden')?.setAttribute('data-qa', '1'); })()`);
            const p = await tab.point('[data-qa="1"]');
            await tab.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: p.x, y: p.y });
            await tab.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: p.x + 20, y: p.y + 10 });
            await sleep(500);
            if (!(await tab.eval(`[...document.querySelectorAll('[x-data^=gallery] img')].some(i => i.style.transform.includes('scale(2)'))`))) throw new Error('the photo did not zoom on hover');
            await tab.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 5, y: 5 });
            await sleep(400);
            if ([...(await tab.eval(`[...document.querySelectorAll('[x-data^=gallery] img')].map(i => i.style.transform)`))].some((t) => t.includes('scale(2)'))) throw new Error('the zoom did not reset when the mouse left');
        });
        await step(name, 'product', 'on phones a sticky add-to-bag bar shows once the main button is out of view, and it adds to the bag (never on desktop)', async () => {
            await tab.nav(`/products/${cfg.product}`);
            const state = () => tab.eval(`(() => { const b = document.querySelector('[role=region][aria-label="Add to bag"]'); if (!b) return { exists: false }; const r = b.getBoundingClientRect(); return { exists: true, shown: r.height > 0 && getComputedStyle(b).display !== 'none' && getComputedStyle(b).visibility !== 'hidden', bottom: Math.round(r.bottom), vh: innerHeight, text: b.innerText.replace(/\\s+/g, ' ').trim() }; })()`);
            if (!mobile) {
                await tab.eval(`window.scrollTo({ top: document.body.scrollHeight, behavior: 'instant' })`);
                await sleep(500);
                if ((await state()).shown) throw new Error('the sticky bar showed on a desktop screen');
                return 'desktop: hidden as intended';
            }
            await tab.eval(`document.querySelector('[data-main-add]').scrollIntoView({ block: 'center', behavior: 'instant' })`);
            await sleep(600);
            if ((await state()).shown) throw new Error('the sticky bar showed while the main add button was on screen');
            await tab.eval(`window.scrollTo({ top: document.body.scrollHeight, behavior: 'instant' })`);
            await sleep(700);
            const s = await state();
            if (!s.exists || !s.shown) throw new Error('the sticky bar did not appear after scrolling past the main button');
            if (Math.abs(s.bottom - s.vh) > 2) throw new Error(`the bar is not stuck to the bottom of the screen (${s.bottom} vs ${s.vh})`);
            if (!/PKR\s*[\d,]+/.test(s.text)) throw new Error('the bar shows no price: ' + s.text);
            const before = await tab.eval(`Alpine.store('cart').count`);
            await tab.click('[role=region][aria-label="Add to bag"] button');
            if (!(await tab.waitFor(`Alpine.store('cart').count > ${before}`, 5000))) throw new Error('tapping the bar did not add the product to the bag');
            // Leave the bag as it was: take the test item out again so later cart checks start from the same bag.
            await tab.eval(`(async () => { const pid = Alpine.$data(document.querySelector('[x-data^="productForm"]')).productId; const h = { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'X-Requested-With': 'XMLHttpRequest' }; await fetch(${JSON.stringify(base + '/cart/items/')} + pid + '-0', { method: 'DELETE', headers: h }); })()`);
            await sleep(500);
            await tab.eval(`Alpine.store('drawer').close?.()`);
        });

        await step(name, 'cart', 'bag page: quantity, coupon and totals', async () => {
            await tab.nav('/cart');
            const before = await tab.eval(`document.body.innerText.match(/Subtotal[^0-9]*([\\d,]+)/)?.[1]`);
            if (!before) throw new Error('no subtotal shown');
            await tab.eval(`(() => { const f = document.querySelector('input[name=code]'); return !!f })()`);
            if (cfg.coupon && (await tab.eval(`!!document.querySelector('input[name=code]')`))) {
                await tab.type('input[name=code]', cfg.coupon);
                await tab.click('form[action*="coupon"] button.btn');
                await tab.loaded();
                const txt = await tab.eval('document.body.innerText');
                if (!new RegExp(cfg.coupon, 'i').test(txt)) throw new Error('coupon not shown as applied: ' + (await tab.eval(`[...document.querySelectorAll('.error-text,[role=alert]')].map(e => e.innerText).join(' | ')`)));
            }
            await tab.shot(`${name}-cart`);
        });

        /* ---------- shopping bag: server-side totals, quantities, stock limits, variants, coupon, removal, drawer ---------- */
        const num = (t) => parseInt(String(t ?? '').replace(/[^\d]/g, '') || '0', 10);
        const bagTotals = () => tab.eval(`(() => {
            const row = (label) => [...document.querySelectorAll('aside[aria-label="Order summary"] dl > div')].find(d => d.querySelector('dt')?.innerText.trim().startsWith(label));
            const read = (r) => { if (!r) return null; const t = r.querySelector('dd').innerText.trim(); return /^free$/i.test(t) ? 0 : parseInt(t.replace(/[^\\d]/g, '') || '0', 10) * (t.includes('−') || t.includes('-') ? -1 : 1); };
            const lines = [...document.querySelectorAll('ul.divide-y > li')].map(li => ({ unit: parseInt((li.querySelector('.price')?.innerText.match(/[\\d,]+/) ?? ['0'])[0].replace(/,/g, ''), 10), qty: parseInt(li.querySelector('output')?.innerText ?? '0', 10), total: parseInt((li.querySelector('p.price.col-start-2')?.innerText ?? '0').replace(/[^\\d]/g, '') || '0', 10), name: li.querySelector('a.font-display')?.innerText.trim(), href: li.querySelector('a.font-display')?.getAttribute('href'), key: li.getAttribute('x-data') }));
            return { subtotal: read(row('Subtotal')), discount: read(row('Discount')), shipping: read(row('Shipping')), total: read(row('Total')), lines };
        })()`);
        await step(name, 'cart', 'bag totals add up: lines → subtotal, minus discount, plus shipping = total', async () => {
            await tab.nav('/cart');
            const t = await bagTotals();
            if (!t.lines.length) throw new Error('the bag is empty');
            const sum = t.lines.reduce((n, l) => n + l.unit * l.qty, 0);
            if (t.subtotal !== sum) throw new Error(`subtotal shows ${t.subtotal} but the lines add up to ${sum}`);
            for (const l of t.lines) if (l.total !== l.unit * l.qty) throw new Error(`line "${l.name}" shows ${l.total}, expected ${l.unit} × ${l.qty}`);
            const expected = t.subtotal - Math.abs(t.discount ?? 0) + (t.shipping ?? 0);
            if (t.total !== expected) throw new Error(`total ${t.total} ≠ subtotal ${t.subtotal} − discount ${t.discount ?? 0} + shipping ${t.shipping}`);
            await tab.shot(`${name}-bag`);
        });
        await step(name, 'cart', 'plus and minus change the quantity and the totals follow (calculated by the server)', async () => {
            await tab.nav('/cart');
            const before = await bagTotals();
            const first = before.lines[0];
            await tab.eval(`(() => { document.querySelectorAll('[data-qa]').forEach(e => e.removeAttribute('data-qa')); document.querySelector('ul.divide-y > li button[aria-label="Increase quantity"]:not([disabled])')?.setAttribute('data-qa', '1'); })()`);
            if (!(await tab.eval(`!!document.querySelector('[data-qa="1"]')`))) throw new Error('no enabled "+" button');
            // wait until the bag really shows the new quantity (a slow server must not make this check flaky)
            const until = async (fn, ms = 12000) => { const t0 = Date.now(); while (Date.now() - t0 < ms) { try { if (await fn()) return true; } catch { /* page is reloading */ } await sleep(250); } return false; };
            const afterReload = async () => { await until(async () => (await tab.eval('window.__qaMark')) !== 1, 5000); };   // the page reloads after each change; do not click again before it has
            await tab.eval('window.__qaMark = 1');
            await tab.click('[data-qa="1"]');
            await afterReload();
            await until(async () => (await bagTotals()).lines[0]?.qty === first.qty + 1);
            const up = await bagTotals();
            if (up.lines[0].qty !== first.qty + 1) throw new Error(`quantity went ${first.qty} → ${up.lines[0].qty}, expected ${first.qty + 1}`);
            if (up.subtotal !== before.subtotal + first.unit) throw new Error(`subtotal went ${before.subtotal} → ${up.subtotal}, expected +${first.unit}`);
            await tab.eval(`(() => { document.querySelectorAll('[data-qa]').forEach(e => e.removeAttribute('data-qa')); document.querySelector('ul.divide-y > li button[aria-label="Decrease quantity"]')?.setAttribute('data-qa', '1'); })()`);
            await tab.eval('window.__qaMark = 1');
            await tab.click('[data-qa="1"]');
            await afterReload();
            await until(async () => (await bagTotals()).lines[0]?.qty === first.qty);
            const down = await bagTotals();
            if (down.lines[0].qty !== first.qty || down.subtotal !== before.subtotal) throw new Error(`minus did not restore the quantity and subtotal: expected qty ${first.qty} / subtotal ${before.subtotal}, got qty ${down.lines[0].qty} / subtotal ${down.subtotal}; after plus it was qty ${up.lines[0].qty} / ${up.subtotal}; lines now: ${JSON.stringify(down.lines)}`);
        });
        await step(name, 'cart', 'stock validation: more than is in stock is refused, the per-line cap holds, and "+" stops at the cap', async () => {
            await tab.nav('/cart');
            const key = await tab.eval(`document.querySelector('ul.divide-y > li').getAttribute('x-data').match(/'([^']+)'/)[1]`);
            const patch = (qty) => tab.eval(`fetch(${JSON.stringify(key)}, { method: 'PATCH', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ quantity: ${qty} }) }).then(async r => ({ status: r.status, body: await r.json().catch(() => ({})) }))`);
            const before = (await bagTotals()).lines[0].qty;
            for (const ask of [999, 21]) {
                const refused = await patch(ask);
                if (refused.status !== 422 || !/available|greater than|at most|must not/i.test(refused.body.message ?? '')) throw new Error(`asking for ${ask} should be refused with a clear message, got ${refused.status} ${JSON.stringify(refused.body)}`);
            }
            await tab.nav('/cart');
            if ((await bagTotals()).lines[0].qty !== before) throw new Error('a refused quantity still changed the bag');
            const atCap = await patch(20);                                      // the per-line cap, within the stock of 60
            if (atCap.status >= 400) throw new Error('quantity 20 should be allowed: ' + JSON.stringify(atCap.body));
            await tab.nav('/cart');
            if ((await bagTotals()).lines[0].qty !== 20) throw new Error('quantity 20 was not stored');
            if (!(await tab.eval(`document.querySelector('ul.divide-y > li button[aria-label="Increase quantity"]')?.disabled === true`))) throw new Error('"+" is still enabled at the cap of 20');
            await patch(1);
        });
        await step(name, 'cart', 'a variant line shows its size/colour and its own SKU', async () => {
            await tab.nav('/cart');
            const rows = await tab.eval(`[...document.querySelectorAll('ul.divide-y > li')].map(li => ({ name: li.querySelector('a.font-display')?.innerText.trim(), options: li.querySelector('p.mt-1.text-sm.text-grey-text')?.innerText.trim() ?? '', sku: li.innerText.match(/SKU:\\s*(\\S+)/)?.[1] ?? '' }))`);
            const withOptions = rows.filter((r) => r.options);
            if (!withOptions.length) throw new Error('no bag line shows size/colour options: ' + JSON.stringify(rows));
            if (!withOptions.every((r) => r.sku)) throw new Error('a variant line has no SKU');
        });
        await step(name, 'cart', 'coupon: applying and removing it changes the discount and total correctly', async () => {
            await tab.nav('/cart');
            if (await tab.eval(`!!document.querySelector('form[action*="coupon"] input[name=_method][value=DELETE]')`)) {
                await tab.clickText('form[action*="coupon"] button', '^Remove$');
                await tab.loaded();
            }
            const base = await bagTotals();
            if (base.discount) throw new Error('a discount shows with no coupon applied');
            await tab.type('input[name=code]', cfg.coupon);
            await tab.click('form[action*="coupon"] button.btn');
            await tab.loaded();
            const withCoupon = await bagTotals();
            if (!(withCoupon.discount && Math.abs(withCoupon.discount) > 0)) throw new Error('the coupon gave no discount');
            if (Math.abs(withCoupon.discount) > withCoupon.subtotal) throw new Error('the discount is bigger than the subtotal');
            if (withCoupon.total !== withCoupon.subtotal - Math.abs(withCoupon.discount) + (withCoupon.shipping ?? 0)) throw new Error('total after the coupon does not add up');
            await tab.clickText('form[action*="coupon"] button', '^Remove$');
            await tab.loaded();
            const removed = await bagTotals();
            if (removed.discount || removed.total !== base.total) throw new Error(`removing the coupon left discount ${removed.discount} and total ${removed.total} (was ${base.total})`);
            await tab.type('input[name=code]', cfg.coupon);                      // leave it applied for checkout
            await tab.click('form[action*="coupon"] button.btn');
            await tab.loaded();
        });
        await step(name, 'cart', 'a wrong coupon code is refused with a clear message and no discount', async () => {
            await tab.nav('/cart');
            if (await tab.eval(`!!document.querySelector('form[action*="coupon"] input[name=_method][value=DELETE]')`)) { await tab.clickText('form[action*="coupon"] button', '^Remove$'); await tab.loaded(); }
            await tab.type('input[name=code]', 'NOT-A-REAL-CODE');
            await tab.click('form[action*="coupon"] button.btn');
            await tab.loaded();
            const msg = await tab.eval(`[...document.querySelectorAll('.error-text,[role=alert]')].map(e => e.innerText.trim()).filter(Boolean).join(' | ')`);
            if (!/not valid|invalid/i.test(msg)) throw new Error('no clear message for a wrong code: "' + msg + '"');
            if ((await bagTotals()).discount) throw new Error('a wrong code still gave a discount');
            await tab.type('input[name=code]', cfg.coupon);
            await tab.click('form[action*="coupon"] button.btn');
            await tab.loaded();
        });
        await step(name, 'cart', 'slide-out drawer: lists the bag, quantity buttons work, the subtotal matches the bag page', async () => {
            await tab.nav('/cart');
            const page = await bagTotals();
            await tab.nav('/shop');
            await tab.click('header a[aria-label="Shopping bag"]');
            if (!(await tab.waitFor(`(() => { const d = [...document.querySelectorAll('[role=dialog]')].find(d => d.getBoundingClientRect().width > 0 && /bag/i.test(d.innerText)); return d && /Subtotal/.test(d.innerText) && ![...d.querySelectorAll('.skeleton')].some(k => k.getBoundingClientRect().height > 0); })()`, 8000))) throw new Error('the drawer did not open with the bag');
            const read = () => tab.eval(`(() => { const d = [...document.querySelectorAll('[role=dialog]')].find(d => d.getBoundingClientRect().width > 0 && /bag/i.test(d.innerText)); return { lines: d.querySelectorAll('ul li').length, subtotal: parseInt((d.innerText.match(/Subtotal\\s*PKR\\s*([\\d,]+)/) ?? [0, '0'])[1].replace(/,/g, ''), 10), hasCheckout: [...d.querySelectorAll('a')].some(a => /checkout/i.test(a.innerText)), hasViewBag: [...d.querySelectorAll('a')].some(a => /view full bag/i.test(a.innerText)) }; })()`);
            const a = await read();
            if (a.lines !== page.lines.length) throw new Error(`drawer lists ${a.lines} lines, the bag page ${page.lines.length}`);
            if (a.subtotal !== page.subtotal) throw new Error(`drawer subtotal ${a.subtotal} ≠ bag subtotal ${page.subtotal}`);
            if (!a.hasCheckout || !a.hasViewBag) throw new Error('the drawer lacks its Checkout / View full bag links');
            await tab.shot(`${name}-drawer`);
            await tab.eval(`(() => { document.querySelectorAll('[data-qa]').forEach(e => e.removeAttribute('data-qa')); const d = [...document.querySelectorAll('[role=dialog]')].find(d => d.getBoundingClientRect().width > 0 && /bag/i.test(d.innerText)); d.querySelector('button[aria-label="Increase quantity"]:not([disabled])')?.setAttribute('data-qa', '1'); })()`);
            await tab.click('[data-qa="1"]');
            if (!(await tab.waitFor(`(() => { const d = [...document.querySelectorAll('[role=dialog]')].find(d => d.getBoundingClientRect().width > 0 && /bag/i.test(d.innerText)); return d && parseInt((d.innerText.match(/Subtotal\\s*PKR\\s*([\\d,]+)/) ?? [0, '0'])[1].replace(/,/g, ''), 10) > ${a.subtotal}; })()`, 6000))) throw new Error('"+" in the drawer did not raise the subtotal');
            await tab.eval(`(() => { document.querySelectorAll('[data-qa]').forEach(e => e.removeAttribute('data-qa')); const d = [...document.querySelectorAll('[role=dialog]')].find(d => d.getBoundingClientRect().width > 0 && /bag/i.test(d.innerText)); d.querySelector('button[aria-label="Decrease quantity"]')?.setAttribute('data-qa', '1'); })()`);
            await tab.click('[data-qa="1"]');
            if (!(await tab.waitFor(`(() => { const d = [...document.querySelectorAll('[role=dialog]')].find(d => d.getBoundingClientRect().width > 0 && /bag/i.test(d.innerText)); return d && parseInt((d.innerText.match(/Subtotal\\s*PKR\\s*([\\d,]+)/) ?? [0, '0'])[1].replace(/,/g, ''), 10) === ${a.subtotal}; })()`, 6000))) throw new Error('"−" in the drawer did not restore the subtotal');
            await tab.key('Escape');
        });
        await step(name, 'cart', 'removing an item takes it out of the bag and the totals update', async () => {
            await tab.nav(`/products/${cfg.product}`);
            const pid = await tab.eval(`Alpine.$data(document.querySelector('[x-data^="productForm"]')).productId`);
            await tab.eval(`fetch(${JSON.stringify(base + '/cart/items')}, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ product_id: ${pid}, quantity: 1 }) }).then(r => r.status)`);
            await tab.nav('/cart');
            const before = await bagTotals();
            const target = before.lines.find((l) => l.href && l.href.includes(`/products/${cfg.product}`));
            if (!target) throw new Error('the product was not added to remove');
            await tab.eval(`(() => { document.querySelectorAll('[data-qa]').forEach(e => e.removeAttribute('data-qa')); [...document.querySelectorAll('ul.divide-y > li')].find(li => li.querySelector('a.font-display')?.getAttribute('href')?.includes(${JSON.stringify('/products/' + cfg.product)}))?.querySelector('form[action*="/cart/items/"] button')?.setAttribute('data-qa', '1'); })()`);
            await tab.click('[data-qa="1"]');
            await tab.loaded();
            const after = await bagTotals();
            if (after.lines.some((l) => l.href && l.href.includes(`/products/${cfg.product}`))) throw new Error('the item is still in the bag');
            if (after.lines.length !== before.lines.length - 1) throw new Error(`lines went ${before.lines.length} → ${after.lines.length}`);
            if (after.subtotal !== before.subtotal - target.total) throw new Error(`subtotal went ${before.subtotal} → ${after.subtotal}, expected −${target.total}`);
        });

        /* ---------- checkout ---------- */
        await step(name, 'checkout', 'the delivery estimate and charge follow the province chosen (province rules)', async () => {
            await tab.nav('/checkout');
            const read = () => tab.eval(`(() => { const l = document.querySelector('[role=radiogroup] label'); return { text: l.innerText.trim(), price: l.lastElementChild.innerText.trim() }; })()`);
            await tab.select('select[name=province]', 'Punjab');
            const punjab = await read();
            await tab.select('select[name=province]', 'Sindh');
            await sleep(300);
            const sindh = await read();
            if (!sindh.text.includes('QA 9-9 days')) throw new Error(`the Sindh estimate did not appear: "${sindh.text}"`);
            if (punjab.text.includes('QA 9-9 days')) throw new Error('the Sindh estimate showed for Punjab too');
            if (sindh.price === punjab.price) throw new Error(`the charge did not change with the province (${punjab.price} vs ${sindh.price})`);
            await tab.select('select[name=province]', 'Punjab');
            await sleep(300);
            if ((await read()).text.includes('QA 9-9 days')) throw new Error('the estimate did not go back when the province changed back');
        });
        await step(name, 'checkout', 'a foreign or malformed phone number is refused in plain words and nothing typed is lost', async () => {
            await tab.nav('/checkout');
            const bad = ['+1 415 555 0123', '12345'];
            for (const phone of bad) {
                await tab.nav('/checkout');
                await tab.type('input[name=name]', 'Phone Tester');
                await tab.type('input[name=phone]', phone);
                await tab.type('input[name=email]', 'phone-tester@example.test');
                await tab.select('select[name=province]', 'Punjab');
                await tab.type('input[name=city]', 'Lahore');
                await tab.type('textarea[name=address]', 'House 12, Street 3, Model Town');
                await tab.clickText('button.btn--block', 'Place order');
                await tab.loaded();
                if (!/\/checkout/.test(await tab.url()) || /thank-you/.test(await tab.url())) throw new Error(`a bad phone (${phone}) got past checkout`);
                const text = await tab.eval(`document.body.innerText`);
                if (!/valid Pakistani phone number/i.test(text)) throw new Error(`no clear phone message for ${phone}`);
                if ((await tab.eval(`document.querySelector('input[name=name]').value`)) !== 'Phone Tester') throw new Error('the name typed was lost after the phone error');
            }
        });
        await step(name, 'checkout', 'a coupon can be removed and applied on the checkout page without losing what was typed', async () => {
            await tab.nav('/checkout');
            await tab.type('input[name=name]', 'Keep Me');
            const hasCoupon = await tab.eval(`[...document.querySelectorAll('aside button')].some(b => /^remove$/i.test(b.innerText.trim()))`);
            if (hasCoupon) {
                await tab.clickText('aside button', '^Remove$');
                await tab.loaded();
            }
            if ((await tab.eval(`document.querySelector('input[name=name]')?.value`)) !== 'Keep Me') throw new Error('typed name lost after removing the coupon');
            if (!(await tab.eval(`!!document.querySelector('aside input[name=code]')`))) throw new Error('no coupon box after removing the coupon');
            const before = await tab.eval(`document.querySelector('aside dl').innerText`);
            await tab.type('aside input[name=code]', 'NOT-A-CODE');
            await tab.key('Enter');                                      // Enter in the coupon box must apply it, not place the order
            await tab.loaded();
            if (/thank-you/.test(await tab.url())) throw new Error('pressing Enter in the coupon box placed an order');
            if (!(await tab.eval(`document.querySelector('aside [role=alert]')?.innerText.length > 3`))) throw new Error('a wrong code showed no error');
            if ((await tab.eval(`document.querySelector('input[name=name]').value`)) !== 'Keep Me') throw new Error('typed name lost after a wrong coupon');
            await tab.type('aside input[name=code]', cfg.coupon);
            await tab.clickText('aside button', '^Apply$');
            await tab.loaded();
            if (!(await tab.eval(`document.querySelector('aside').innerText.includes(${JSON.stringify(cfg.coupon)})`))) throw new Error('the coupon was not shown as applied');
            if ((await tab.eval(`document.querySelector('aside dl').innerText`)) === before) throw new Error('the totals did not change after applying the coupon');
            if (!/discount/i.test(await tab.eval(`document.querySelector('aside dl').innerText`))) throw new Error('no discount line in the summary');
        });
        await step(name, 'checkout', 'fill the form, place a cash-on-delivery order, see the confirmation', async () => {
            await tab.nav('/checkout');
            if (!(await tab.eval(`!!document.querySelector('#f-name, input[name=name]')`))) throw new Error('checkout form missing (is the bag empty?)');
            await tab.type('input[name=name]', 'QA Buyer');
            await tab.type('input[name=phone]', '03001234567');
            const email = `qa-${name}-${Date.now()}@example.test`;
            await tab.type('input[name=email]', email);
            await tab.select('select[name=province]', 'Punjab');
            await tab.type('input[name=city]', 'Lahore');
            await tab.type('textarea[name=address]', 'House 12, Street 3, Model Town');
            await tab.shot(`${name}-checkout`);
            await tab.clickText('button.btn--block', 'Place order');
            if (!(await tab.waitFor(`location.pathname.includes('thank-you')`, 12000))) throw new Error(`not on the confirmation page: ${await tab.url()} errors: ${await tab.eval(`[...document.querySelectorAll('[role=alert], .field-error, .error')].map(e => e.innerText).join(' | ')`)}`);
            const num = await tab.eval(`document.body.innerText.match(/ABH-[A-Z0-9-]+/)?.[0]`);
            if (!num) throw new Error('order number not shown');
            await tab.shot(`${name}-confirmation`);
            globalThis.lastOrder = { number: num, email };
            return num;
        });
        await step(name, 'checkout', 'order tracking finds the order with number + email', async () => {
            if (!globalThis.lastOrder?.number) throw new Error('no order from the previous step');
            await tab.nav('/track-order');
            await tab.type('input[name=number]', globalThis.lastOrder.number);
            await tab.type('input[name=email]', globalThis.lastOrder.email);
            await tab.clickText('main form button', 'track|find|look|search|view');
            await tab.loaded();
            if (!(await tab.eval(`document.body.innerText.includes('${globalThis.lastOrder.number}')`))) throw new Error('order not shown');
        });

        /* ---------- bag persistence: guests, signing in (merge), signing out, signing in again ---------- */
        const signIn = async () => {
            await tab.nav('/login');
            await tab.type('input[name=email]', cfg.customer.email);
            await tab.type('input[name=password]', cfg.customer.password);
            await tab.click('form[action$="/login"] button.btn');
            await tab.loaded();
            if (!/\/account/.test(await tab.url())) throw new Error('could not sign in: ' + (await tab.url()));
        };
        const signOut = async () => { await tab.nav('/account'); await tab.eval(`document.querySelector('form[action$="/logout"]').submit()`); await tab.loaded(); };
        const bagNames = async () => { await tab.nav('/cart'); return tab.eval(`[...document.querySelectorAll('ul.divide-y > li a.font-display')].map(a => a.innerText.trim())`); };
        await step(name, 'cart', 'a guest bag survives a reload, merges on sign-in, and a signed-in customer keeps it across sign-out and back', async () => {
            await tab.nav(`/products/${cfg.product}`);
            const pid = await tab.eval(`Alpine.$data(document.querySelector('[x-data^="productForm"]')).productId`);
            const productName = await tab.eval(`document.querySelector('h1').innerText.trim()`);
            await tab.eval(`fetch(${JSON.stringify(base + '/cart/items')}, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ product_id: ${pid}, quantity: 2 }) }).then(r => r.status)`);
            if (!(await bagNames()).includes(productName)) throw new Error('the guest bag lost the item');
            await tab.nav('/');
            await tab.eval(`location.reload()`);
            await tab.loaded();
            if ((await tab.eval(`Alpine.store('cart').count`)) < 2) throw new Error('the bag count was lost on reload');
            await signIn();
            if (!(await bagNames()).includes(productName)) throw new Error('the guest bag was not merged into the account on sign-in');
            await signOut();
            if ((await bagNames()).includes(productName)) throw new Error('the signed-out visitor can still see the account bag');
            await signIn();
            if (!(await bagNames()).includes(productName)) throw new Error('the signed-in customer lost the bag after signing out and back in');
            // tidy up: empty the account bag, then sign out so the next steps start as a guest
            await tab.eval(`[...document.querySelectorAll('ul.divide-y > li form[action*="/cart/items/"]')].forEach(f => f.requestSubmit())`);
            await sleep(800);
            await tab.loaded();
            await signOut();
        });

        /* ---------- customer account ---------- */
        await step(name, 'account', 'customer signs in, sees the account, signs out', async () => {
            await tab.nav('/login');
            await tab.type('input[name=email]', cfg.customer.email);
            await tab.type('input[name=password]', cfg.customer.password);
            await tab.click('form[action$="/login"] button.btn');
            await tab.loaded();
            if (!/\/account/.test(await tab.url())) throw new Error(`login did not land on the account: ${await tab.url()}`);
        });
        for (const p of ['/account', '/account/orders', '/account/profile', '/account/addresses']) await auditPage(tab, name, p);
        await step(name, 'account', 'sign out returns to a guest', async () => {
            await tab.nav('/account');
            const sel = (await tab.eval(`!!document.querySelector('form[action$="/logout"] button')`)) ? 'form[action$="/logout"] button' : null;
            if (!sel) { await tab.eval(`document.querySelector('form[action$="/logout"]').submit()`); } else { await tab.eval(`document.querySelector('${sel}').click()`); }
            await tab.loaded();
            await tab.nav('/account');
            if (!(await tab.url()).includes('/login')) throw new Error('still signed in after logout');
        });

        /* ---------- admin ---------- */
        await step(name, 'admin', 'staff sign-in works', async () => {
            await tab.nav('/admin/login');
            await tab.type('input[name=email]', cfg.admin.email);
            await tab.type('input[name=password]', cfg.admin.password);
            await tab.click('main form button.btn, form button.btn');
            await tab.loaded();
            if (!/\/admin(\/|$)/.test(await tab.url()) || (await tab.url()).includes('login')) throw new Error(`admin login failed: ${await tab.url()}`);
        });
        await auditPage(tab, name, '/admin', '/admin dashboard');
        const adminLinks = await tab.eval(`[...new Set([...document.querySelectorAll('a[href]')].map(a => a.pathname).filter(p => p.startsWith('/admin') && !p.includes('logout') && !p.includes('export') && !/\\/\\d+$/.test(p)))]`);
        for (const p of adminLinks.filter((p) => p !== '/admin')) await auditPage(tab, name, p);
        const firstOrder = await (async () => { await tab.nav('/admin/orders'); return tab.eval(`[...document.querySelectorAll('a[href*="/admin/orders/"]')].map(a => a.pathname).find(p => /ABH-/.test(p))`); })();
        if (firstOrder) await auditPage(tab, name, firstOrder);
        const firstProduct = await (async () => { await tab.nav('/admin/products'); return tab.eval(`[...document.querySelectorAll('a[href*="/admin/products/"]')].map(a => a.pathname).find(p => /\\/edit$/.test(p))`); })();
        if (firstProduct) {
            await auditPage(tab, name, firstProduct, 'product edit form');
            await step(name, 'admin', 'saving the product form works', async () => {
                await tab.nav(firstProduct);
                await tab.clickText('main form button, form button', '^\s*(save|update)');
                await tab.loaded();
                const txt = await tab.eval(`document.body.innerText`);
                if (/error|invalid/i.test(await tab.eval(`[...document.querySelectorAll('[role=alert],.alert--error')].map(e => e.innerText).join(' ')`))) throw new Error('save reported an error');
                return /saved|updated/i.test(txt) ? 'saved' : 'submitted';
            });
            await step(name, 'admin', 'product photos can be reordered with the arrows and the order is saved', async () => {
                const ids = () => tab.eval(`[...document.querySelectorAll('input[name="image_order[]"]')].map((i) => i.value)`);
                // the product that was listed first may have no photos: look for one that has at least two
                await tab.nav('/admin/products');
                const editLinks = await tab.eval(`[...new Set([...document.querySelectorAll('a[href*="/admin/products/"]')].map((a) => a.pathname).filter((p) => p.endsWith('/edit')))].slice(0, 25)`);
                let target = null;
                for (const p of editLinks) { await tab.nav(p); if ((await ids()).length >= 2) { target = p; break; } }
                if (!target) { if (cfg.requirePhotos) throw new Error('no product has two photos to reorder'); return 'skipped: no product has two photos'; }
                await tab.nav(target);
                const save = async () => { await tab.clickText('main form button, form button', '^(save|update)'); await tab.loaded(); };
                const before = await ids();
                if (before.length < 2) return 'skipped: this product has fewer than two photos';
                await tab.click('button[aria-label="Move photo 1 later"]');
                const moved = await ids();
                if (moved[0] !== before[1] || moved[1] !== before[0]) throw new Error(`the arrow did not swap the photos: ${before} -> ${moved}`);
                if (!(await tab.eval(`document.querySelector('ol[x-ref=list] li:first-of-type')?.innerText.toLowerCase().includes('main')`))) throw new Error('the first photo is not marked as the main one');
                await save();
                await tab.nav(target);
                const saved = await ids();
                if (saved[0] !== before[1]) throw new Error(`the new order was not kept: ${before} -> ${saved}`);
                await tab.click('button[aria-label="Move photo 1 later"]');      // put it back so later runs start the same
                await save();
            });
        }
        if (firstOrder) await step(name, 'admin', 'staff can move an order to the next status from the order page', async () => {
            await tab.nav(firstOrder);
            const options = await tab.eval(`[...document.querySelectorAll('select#status option')].map(o => o.value)`);
            if (!options.length) return 'order is already final: nothing to move';
            const target = options.includes('confirmed') ? 'confirmed' : options[0];
            await tab.select('select#status', target);
            await tab.clickText('button.btn', '^\s*Update status');
            await tab.loaded();
            const txt = await tab.eval('document.body.innerText');
            if (!new RegExp(target, 'i').test(txt)) throw new Error(`page does not show the new status "${target}"`);
            return target;
        });
        await step(name, 'admin', 'staff can add a homepage block and remove it again (with the confirmation dialog)', async () => {
            await tab.nav('/admin/home');
            const before = await tab.eval(`document.querySelectorAll('table tbody tr').length`);
            await tab.select('#type', 'products');
            await tab.type('#f-title', 'QA row');
            await tab.clickText('button.btn', 'Add block');
            await tab.loaded();
            if (!(await tab.url()).includes('/edit')) throw new Error('adding a block did not open its edit page: ' + (await tab.url()));
            await tab.nav('/admin/home');
            if ((await tab.eval(`document.querySelectorAll('table tbody tr').length`)) !== before + 1) throw new Error('the new block is not in the list');
            await tab.eval(`(() => { document.querySelectorAll('[data-qa]').forEach(e => e.removeAttribute('data-qa')); [...document.querySelectorAll('table tbody tr')].find(r => r.innerText.includes('QA row'))?.querySelector('button[data-confirm]')?.setAttribute('data-qa', '1'); })()`);
            await tab.click('[data-qa="1"]');
            await tab.clickText('button', 'Yes, continue');
            await tab.loaded();
            if ((await tab.eval(`document.querySelectorAll('table tbody tr').length`)) !== before) throw new Error('the block was not removed');
        });
        await step(name, 'admin', 'saving the site settings works', async () => {
            await tab.nav('/admin/settings');
            await tab.clickText('button.btn', '^\s*Save settings');
            await tab.loaded();
            const errors = await tab.eval(`[...document.querySelectorAll('[role=alert],.alert--error,.error-text')].map(e => e.innerText.trim()).filter(Boolean).join(' | ')`);
            if (errors) throw new Error('settings save reported: ' + errors);
            if (!(await tab.url()).includes('/admin/settings')) throw new Error('left the settings page: ' + await tab.url());
        });
    } finally {
        await close();
    }
}

let port = 9440;
for (const name of only) await runViewport(name, VIEWPORTS[name], port++);

const failed = results.filter((r) => !r.ok);
console.log(`\n${'='.repeat(60)}\n${results.length - failed.length}/${results.length} checks passed across ${only.join(', ')}`);
for (const f of failed) console.log(`  FAIL [${f.vp}] ${f.group}: ${f.name}\n        ${f.detail}`);
const wsum = new Map();
for (const w of warnings) { const k = `[${w.vp}] ${w.text}`; wsum.set(k, (wsum.get(k) ?? new Set()).add(w.page)); }
if (wsum.size) { console.log(`
WARNINGS (sizing, advisory): ${wsum.size} distinct`); for (const [k, pages] of [...wsum].slice(0, 60)) console.log(`  ${k}  on ${[...pages].slice(0, 3).join(', ')}${pages.size > 3 ? ` +${pages.size - 3}` : ''}`); }
fs.writeFileSync(path.join(os.tmpdir(), 'abiha-qa-results.json'), JSON.stringify(results, null, 2));
process.exit(failed.length ? 1 : 0);
