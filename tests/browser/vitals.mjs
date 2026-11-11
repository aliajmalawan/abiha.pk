// Lab measurement of Core Web Vitals in a real (headless) Edge/Chrome over the DevTools protocol. No npm dependencies.
//
//   node tests/browser/vitals.mjs <base-url> [path ...] [--desktop] [--no-throttle] [--strict]
//   node tests/browser/vitals.mjs http://localhost/abiha.pk/public / /shop /products/some-slug
//
// Default profile = mobile: 390x844 @2x, 4x CPU slowdown, ~1.6 Mbps / 150 ms RTT (Lighthouse's "slow 4G").
// Reports FCP, LCP (+ which element), CLS during load and after scrolling the whole page, long-task time (a TBT proxy),
// requests and transferred bytes by type, and which image formats were delivered.
// This is LAB data from whatever server you point it at. It does not replace field data (CrUX / Search Console).
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const args = process.argv.slice(2);
const flags = new Set(args.filter((a) => a.startsWith('--')));
const positional = args.filter((a) => !a.startsWith('--'));
const base = (positional[0] ?? 'http://127.0.0.1:8100').replace(/\/$/, '');
const paths = positional.slice(1).length ? positional.slice(1) : ['/', '/shop'];
const desktop = flags.has('--desktop');
const throttle = !flags.has('--no-throttle');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = [process.env.BROWSER_PATH, 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium'].find((p) => p && fs.existsSync(p));
if (!browser) { console.error('No Edge/Chrome found. Set BROWSER_PATH.'); process.exit(2); }

const BUDGET = { lcp: 2500, cls: 0.1, tbt: 200 };

const observers = `
window.__v = { fcp: 0, lcp: 0, lcpEl: '', cls: 0, clsAtLoad: null, tbt: 0 };
new PerformanceObserver((l) => { for (const e of l.getEntries()) { window.__v.lcp = e.startTime; window.__v.lcpEl = e.element ? e.element.tagName.toLowerCase() + (e.url ? ' ' + e.url.split('/').pop().slice(0, 40) : '') : ''; } }).observe({ type: 'largest-contentful-paint', buffered: true });
new PerformanceObserver((l) => { for (const e of l.getEntries()) { if (!e.hadRecentInput) window.__v.cls += e.value; } }).observe({ type: 'layout-shift', buffered: true });
new PerformanceObserver((l) => { for (const e of l.getEntries()) { if (e.name === 'first-contentful-paint') window.__v.fcp = e.startTime; } }).observe({ type: 'paint', buffered: true });
new PerformanceObserver((l) => { for (const e of l.getEntries()) { window.__v.tbt += Math.max(0, e.duration - 50); } }).observe({ type: 'longtask', buffered: true });
`;

async function measure(port, url) {
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'abiha-cwv-'));
    const proc = spawn(browser, ['--headless=new', '--disable-gpu', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore' });
    try {
        let targets;
        for (let i = 0; i < 60; i++) { try { targets = await (await fetch(`http://127.0.0.1:${port}/json`)).json(); if (targets.length) break; } catch { /* starting */ } await sleep(250); }
        const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
        await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
        let id = 0; const pending = new Map(); const events = [];
        ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } else if (d.method) events.push(d); };
        const send = (method, params = {}) => new Promise((res) => { const n = ++id; pending.set(n, res); ws.send(JSON.stringify({ id: n, method, params })); });
        const evalJs = async (expression) => (await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })).result?.result?.value;

        await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
        await send('Network.setCacheDisabled', { cacheDisabled: true });                      // cold first visit
        await send('Page.addScriptToEvaluateOnNewDocument', { source: observers });
        await send('Emulation.setDeviceMetricsOverride', desktop ? { width: 1350, height: 900, deviceScaleFactor: 1, mobile: false } : { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
        if (throttle) {
            await send('Emulation.setCPUThrottlingRate', { rate: desktop ? 2 : 4 });
            await send('Network.emulateNetworkConditions', desktop
                ? { offline: false, latency: 40, downloadThroughput: (10 * 1024 * 1024) / 8, uploadThroughput: (5 * 1024 * 1024) / 8 }
                : { offline: false, latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 });
        }
        await send('Page.navigate', { url });

        for (const t0 = Date.now(); Date.now() - t0 < 90000;) {
            if ((await evalJs('document.readyState')) === 'complete') break;
            await sleep(300);
        }
        await sleep(2500);                                                                     // let late LCP candidates and shifts settle
        const atLoad = await evalJs('JSON.stringify(window.__v)');
        // Scroll the whole page in steps so lazy images load and any shifts they cause are counted.
        await evalJs(`(async () => { const h = document.documentElement.scrollHeight; for (let y = 0; y < h; y += 500) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 250)); } window.scrollTo(0, 0); })()`);
        await sleep(1500);
        const afterScroll = await evalJs('JSON.stringify(window.__v)');
        const v0 = JSON.parse(atLoad); const v1 = JSON.parse(afterScroll);

        // Transfer accounting from the protocol's own events
        const reqs = new Map();
        for (const e of events) {
            const p = e.params;
            if (e.method === 'Network.responseReceived') reqs.set(p.requestId, { type: p.type, mime: p.response.mimeType, url: p.response.url, status: p.response.status, bytes: 0 });
            if (e.method === 'Network.loadingFinished' && reqs.has(p.requestId)) reqs.get(p.requestId).bytes = p.encodedDataLength;
        }
        const list = [...reqs.values()];
        const sum = (f) => list.filter(f).reduce((n, r) => n + r.bytes, 0);
        const images = list.filter((r) => r.type === 'Image');
        const formats = images.reduce((o, r) => { const f = r.mime.replace('image/', ''); o[f] = (o[f] ?? 0) + 1; return o; }, {});
        const failed = list.filter((r) => r.status >= 400).map((r) => `${r.status} ${r.url.split('/').slice(3).join('/')}`);
        await send('Browser.close').catch(() => {});

        return { fcp: v0.fcp, lcp: v0.lcp, lcpEl: v0.lcpEl, clsLoad: v0.cls, clsAll: v1.cls, tbt: v1.tbt, requests: list.length,
            kb: Math.round(sum(() => true) / 1024), kbHtml: Math.round(sum((r) => r.type === 'Document') / 1024), kbJs: Math.round(sum((r) => r.type === 'Script') / 1024),
            kbCss: Math.round(sum((r) => r.type === 'Stylesheet') / 1024), kbImg: Math.round(sum((r) => r.type === 'Image') / 1024), kbFont: Math.round(sum((r) => r.type === 'Font') / 1024),
            formats, failed };
    } finally {
        proc.kill();
        await sleep(1200);
        try { fs.rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 400 }); } catch { /* temp dir */ }
    }
}

console.log(`Profile: ${desktop ? 'desktop' : 'mobile 390x844'}, ${throttle ? (desktop ? 'CPU 2x, 10 Mbps' : 'CPU 4x, 1.6 Mbps / 150 ms RTT') : 'no throttling'}, cold cache. Server: ${base}`);
let bad = 0, port = 9420;
for (const p of paths) {
    const r = await measure(port++, p.startsWith('http') ? p : base + p);
    const flag = (ok) => (ok ? 'ok ' : 'OVER');
    const lcpOk = r.lcp <= BUDGET.lcp, clsOk = r.clsAll <= BUDGET.cls, tbtOk = r.tbt <= BUDGET.tbt;
    if (!lcpOk || !clsOk || !tbtOk || r.failed.length) bad++;
    console.log(`\n${p}`);
    console.log(`  FCP ${Math.round(r.fcp)} ms | LCP ${Math.round(r.lcp)} ms [${flag(lcpOk)} budget ${BUDGET.lcp}] ${r.lcpEl ? '(' + r.lcpEl + ')' : ''}`);
    console.log(`  CLS ${r.clsLoad.toFixed(3)} at load, ${r.clsAll.toFixed(3)} after scrolling [${flag(clsOk)} budget ${BUDGET.cls}] | long tasks ${Math.round(r.tbt)} ms [${flag(tbtOk)} budget ${BUDGET.tbt}]`);
    console.log(`  ${r.requests} requests, ${r.kb} KB on the wire (html ${r.kbHtml}, js ${r.kbJs}, css ${r.kbCss}, fonts ${r.kbFont}, images ${r.kbImg}) | image formats ${JSON.stringify(r.formats)}`);
    if (r.failed.length) console.log(`  FAILED REQUESTS: ${r.failed.join(', ')}`);
}
console.log('\nLab data from this machine and server only. Field data (Search Console / CrUX) is the real measure once the site is live.');
process.exit(flags.has('--strict') && bad ? 1 : 0);
