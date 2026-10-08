'use strict';
// Read-only dashboard acceptance against the local development origin.
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
    const captures = path.resolve(__dirname, '../../../../.tmp/dashboard-acceptance');
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
            throw new Error(`Timed out: ${description}; URL: ${await evaluate('location.href')}; ${await evaluate('document.body.innerText.slice(0,1500)')}`);
        };
        const visit = async route => {
            await call('Page.navigate', { url: root + route });
            await wait("document.readyState === 'complete' && !!document.querySelector('#colophon')", route);
        };
        const session=JSON.parse(fs.readFileSync(authFile,'utf8'));
        for(const cookie of session.cookies) await call('Network.setCookie',{name:cookie.name,value:cookie.value,url:root+'/',path:'/wordpress/',httpOnly:true,sameSite:'Lax'});


        const fixture=JSON.parse(fs.readFileSync(path.resolve(__dirname,'../../../../.tmp/access-browser-fixture.json'),'utf8'));
        const navigate=async route=>{await call('Page.navigate',{url:root+'/wp-admin/'+route});await wait('document.readyState==="complete" && !!document.querySelector(".wrap")',route);};
        await navigate('user-edit.php?user_id='+fixture.id);
        await wait('!!document.querySelector(".adc-permission-filters")','Permission filters');
        check(await evaluate('!!document.querySelector(".notice-warning")'),'Profile warns about missing branch');
        check(await evaluate('(() => {const f=document.querySelector(".adc-user-permissions .adc-permission-filters select");f.value="custom";f.dispatchEvent(new Event("change"));return [...document.querySelectorAll(".adc-permission-grid > label")].filter(l=>!l.hidden).length===1})()'),'Customized filter shows only individual override');
        await navigate('admin.php?page=adc-access-review&user_id='+fixture.id);
        check(await evaluate('document.querySelectorAll(".adc-permission-report tbody tr").length>=60'),'Effective report covers all dealership permissions');
        check(await evaluate('document.querySelectorAll(".adc-access-operation").length===3'),'Account report includes suspension, sessions and temporary grants');
        const bad=await evaluate('(() => {const f=document.querySelector(".adc-access-operation");const d=new FormData(f);d.set("_wpnonce","bad");d.set("reason","Invalid nonce test");return fetch(f.getAttribute("action"),{method:"POST",body:d}).then(r=>r.status)})()');
        check(bad===403,'Access operation rejects invalid nonce');
        await evaluate('(() => {const f=[...document.querySelectorAll(".adc-access-operation")].find(f=>f.querySelector("[name=operation]").value==="grant");f.querySelector("[name=cap]").value="adc_view_inventory";f.querySelector("[name=expires]").value='+JSON.stringify(fixture.expires)+';f.querySelector("[name=reason]").value="Temporary browser acceptance";f.requestSubmit()})()');
        await wait('document.readyState==="complete" && document.querySelectorAll(".adc-access-operation").length===4','Temporary grant saved');
        check(await evaluate('Object.values([...document.querySelectorAll(".adc-permission-report tr")]).some(r=>r.cells[1]?.textContent.includes("'+ '\u0645\u0646\u062d \u0645\u0624\u0642\u062a' +'"))'),'Temporary source is shown in Arabic');
        await evaluate('(() => {const f=[...document.querySelectorAll(".adc-access-operation")].find(f=>f.querySelector("[name=operation]").value==="suspend");f.querySelector("[name=reason]").value="Suspend browser acceptance";f.requestSubmit()})()');
        await wait('document.readyState==="complete" && !!document.querySelector("input[name=operation][value=resume]")','Suspension saved');
        check(await evaluate('[...document.querySelectorAll(".adc-permission-report tbody tr")].every(r=>r.dataset.granted==="0")'),'Suspended report denies all capabilities');
        await evaluate('(() => {const f=[...document.querySelectorAll(".adc-access-operation")].find(f=>f.querySelector("[name=operation]").value==="resume");f.querySelector("[name=reason]").value="Resume browser acceptance";f.requestSubmit()})()');
        await wait('document.readyState==="complete" && !!document.querySelector("input[name=operation][value=suspend]")','Resume saved');
        await navigate('admin.php?page=adc-roles&role='+fixture.role);
        await wait('!!document.querySelector("[name=clone_source]")','Role manager');
        check(await evaluate('(() => {const s=document.querySelector("[name=clone_source]");s.value="dealership_inventory";s.dispatchEvent(new Event("change"));return [...s.closest("form").querySelectorAll("input[type=checkbox]")].filter(i=>i.checked).length===6})()'),'Clone copies scoped role defaults');
        await evaluate('(() => {const f=document.querySelector("form.adc-user-permissions");f.querySelector("[value=adc_view_inventory]").checked=true;f.querySelector("[name=reason]").value="Browser reviewed role change";f.requestSubmit()})()');
        await wait('location.search.includes("preview=") && document.readyState==="complete" && !!document.querySelector("[name=revision]")','Impact preview');
        check(await evaluate('document.querySelector(".widefat tbody").textContent.includes("Access governance browser")'),'Impact preview names affected users');
        const stale=await evaluate('Array.from(new FormData(document.querySelector("form[action*=admin-post]")))');
        await evaluate('document.querySelector("form[action*=admin-post]").requestSubmit()');
        await wait('!location.search.includes("preview=") && document.readyState==="complete" && !!document.querySelector("[name=clone_source]")','Reviewed role save');
        check(await evaluate('document.querySelector("form.adc-user-permissions [value=adc_view_inventory]").checked'),'Reviewed change persists');
        const replay=await evaluate('fetch('+JSON.stringify(root+'/wp-admin/admin-post.php')+',{method:"POST",body:new URLSearchParams('+JSON.stringify(stale)+')}).then(r=>r.status)');
        check(replay===409,'Replaying stale preview is rejected');
        await evaluate('(() => {const f=document.querySelector("form.adc-user-permissions");f.querySelector("[name=reason]").value="Restore original role";f.requestSubmit(f.querySelector("[value=restore]"))})()');
        await wait('location.search.includes("preview=") && document.readyState==="complete" && !!document.querySelector("[name=revision]")','Restore preview');
        await evaluate('document.querySelector("form[action*=admin-post]").requestSubmit()');
        await wait('!location.search.includes("preview=") && document.readyState==="complete" && !!document.querySelector("[name=clone_source]")','Restore saved');
        check(await evaluate('!document.querySelector("form.adc-user-permissions [value=adc_view_inventory]").checked'),'Restore returns original default permissions');
        await evaluate('(() => {const f=document.querySelector("form.adc-user-permissions");f.querySelector("[name=target]").value="dealership_inventory";f.querySelector("[name=reason]").value="Retire browser fixture role";f.requestSubmit(f.querySelector("[value=delete]"))})()');
        await wait('location.search.includes("preview=") && document.readyState==="complete" && !!document.querySelector("[name=revision]")','Deletion preview');
        await evaluate('document.querySelector("form[action*=admin-post]").requestSubmit()');
        await wait('location.search.includes("role=dealership_inventory") && document.readyState==="complete" && !!document.querySelector("[name=clone_source]")','Role transferred and deleted');
        check(await evaluate('!document.querySelector("select[name=role] option[value='+fixture.role+']")'),'Deleted role disappears from selectable roles');
        await navigate('admin.php?page=adc-access-review');
        check(await evaluate('!!document.querySelector(".widefat")'),'Account review dashboard renders');
        await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
        check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'Account review fits mobile viewport');
        check(errors.length===0,'No browser runtime exceptions');
        check(badAssets.length===0,'No failed browser assets');
        console.log('Access governance browser: '+passed+' checks passed.');
    } finally { if(socket)socket.close(); browser.kill(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
