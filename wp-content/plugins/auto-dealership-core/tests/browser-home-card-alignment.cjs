'use strict';
// Responsive scrolling fixture uses live theme CSS and production rail JavaScript; no inventory writes.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');

(async () => {
    const origin = process.env.ADC_SITE_ORIGIN || 'http://localhost/wordpress';
    assert.match(origin, /^http:\/\/(?:localhost|127\.0\.0\.1)(?::\d+)?\/wordpress\/?$/);
    const root = origin.replace(/\/$/, '');
    const profileRoot = path.resolve(__dirname, '../../../../.tmp/browser-profiles');
    fs.mkdirSync(profileRoot, { recursive: true });
    const profile = fs.mkdtempSync(path.join(profileRoot, 'adc-dashboard-'));
    const browser = spawn(process.env.ADC_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', [
        '--headless=new', '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=0',
        '--no-first-run', '--disable-background-networking', '--disable-sync',
        `--user-data-dir=${profile}`, 'about:blank'
    ], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
    let browserLog = '';
    browser.stderr.on('data', chunk => { browserLog = (browserLog + chunk.toString()).slice(-2000); });
    const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
    let socket;
    let passed = 0;
    const errors = [];
    const badAssets = [];
    const captures = path.resolve(__dirname, '../../../../.tmp/catalog-publishing-acceptance');
    fs.mkdirSync(captures, { recursive: true });
    const authFile=path.resolve(__dirname,'../../../../.tmp/dashboard-browser-session.json');
    const check = (value, description) => {
        assert.ok(value, description);
        passed++;
        process.stdout.write(`PASS ${description}\n`);
    };
    try {
        const activePort = path.join(profile, 'DevToolsActivePort');
        for (let i = 0; i < 200 && !fs.existsSync(activePort); i++) await pause(100);
        assert.ok(fs.existsSync(activePort), `Browser must start: ${browserLog}`);
        const port = Number(fs.readFileSync(activePort, 'utf8').split('\n')[0]);
        const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        const target = targets.find(item => item.type === 'page');
        assert.ok(target, 'Browser page target must exist');
        socket = new WebSocket(target.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
        let sequence = 0;
        const pending = new Map();
        const call = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++sequence;
            const timer = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 15000);
            pending.set(id, { resolve, reject, timer });
            socket.send(JSON.stringify({ id, method, params }));
        });
        socket.onmessage = event => {
            const data = JSON.parse(event.data);
            if (data.method === 'Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.text);
            if (data.method === 'Network.responseReceived' && data.params.response.status >= 400 && ['Stylesheet', 'Script'].includes(data.params.type)) badAssets.push(data.params.response.url);
            if (data.method === 'Fetch.requestPaused') {
                const request = data.params;
                const local = request.request.url.startsWith(root + '/') || request.request.url.startsWith('data:');
                call(local ? 'Fetch.continueRequest' : 'Fetch.failRequest', local ? { requestId: request.requestId } : { requestId: request.requestId, errorReason: 'BlockedByClient' }).catch(() => {});
            }
            const waiting = pending.get(data.id);
            if (!waiting) return;
            clearTimeout(waiting.timer);
            pending.delete(data.id);
            data.error ? waiting.reject(new Error(data.error.message)) : waiting.resolve(data.result);
        };
        await call('Page.enable');
        await call('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
        await call('Runtime.enable');
        await call('Network.enable');
        await call('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
        const evaluate = async expression => {
            const response = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
            if (response.exceptionDetails) throw new Error(JSON.stringify(response.exceptionDetails));
            return response.result.value;
        };
        const wait = async (expression, description) => {
            for (let i = 0; i < 120; i++) {
                try { if (await evaluate(expression)) return; } catch {}
                await pause(100);
            }
            throw new Error(`Timed out: ${description}; URL: ${await evaluate('location.href')}`);
        };
        const visit = async route => {
            await call('Page.navigate', { url: root + route });
            await wait("document.readyState === 'complete' && !!document.querySelector('#colophon')", route);
        };


        for(const lang of ['ar','en']) {
          await visit('/?lang='+lang);
          for(const width of [1440,768,390]) {
            await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});await pause(150);
            const result=await evaluate('(()=>{const groups=Array.from(document.querySelectorAll(".ab-section .car-grid"));let count=0;let bad=[];for(const g of groups){const cards=Array.from(g.querySelectorAll(".car-card"));count+=cards.length;for(const cls of [".car-image",".car-brand",".car-title",".car-price",".car-model-colors",".public-price-inclusions",".car-monthly",".car-specs",".car-card-actions"]){const y=cards.filter(c=>c.querySelector(cls)).map(c=>c.querySelector(cls).getBoundingClientRect().top-c.getBoundingClientRect().top);if(y.length>1&&Math.max(...y)-Math.min(...y)>1)bad.push(cls)}const h=cards.map(c=>c.getBoundingClientRect().height);if(h.length>1&&Math.max(...h)-Math.min(...h)>1)bad.push("card height")}return {count,bad}})()');
            check(result.count>1&&result.bad.length===0,'Live homepage card slots and heights align '+lang+' '+width+' '+JSON.stringify(result.bad));
            const stable=await evaluate('(()=>{const c=document.querySelector(".ab-section .car-card");const before=c.getBoundingClientRect().height;const y=c.querySelector(".car-card-actions").getBoundingClientRect().top-c.getBoundingClientRect().top;const nodes=[c.querySelector(".car-title"),c.querySelector(".car-model-colors"),c.querySelector(".public-price-inclusions")].filter(Boolean);const original=nodes.map(n=>n.innerHTML);nodes.forEach(n=>n.textContent="Long vehicle title with extended specifications and many colours ".repeat(10));const ok=Math.abs(before-c.getBoundingClientRect().height)<1&&Math.abs(y-(c.querySelector(".car-card-actions").getBoundingClientRect().top-c.getBoundingClientRect().top))<1;nodes.forEach((n,i)=>n.innerHTML=original[i]);return ok})()');
            check(stable,'Long multi-line text preserves card height and action position '+lang+' '+width);
            check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'No page overflow '+lang+' '+width);
          }
        }
        check(errors.length===0,'No browser runtime exceptions');check(badAssets.length===0,'No missing styles or scripts');
        console.log('Homepage card alignment checks passed: '+passed);
    } finally { if(socket) socket.close(); browser.kill(); }
})().catch(error=>{console.error(error.message);process.exitCode=1;});
