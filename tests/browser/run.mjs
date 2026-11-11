// Real-browser UI checks: dialogs, focus trap, Escape, scroll lock, cart drawer, reveal, validation, loading state,
// and the same again with prefers-reduced-motion emulated. No npm dependencies (Node 22+ and Edge/Chrome).
//
//   php artisan serve --port=8100        # in one terminal (needs the sample data seeded)
//   node tests/browser/run.mjs [http://127.0.0.1:8100]
//
// The harness page is copied into public/ for the run and removed afterwards.
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..', '..');
const base = (process.argv[2] ?? 'http://127.0.0.1:8100').replace(/\/$/, '');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = [
    process.env.BROWSER_PATH,
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
].find((p) => p && fs.existsSync(p));
if (!browser) { console.error('No Edge/Chrome found. Set BROWSER_PATH.'); process.exit(2); }

const page = path.join(root, 'public', '_ui-harness.html');
fs.copyFileSync(path.join(here, 'ui-harness.html'), page);
process.on('exit', () => fs.rmSync(page, { force: true }));

async function runMode(port, reduced) {
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'abiha-ui-'));
    const proc = spawn(browser, ['--headless=new', '--disable-gpu', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore' });
    try {
        let targets;
        for (let i = 0; i < 60; i++) { try { targets = await (await fetch(`http://127.0.0.1:${port}/json`)).json(); if (targets.length) break; } catch { /* starting */ } await sleep(250); }
        const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
        await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
        let id = 0; const pending = new Map();
        ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
        const send = (method, params = {}) => new Promise((res) => { const n = ++id; pending.set(n, res); ws.send(JSON.stringify({ id: n, method, params })); });

        await send('Page.enable'); await send('Runtime.enable');
        await send('Emulation.setFocusEmulationEnabled', { enabled: true });       // behave like a focused window
        if (reduced) await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
        await send('Page.navigate', { url: `${base}/_ui-harness.html?reduced=${reduced ? 1 : 0}` });

        let title = '';
        for (const t0 = Date.now(); Date.now() - t0 < 150000;) {
            title = (await send('Runtime.evaluate', { expression: 'document.title', returnByValue: true })).result?.result?.value ?? '';
            if (title.startsWith('DONE')) break;
            await sleep(500);
        }
        await send('Browser.close').catch(() => {});
        return title.startsWith('DONE') ? JSON.parse(title.slice(5)) : [['FAIL', 'harness did not finish', title]];
    } finally {
        proc.kill();
        await sleep(1200);                                                          // Windows keeps the profile locked briefly
        try { fs.rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 400 }); } catch { /* temp dir: the OS cleans it up */ }
    }
}

let failed = 0;
for (const [port, reduced] of [[9411, false], [9412, true]]) {
    const results = await runMode(port, reduced);
    const bad = results.filter(([s]) => s !== 'PASS');
    failed += bad.length;
    console.log(`\n${reduced ? 'REDUCED MOTION' : 'NORMAL MOTION'}: ${results.length - bad.length}/${results.length} passed`);
    bad.forEach(([, name, extra]) => console.log(`  FAIL  ${name}  ${String(extra ?? '').replace(/\s+/g, ' ')}`));
}
process.exit(failed ? 1 : 0);
