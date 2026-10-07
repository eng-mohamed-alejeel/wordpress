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
    const profile = fs.mkdtempSync(path.join(profileRoot, 'adc-finance-admin-'));
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
    const captures = path.resolve(__dirname, '../../../../.tmp/finance-admin-acceptance');
    fs.mkdirSync(captures, { recursive: true });
    const authFile=path.resolve(__dirname,'../../../../.tmp/finance-admin-browser-session.json');
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
        const session=JSON.parse(fs.readFileSync(authFile,'utf8'));
        for(const cookie of session.cookies) await call('Network.setCookie',{name:cookie.name,value:cookie.value,url:root+'/',path:'/wordpress/',httpOnly:true,sameSite:'Lax'});
        async function admin(route){await call('Page.navigate',{url:root+'/wp-admin/admin.php?page=adc-finance-calculator'+route});await wait('!!document.querySelector(".adc-finance-admin") && document.readyState==="complete"','Admin page '+route);}
        await admin('');check(await evaluate('document.querySelectorAll(".adc-finance-admin tbody tr").length===11'),'Existing providers migrated into editor');
        await admin('&edit=alrajhi');check(await evaluate('document.querySelectorAll("[data-finance-program]").length===3'),'Three program editors');
        check(await evaluate('document.querySelectorAll("[data-finance-rule]").length>10'),'Existing conditional rates shown');
        await evaluate('document.querySelector("[data-finance-add-rule]").click()');
        check(await evaluate('!document.querySelector("input[name*=__INDEX__]")'),'New rule index normalized');
        await evaluate('document.querySelector("[data-finance-rules]").lastElementChild.querySelector("[data-finance-remove-rule]").click()');
        await admin('&new=1');
        await evaluate(`(()=>{
          const f=document.querySelector('[data-finance-admin-form]');const set=(name,value)=>f.querySelector('[name="'+name+'"]').value=value;
          set('provider[name][0]','جهة تحقق الإدارة');set('provider[name][1]','Admin acceptance provider');f.querySelector('[name="provider[active]"]').checked=true;
          f.querySelector('[name="provider[programs][regular][active]"]').checked=true;
          ['rate','down','balloon','admin','min_salary','rebate'].forEach((field,i)=>set('provider[programs][regular][values]['+field+']',[2,10,20,0,3000,0][i]));
          f.querySelector('[data-finance-add-rule]').click();
          set('provider[programs][regular][rules][0][when][transfer]','nst');set('provider[programs][regular][rules][0][values][rate]','4');
          f.requestSubmit();
        })()`);
        await wait('!!document.querySelector(".notice-success")','Provider saved by admin POST');
        const editId=await evaluate('new URL(location.href).searchParams.get("edit")');check(editId.startsWith('provider_'),'Stable unique identifier assigned');
        check(await evaluate("document.querySelector('[data-finance-admin-form]').elements.namedItem('provider[name][1]').value==='Admin acceptance provider'"),'Added provider persisted');
        await call('Page.navigate',{url:root+'/finance/?lang=en'});await wait('!!document.querySelector("[data-loan-calculator]") && document.readyState==="complete"','Public calculator');
        check(await evaluate('!!document.querySelector("option[value='+editId+']")'),'New provider available in public form');
        await evaluate(`(()=>{const f=document.querySelector('[data-loan-calculator]');f.elements.price.value='100000';f.elements.salary.value='10000';f.elements.provider.value='${editId}';f.elements.transfer.value='nst';f.requestSubmit();})()`);
        await wait('document.querySelectorAll("[data-loan-results] tbody tr").length===1','Configured result');
        check(await evaluate('document.querySelector("[data-loan-results] tbody").innerText.includes("4.00%")'),'Admin rule affects public rate');
        await admin('&edit='+editId);
        await evaluate(`(()=>{const f=document.querySelector('[data-finance-admin-form]');f.querySelector('[name="provider[programs][regular][values][rate]"]').value='3';f.requestSubmit();})()`);
        await wait('!!document.querySelector(".notice-success")','Provider edit saved');

        check(await evaluate("(()=>{const f=document.querySelector('[data-finance-admin-form]');const input=f.elements.namedItem('provider[programs][regular][values][rate]');new FormData(f);input.value='5';const d=new FormData(f);const result=JSON.parse(d.get('payload'));return input.name==='provider[programs][regular][values][rate]'&&result.provider.programs.regular.values.rate==='5'&&!d.has(input.name);})()"),'Repeated serialization preserves names and uses latest values');
        await admin('&settings=1');
        await evaluate(`(()=>{const f=document.querySelector('[data-finance-admin-form]');f.querySelector('[name="settings[insurance_min]"]').value='1';f.querySelector('[name="settings[insurance_max]"]').value='6';f.querySelector('[name="settings[insurance_default]"]').value='2';f.querySelector('[name="settings[categories][A]"]').value='Toyota, Acceptance Brand';f.requestSubmit();})()`);
        await wait('!!document.querySelector(".notice-success")','Settings save');
        await call('Page.navigate',{url:root+'/finance/?lang=en'});await wait('!!document.querySelector("[name=insurance_rate]") && document.readyState==="complete"','Public settings');
        check(await evaluate('document.querySelector("[name=insurance_rate]").min==="1" && document.querySelector("[name=insurance_rate]").max==="6" && document.querySelector("[name=insurance_rate]").value==="2"'),'Insurance settings reflected in public form');
        check(await evaluate('document.querySelector(".adc-finance-brands").innerText.includes("Acceptance Brand")'),'Category lists are managed');
        await admin('');
        await evaluate(`(()=>{const row=[...document.querySelectorAll('tbody tr')].find(r=>r.innerText.includes('Admin acceptance provider'));row.querySelector('[name=operation][value=toggle]').form.requestSubmit();})()`);
        await wait('!!document.querySelector(".notice-success") && document.readyState==="complete"','Disable save');
        await call('Page.navigate',{url:root+'/finance/?lang=en'});await wait('!!document.querySelector("[data-loan-calculator]") && document.readyState==="complete"','Disabled public provider');
        check(await evaluate('!document.querySelector("option[value='+editId+']")'),'Disabled provider hidden from public');
        await admin('');await evaluate(`(()=>{const row=[...document.querySelectorAll('tbody tr')].find(r=>r.innerText.includes('Admin acceptance provider'));const form=row.querySelector('[name=operation][value=delete]').form;form.querySelector('[name=confirm_delete]').checked=true;form.requestSubmit();})()`);
        await wait('!!document.querySelector(".notice-success") && document.readyState==="complete"','Delete save');check(await evaluate('!document.querySelector(".adc-finance-admin").innerText.includes("Admin acceptance provider")'),'Deleted provider removed');
        await admin('&settings=1');const savedRevision=await evaluate('document.querySelector("[name=revision]").value');
        const rejection=await evaluate(`(async()=>{const f=document.querySelector('[data-finance-admin-form]');const data=new FormData(f);data.set('_wpnonce','invalid');const r=await fetch(f.getAttribute('action'),{method:'POST',body:data});return r.status;})()`);check(rejection===403,'Invalid nonce rejected (HTTP '+rejection+')');
        await evaluate(`(()=>{const f=document.querySelector('[data-finance-admin-form]');f.querySelector('[name=revision]').value='stale';f.requestSubmit();})()`);
        await wait('!!document.querySelector(".notice-error")','Concurrent edit rejected');check(await evaluate('document.querySelector(".notice-error").innerText.includes("نافذة أخرى")'),'Conflict explained without overwrite');
        for(const width of [390,1440]){await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<500});check(await evaluate('document.documentElement.scrollWidth<=document.documentElement.clientWidth+1'),'Admin settings fit width '+width);}
        const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync(path.join(captures,'finance-admin.png'),Buffer.from(shot.data,'base64'));
        check(errors.length===0,'No browser exceptions: '+errors.join('; '));check(badAssets.length===0,'No missing assets: '+badAssets.join('; '));
        process.stdout.write('FINANCE ADMIN BROWSER PASS: '+passed+' checks.\n');
    } finally {
        if (socket) socket.close();
        browser.kill();
        await pause(500);
        if (path.dirname(path.resolve(profile)) === profileRoot && path.basename(profile).startsWith('adc-finance-')) {
            try { fs.rmSync(profile, { recursive: true, force: true }); } catch {}
        }
    }
})().catch(error => { process.stderr.write(`${error.stack || error}\n`); process.exitCode = 1; });

