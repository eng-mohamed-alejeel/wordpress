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
          await visit('/cars/?lang='+lang+'&verify_filters='+Date.now());
          check(await evaluate('!!document.querySelector("[data-customer-filters]")'),'Customer filter interface '+lang);
          check(await evaluate('["branch_id","engine_size","drivetrain","condition","interior_color","fuel_type","transmission"].every(n=>!document.querySelector(".filter-bar [name="+n+"]"))'),'Unsupported and internal filters absent '+lang);
          const choice=await evaluate('(()=>{const f=document.querySelector("[data-customer-filters]");f.elements.brand.selectedIndex=1;f.elements.brand.dispatchEvent(new Event("change"));f.elements.model.selectedIndex=1;f.elements.model.dispatchEvent(new Event("change"));return {brand:f.elements.brand.value,model:f.elements.model.value,modelLabel:f.elements.model.selectedOptions[0].textContent,models:Array.from(f.elements.model.options).slice(1).map(o=>o.value),rows:JSON.parse(f.querySelector("[data-catalog-choices]").textContent)}})()');
          check(choice.models.every(m=>choice.rows.some(r=>r.brand===choice.brand&&r.model===m)),'Models limited to chosen brand '+lang);
          await evaluate('document.querySelector("[data-customer-filters] button[type=submit]").click()');
          await wait('document.readyState==="complete"&&location.search.includes("brand=")','Applied brand/model');
          check(await evaluate('document.querySelectorAll(".car-card").length>0 && Array.from(document.querySelectorAll(".car-card .car-title")).every(t=>t.textContent.includes('+JSON.stringify(choice.modelLabel)+'))'),'Real brand/model result matches '+lang);
          await visit('/cars/?lang='+lang+'&max_price=150000&sort=price_asc');
          const prices=await evaluate('Array.from(document.querySelectorAll(".car-card .car-price")).map(n=>Number(n.textContent.replace(/[^0-9.]/g,"")))');
          check(prices.length>0&&prices.every(p=>p<=150000)&&prices.every((p,i)=>!i||p>=prices[i-1]),'Final customer price limit and ascending order '+lang);
          for(const width of [1440,768,390]) {await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});await pause(100);check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'Catalog fits viewport '+lang+' '+width);check(await evaluate('Array.from(document.querySelectorAll(".cd-filter-primary input,.cd-filter-primary select")).every(n=>n.getBoundingClientRect().height>=44&&n.getBoundingClientRect().width>=120&&n.scrollWidth<=n.clientWidth+2)'),'Uniform usable filter fields '+lang+' '+width);check(await evaluate('getComputedStyle(document.querySelector(".cd-customer-filters")).gridTemplateColumns.split(" ").length===1'),'Outer filter layout avoids nested column squeeze '+lang+' '+width);}
          await evaluate('document.querySelector(".filter-actions a").click()');await wait('document.readyState==="complete"&&!location.search.includes("max_price")','Clear filters');
          check(await evaluate('document.documentElement.dir==='+JSON.stringify(lang==='en'?'ltr':'rtl')),'Reset retains language '+lang);
        }
        check(errors.length===0,'No runtime errors');check(badAssets.length===0,'No missing styles or scripts');console.log('Catalog customer filter checks passed: '+passed);
    } finally { if(socket) socket.close(); browser.kill(); }
})().catch(error=>{console.error(error.message);process.exitCode=1;});
