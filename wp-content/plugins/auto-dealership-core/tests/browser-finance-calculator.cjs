'use strict';
// Read-only public-site acceptance against the local development origin.
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
    const profile = fs.mkdtempSync(path.join(profileRoot, 'adc-finance-'));
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
    const captures = path.resolve(__dirname, '../../../../.tmp/finance-acceptance');
    fs.mkdirSync(captures, { recursive: true });
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
        for (const language of ['ar', 'en']) {
            await visit('/finance/?lang='+language);
            check(await evaluate('!!document.querySelector("[data-finance-widget]")'),language+' shared form');
            check(await evaluate('document.querySelectorAll("[data-loan-calculator] [name]").length===18'),language+' all inputs rendered');
            check(await evaluate('document.querySelector("[data-loan-calculator] h2") === null'),language+' heading outside form');
            await evaluate(`(()=>{const f=document.querySelector('[data-loan-calculator]');f.elements.price.value='100000';f.elements.salary.value='10000';f.requestSubmit();})()`);
            await wait('document.querySelectorAll("[data-loan-results] tbody tr").length===11','11 comparison rows');
            check(await evaluate('document.querySelectorAll(".adc-finance-badge").length===2'),language+' best badges exclude missing rules');
            check(await evaluate('document.querySelector("[data-loan-results] tbody").innerText.includes("'+(language==='ar'?'بنك الرياض':'Riyad Bank')+'")'),language+' localized AJAX response');
            check(await evaluate('document.querySelector("[data-loan-results] details dl").children.length===17'),language+' detailed breakdown');
            await evaluate('document.querySelector("[data-loan-results] input[type=checkbox]").click()');
            check(await evaluate('document.querySelectorAll("[data-loan-results] tbody tr").length===3'),language+' eligibility filter');
            await evaluate('document.querySelector("[data-loan-results] input[type=checkbox]").click()');
            await evaluate(`(()=>{const select=document.querySelector('[data-loan-results] select');select.value='annual_rate';select.dispatchEvent(new Event('change'));})()`);
            check(await evaluate('document.querySelector("[data-loan-results] tbody tr th").innerText.includes("'+(language==='ar'?'بنك الرياض':'Riyad Bank')+'")'),language+' rate sort');
            await evaluate(`(()=>{window.financeBlob=null;window.originalFinanceCreate=URL.createObjectURL;URL.createObjectURL=b=>{window.financeBlob=b;return window.originalFinanceCreate(b);};window.originalAnchorClick=HTMLAnchorElement.prototype.click;HTMLAnchorElement.prototype.click=function(){};document.querySelector('[data-loan-results] button').click();})()`);
            check((await evaluate('window.financeBlob.text()')).includes('Monthly incl insurance SAR'),language+' CSV export contains totals');
            await evaluate('document.querySelectorAll("[data-loan-results] button")[1].click()');
            check(JSON.parse(await evaluate('window.financeBlob.text()')).results.length===11,language+' JSON export complete');
            await evaluate('URL.createObjectURL=window.originalFinanceCreate;HTMLAnchorElement.prototype.click=window.originalAnchorClick');
            await evaluate(`(()=>{const f=document.querySelector('[data-loan-calculator]');f.elements.provider.value='alrajhi';f.elements.campaign.value='half';f.elements.months.value='24';f.requestSubmit();})()`);
            await wait('document.querySelectorAll("[data-loan-results] tbody tr").length===1','50/50 result');
            check(await evaluate('document.querySelector("[data-loan-results] tbody tr").children[2].innerText.includes("'+(language==='ar'?'٢٥٠':'250')+'")'),language+' zero profit 50/50 includes insurance');
            await evaluate(`(()=>{const f=document.querySelector('[data-loan-calculator]');f.elements.provider.value='raya';f.elements.campaign.value='regular';f.elements.months.value='60';f.elements.salary.value='3000';f.requestSubmit();})()`);
            await wait('!!document.querySelector(".adc-finance-ineligible")','red minimum salary failure');
            check(await evaluate('document.querySelectorAll(".adc-finance-badge").length===0'),language+' ineligible has no badges');
            await evaluate(`(()=>{const input=document.querySelector('[name=salary]');input.value='10000';input.dispatchEvent(new Event('change',{bubbles:true}));})()`);
            check(await evaluate('document.querySelector("[data-loan-results]").children.length===0'),language+' stale results cleared after input change');
            for(const width of [320,390,1440]) {
                await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<500});
                check(await evaluate('document.documentElement.scrollWidth<=document.documentElement.clientWidth+1'),language+' no page overflow at '+width);
            }
            await evaluate("document.querySelector('[data-loan-calculator]').requestSubmit()");
            await wait('document.querySelectorAll("[data-loan-results] tbody tr").length===1','Screenshot comparison result');
            const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync(path.join(captures,'finance-'+language+'.png'),Buffer.from(shot.data,'base64'));
        }
        await visit('/');check(await evaluate('!document.querySelector("[data-finance-widget]")'),'Homepage has no finance calculator');
        await visit('/cars/');
        const carUrl=await evaluate("[...document.querySelectorAll('a')].find(a=>a.href.includes('/cars/') && a.href.split('/cars/')[1].split('?')[0].split('#')[0].length>1)?.href");
        assert.ok(carUrl, 'A published vehicle detail link exists');
        if(carUrl) { await call('Page.navigate',{url:carUrl});await wait("document.readyState==='complete'",'Vehicle detail');check(await evaluate('!!document.querySelector("[data-finance-widget]")'),'Vehicle detail uses shared calculator');check(await evaluate('Number(document.querySelector("[data-loan-price]").value)>0'),'Vehicle price prefilled'); }
        check(errors.length===0,'No runtime exceptions: '+errors.join('; '));check(badAssets.length===0,'No missing assets: '+badAssets.join('; '));
        process.stdout.write('FINANCE BROWSER PASS: '+passed+' checks. Screenshots: '+captures+'\n');

    } finally {
        if (socket) socket.close();
        browser.kill();
        await pause(500);
        if (path.dirname(path.resolve(profile)) === profileRoot && path.basename(profile).startsWith('adc-finance-')) {
            try { fs.rmSync(profile, { recursive: true, force: true }); } catch {}
        }
    }
})().catch(error => { process.stderr.write(`${error.stack || error}\n`); process.exitCode = 1; });

