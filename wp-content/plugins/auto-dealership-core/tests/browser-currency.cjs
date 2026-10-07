'use strict';
// Receives an isolated loopback origin and fixture IDs on stdin.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');
const assert = require('node:assert/strict');

(async () => {
    const input = JSON.parse(fs.readFileSync(0, 'utf8'));
    assert.match(input.origin, /^http:\/\/127\.0\.0\.1:[0-9]+$/);

    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'adc-currency-browser-'));
    const browser = spawn(process.env.ADC_BROWSER || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', [
        '--headless=new', '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=0',
        '--no-first-run', '--disable-background-networking', '--disable-sync',
        `--user-data-dir=${profile}`, 'about:blank'
    ], { windowsHide: true, stdio: 'ignore' });
    let socket;
    let checks = 0;
    const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
    const check = (condition, message) => {
        assert.ok(condition, message);
        ++checks;
        console.log(`CURRENCY BROWSER PASS: ${message}`);
    };
    try {
        const active = path.join(profile, 'DevToolsActivePort');
        for (let i = 0; i < 200 && !fs.existsSync(active); i++) await pause(100);
        assert.ok(fs.existsSync(active), 'Browser must start.');
        let port = 0;
        for (let i = 0; i < 100 && !port; i++) {
            try { port = Number(fs.readFileSync(active, 'utf8').split('\n')[0]); } catch {}
            if (!port) await pause(100);
        }
        assert.ok(port > 0, 'Browser debug endpoint must be readable.');
        const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        const pageTarget = targets.find(target => target.type === 'page');
        assert.ok(pageTarget, 'Browser page target must be available.');
        socket = new WebSocket(pageTarget.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });

        let sequence = 0;
        const pending = new Map();
        const errors = [];
        const badAssets = [];
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
                const paused = data.params;
                const local = paused.request.url.startsWith(input.origin + '/') || paused.request.url.startsWith('data:');
                call(local ? 'Fetch.continueRequest' : 'Fetch.failRequest', local ? { requestId: paused.requestId } : { requestId: paused.requestId, errorReason: 'BlockedByClient' }).catch(() => {});
            }
            const request = pending.get(data.id);
            if (!request) return;
            clearTimeout(request.timer);
            pending.delete(data.id);
            data.error ? request.reject(new Error(data.error.message)) : request.resolve(data.result);
        };
        await call('Page.enable');
        await call('Runtime.enable');
        await call('Network.enable');
        await call('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
        const evaluate = async expression => {
            const result = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
            if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
            return result.result.value;
        };
        const wait = async (expression, label) => {
            for (let i = 0; i < 120; i++) {
                try { if (await evaluate(expression)) return; } catch {}
                await pause(100);
            }
            throw new Error(`Timed out: ${label}; URL: ${await evaluate('location.href')}`);
        };
        const navigate = async (url, selector) => {
            await call('Page.navigate', { url: input.origin + url });
            await wait(`document.readyState === 'complete' && !!document.querySelector(${JSON.stringify(selector)})`, selector);
        };
        const artifacts = path.resolve(__dirname, '../../../../docs/artifacts/currency');
        fs.mkdirSync(artifacts, { recursive: true });


        await call('Network.setCookies', {cookies: input.cookie.split('; ').map(pair => { const split=pair.indexOf('='); return {name:pair.slice(0,split),value:pair.slice(split+1),url:input.origin+'/'}; })});
        await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        for (const lang of ['ar','en']) {
            for (const view of ['inventory','settings','payments','refunds','acquisition','finance']) {
                await navigate('/adc-test-currency?view='+view+'&lang='+lang,'h1');
                check(await evaluate('document.documentElement.lang === '+JSON.stringify(lang)+' && document.documentElement.dir === '+JSON.stringify(lang==='ar'?'rtl':'ltr')),view+' declares correct '+lang+' direction.');
                check(await evaluate('Array.from(document.querySelectorAll("input[name=adc_money_unit]")).every(input=>input.value==="SAR")'),view+' forms identify SAR.');
                const expected = {inventory:['1234.56','2345.67'],payments:['100.25','1319.49'],refunds:['0.01','1419.73'],acquisition:['1012.59'],finance:['1319.49']}[view];
                if (expected) check(await evaluate(JSON.stringify(expected)+'.every(value=>document.body.innerText.includes(value))'),view+' displays the recorded SAR fractions in '+lang+'.');
                const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
                fs.writeFileSync(path.join(artifacts,view+'-'+lang+'.png'),Buffer.from(shot.data,'base64'));
                if(view==='settings') {
                    check(await evaluate('(() => { const input=document.querySelector("[data-currency-value]"); const select=input.closest("td").querySelector("select"); select.value="fixed";select.dispatchEvent(new Event("change"));return input.step==="0.01" && input.value==="0" && !input.disabled;})()'),'Fixed SAR setting resets safely and permits two decimals.');
                    check(await evaluate('(() => { const input=document.querySelector("[data-currency-value]"); const select=input.closest("td").querySelector("select"); select.value="percentage";select.dispatchEvent(new Event("change"));return input.step==="1" && input.value==="0";})()'),'Percentage setting preserves basis points.');
                }
            }
            await navigate(input.print_path+'&lang='+lang,'body');
            await wait('document.body.innerText.includes("1419.74")','printed SAR total');
            check(await evaluate('document.body.innerText.includes("185.18") && !document.body.innerText.includes("141974")'),'Print total and tax retain SAR precision in '+lang+'.');
            const screenshot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
            fs.writeFileSync(path.join(artifacts,'quote-'+lang+'.png'),Buffer.from(screenshot.data,'base64'));
            const pdf=await call('Page.printToPDF',{printBackground:true,preferCSSPageSize:true});
            const bytes=Buffer.from(pdf.data,'base64');check(bytes.subarray(0,4).toString()==='%PDF','Generated '+lang+' quotation PDF.');
            fs.writeFileSync(path.join(artifacts,'quote-'+lang+'.pdf'),bytes);
        }
        check(errors.length===0,'No uncaught currency JavaScript errors: '+errors.join(';'));
        check(badAssets.length===0,'Currency CSS and scripts load successfully: '+badAssets.join(';'));
        console.log('Completed '+checks+' currency browser checks. Artifacts: '+artifacts);
        await call('Browser.close').catch(()=>{});
    } finally {
        if (socket) socket.close();
        if (browser.exitCode === null) browser.kill();
        await pause(500);
        const resolved = path.resolve(profile);
        if (path.dirname(resolved) === path.resolve(os.tmpdir()) && path.basename(resolved).startsWith('adc-currency-browser-')) {
            try { fs.rmSync(resolved, { recursive: true, force: true }); } catch {}
        }
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
