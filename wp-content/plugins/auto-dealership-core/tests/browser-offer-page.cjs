'use strict';

// Public offer page contract: visual composition, clean permalink, legacy route compatibility, and responsive safety.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');

(async () => {
    const origin = (process.env.ADC_SITE_ORIGIN || 'http://localhost/wordpress').replace(/\/$/, '');
    const route = process.env.ADC_OFFER_ROUTE || '/offers/offer-111/';
    const legacyRoute = process.env.ADC_OFFER_LEGACY_ROUTE || '/offers/%d8%a7%d9%84%d9%8a%d9%88%d9%85-%d8%a7%d9%84%d9%88%d8%b7%d9%86%d9%8a/';
    const browserPath = process.env.ADC_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    const profileRoot = path.resolve(__dirname, '../../../../.tmp/browser-profiles');
    const captures = path.resolve(__dirname, '../../../../.tmp/offer-page-acceptance');
    fs.mkdirSync(profileRoot, { recursive: true });
    fs.mkdirSync(captures, { recursive: true });
    const profile = fs.mkdtempSync(path.join(profileRoot, 'adc-offer-'));
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
        await call('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
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
        const visit = async (pathName, width, label) => {
            await call('Emulation.setDeviceMetricsOverride', { width, height: 1100, deviceScaleFactor: 1, mobile: false });
            const hashIndex = pathName.indexOf('#');
            const basePath = hashIndex >= 0 ? pathName.slice(0, hashIndex) : pathName;
            const hash = hashIndex >= 0 ? pathName.slice(hashIndex) : '';
            const separator = basePath.includes('?') ? '&' : '?';
            await call('Page.navigate', { url: origin + basePath + separator + 'adc_offer_test=' + Date.now() + hash });
            await wait('document.readyState === "complete" && !!document.querySelector("#colophon") && !!document.querySelector("h1#offer-title")');
            check(await evaluate('document.body.classList.contains("single-car_offer")'), label + ': offer body contract');
            check(await evaluate('!!document.querySelector(".ab-offer-media-frame") && !!document.querySelector("#offer-lead")'), label + ': visual and lead regions exist');
            check(await evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), label + ': no horizontal overflow');
            check(await evaluate('document.querySelector(".ab-offer-single").getBoundingClientRect().height < (innerWidth < 600 ? 1150 : 950)'), label + ': hero has bounded height');
            return await evaluate('location.href');
        };

        const cleanUrl = await visit(route, 1440, 'Clean URL desktop');
        check(cleanUrl.includes('/offers/offer-111/'), 'Generated offer URL uses ASCII offer identifier');
        await call('Page.captureScreenshot', { format: 'png' }).then(result => fs.writeFileSync(path.join(captures, 'offer-desktop.png'), Buffer.from(result.data, 'base64')));
        await visit(route, 390, 'Clean URL mobile');
        await call('Page.captureScreenshot', { format: 'png' }).then(result => fs.writeFileSync(path.join(captures, 'offer-mobile.png'), Buffer.from(result.data, 'base64')));
        const legacyUrl = await visit(legacyRoute, 390, 'Legacy Arabic URL mobile');
        check(legacyUrl.includes('/offers/'), 'Legacy offer URL remains reachable');
        check(legacyUrl.includes('/offers/offer-111/'), 'Legacy URL normalizes to the clean offer path');
        check(await evaluate('document.querySelector("h1#offer-title")?.textContent.trim() === "اليوم الوطني"'), 'Legacy URL resolves to the same offer');
        await visit(legacyRoute + '#offer-lead', 390, 'Legacy offer lead anchor mobile');
        await pause(500);
        const anchorState = await evaluate('({hash: location.hash, scrollY: window.scrollY, top: document.querySelector("#offer-lead").getBoundingClientRect().top})');
        process.stdout.write('ANCHOR ' + JSON.stringify(anchorState) + '\n');
        check(anchorState.hash === '#offer-lead' && anchorState.scrollY > 300 && (await evaluate('location.pathname')).includes('/offers/offer-111/'), 'Legacy offer CTA anchor reaches the lead form and clean URL');
        check(errors.length === 0, 'No browser runtime exceptions');
        check(badAssets.length === 0, 'No missing scripts or styles');
        console.log('Offer page browser checks passed: ' + passed);
    } finally {
        if (socket) socket.close();
        browser.kill();
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
