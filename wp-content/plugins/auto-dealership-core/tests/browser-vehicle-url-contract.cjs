'use strict';

// Public vehicle URL contract: generated ASCII links, legacy compatibility, and canonical redirects.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');

(async () => {
    const origin = (process.env.ADC_SITE_ORIGIN || 'http://localhost/wordpress').replace(/\/$/, '');
    const browserPath = process.env.ADC_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    const profileRoot = path.resolve(__dirname, '../../../../.tmp/browser-profiles');
    fs.mkdirSync(profileRoot, { recursive: true });
    const profile = fs.mkdtempSync(path.join(profileRoot, 'adc-vehicle-url-'));
    const browser = spawn(browserPath, [
        '--headless=new', '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=0',
        '--no-first-run', '--disable-background-networking', '--disable-sync',
        '--user-data-dir=' + profile, 'about:blank'
    ], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
    let browserLog = '';
    browser.stderr.on('data', chunk => { browserLog = (browserLog + chunk.toString()).slice(-2000); });
    const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
    let socket;
    let sequence = 0;
    const pending = new Map();
    const errors = [];
    const badAssets = [];
    let passed = 0;
    const check = (value, description) => { assert.ok(value, description); passed++; process.stdout.write('PASS ' + description + '\n'); };

    try {
        const activePort = path.join(profile, 'DevToolsActivePort');
        for (let i = 0; i < 200 && !fs.existsSync(activePort); i++) await pause(100);
        assert.ok(fs.existsSync(activePort), 'Browser must start: ' + browserLog);
        const port = Number(fs.readFileSync(activePort, 'utf8').split('\n')[0]);
        const targets = await (await fetch('http://127.0.0.1:' + port + '/json/list')).json();
        const target = targets.find(item => item.type === 'page');
        assert.ok(target, 'Browser page target must exist');
        socket = new WebSocket(target.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
        const call = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++sequence;
            const timer = setTimeout(() => { pending.delete(id); reject(new Error('CDP timeout: ' + method)); }, 15000);
            pending.set(id, { resolve, reject, timer });
            socket.send(JSON.stringify({ id, method, params }));
        });
        socket.onmessage = event => {
            const data = JSON.parse(event.data);
            if (data.method === 'Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.text);
            if (data.method === 'Network.responseReceived' && data.params.response.status >= 400 && ['Stylesheet', 'Script'].includes(data.params.type)) badAssets.push(data.params.response.url);
            const waiting = pending.get(data.id);
            if (!waiting) return;
            clearTimeout(waiting.timer);
            pending.delete(data.id);
            data.error ? waiting.reject(new Error(data.error.message)) : waiting.resolve(data.result);
        };
        await call('Page.enable');
        await call('Runtime.enable');
        await call('Network.enable');
        const evaluate = async expression => {
            const response = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
            if (response.exceptionDetails) throw new Error(JSON.stringify(response.exceptionDetails));
            return response.result.value;
        };
        const wait = async expression => {
            for (let i = 0; i < 120; i++) {
                if (await evaluate(expression)) return;
                await pause(100);
            }
            throw new Error('Timed out: ' + expression + '; URL: ' + await evaluate('location.href'));
        };
        const visit = async route => {
            const separator = route.includes('?') ? '&' : '?';
            await call('Page.navigate', { url: origin + route + separator + 'adc_vehicle_url_test=' + Date.now() });
            await wait('document.readyState === "complete" && !!document.querySelector("#colophon")');
        };

        await visit('/cars/');
        const archive = await evaluate(`(() => {
            const root = ${JSON.stringify(origin)};
            const paths = [...document.querySelectorAll('a[href]')].map(a => new URL(a.href, location.href).pathname)
                .filter(path => path.startsWith(new URL(root).pathname + '/cars/'));
            const vehiclePaths = [...new Set(paths)].filter(path => /\\/cars\\/[^/]+\\/?$/.test(path) && !path.endsWith('/cars/feed/'));
            return { total: vehiclePaths.length, clean: vehiclePaths.filter(path => /\\/cars\\/car-[0-9]+\\/?$/.test(path)).length, bad: vehiclePaths.filter(path => !/\\/cars\\/car-[0-9]+\\/?$/.test(path)) };
        })()`);
        check(archive.total > 0, 'Vehicle archive exposes vehicle links');
        check(archive.clean === archive.total && archive.bad.length === 0, 'All generated vehicle links use ASCII identifiers');

        const vehicleUrl = await evaluate(`(() => [...document.querySelectorAll('a[href]')].map(a => a.href).find(href => /\\/cars\\/car-[0-9]+\\/?(?:[?#]|$)/.test(new URL(href, location.href).pathname)) || '')()`);
        assert.match(vehicleUrl, /\/cars\/car-[0-9]+\//, 'A clean vehicle URL must be discoverable');
        const sitePath = new URL(origin).pathname.replace(/\/$/, '');
        const vehicleRoute = new URL(vehicleUrl).pathname.replace(sitePath, '') || '/';
        await visit(vehicleRoute);
        check(await evaluate('document.body.classList.contains("single-car")'), 'Clean vehicle URL resolves to a vehicle page');
        check(await evaluate('/\\/cars\\/car-[0-9]+\\/?$/.test(location.pathname)'), 'Clean vehicle page keeps its canonical path');
        check(await evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), 'Vehicle page has no horizontal overflow');

        const legacy = await evaluate(`fetch(${JSON.stringify(origin + '/wp-json/wp/v2/car?per_page=1&_fields=id,slug')}).then(response => response.json()).then(rows => {
            const slug = String(rows[0].slug || '');
            return '/cars/' + (slug.includes('%') ? slug : encodeURIComponent(slug)) + '/';
        })`);
        assert.match(legacy, /\/cars\/.+\//, 'A legacy vehicle URL must be discoverable');
        await visit(legacy);
        check(await evaluate('document.body.classList.contains("single-car")'), 'Legacy vehicle URL remains reachable');
        check(await evaluate('/\\/cars\\/car-[0-9]+\\/?$/.test(location.pathname)'), 'Legacy vehicle URL redirects to the clean canonical path');
        check(errors.length === 0, 'No browser runtime exceptions');
        check(badAssets.length === 0, 'No missing scripts or styles');
        console.log('Vehicle URL browser checks passed: ' + passed);
    } finally {
        if (socket) socket.close();
        browser.kill();
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
