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

        for(const language of ['ar','en']) {
          await visit('/offers/?lang='+language);
          check(await evaluate('document.documentElement.dir === '+JSON.stringify(language==='en'?'ltr':'rtl')),'Offer archive direction '+language);
          check(await evaluate('document.querySelector("h1").textContent.includes('+JSON.stringify(language==='en'?'AUTO BRANDS Offers':'عروض أوتو براندز')+')'),'Offer archive heading '+language);
        }
        await visit('/');
        const main=await (await fetch(root+'/wp-content/themes/car-dealer/assets/js/main.js')).text();
        const start=main.indexOf("  document.querySelectorAll('[data-vehicle-row], [data-horizontal-row]')");
        const stop=main.indexOf("  document.querySelectorAll('.language-dropdown')",start);
        assert.ok(start>=0&&stop>start);
        const rail='<section class="container offer-rail" data-horizontal-row><div class="offer-rail-controls"><button data-scroll-direction="left">Left</button><button data-scroll-direction="right">Right</button></div><div class="offer-rail-track" data-horizontal-track tabindex="0">'+Array.from({length:8},(_,i)=>'<article class="offer-card"><div class="offer-content"><h3>Offer '+i+'</h3><p class="offer-price">85,000 SAR</p><div class="offer-card-actions"><a class="btn btn-primary" href="/wordpress/offers/test#offer-lead">Get offer</a><a class="btn btn-outline" href="/wordpress/offers/test">Details</a></div></div></article>').join('')+'</div></section>';
        await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
        for(const direction of ['rtl','ltr']) {
          await evaluate('document.body.innerHTML='+JSON.stringify(rail)+';document.documentElement.dir='+JSON.stringify(direction)+';');
          await evaluate(main.slice(start,stop));
          check(await evaluate('document.querySelector("[data-scroll-direction=left]").getBoundingClientRect().x < document.querySelector("[data-scroll-direction=right]").getBoundingClientRect().x'),'Left arrow stays on left and right arrow stays on right '+direction);
          for(const width of [1440,768,390]) {
            await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});await pause(200);
            check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'No page overflow '+direction+' '+width);
            const original=await evaluate('document.querySelector(".offer-card").getBoundingClientRect().width');
            const removed=await evaluate('(()=>{const track=document.querySelector(".offer-rail-track");window.offerRemoved=Array.from(track.children).slice(1);window.offerRemoved.forEach(c=>c.remove());return track.children.length})()');
            await pause(100);
            check(removed===1&&await evaluate('Math.abs(document.querySelector(".offer-card").getBoundingClientRect().width-'+original+')<1'),'Single offer keeps card width '+direction+' '+width);
            check(await evaluate('document.querySelector(".offer-card").getBoundingClientRect().width<=360'),'Card has bounded width '+direction+' '+width);
            await evaluate('window.offerRemoved.forEach(c=>document.querySelector(".offer-rail-track").append(c))');await pause(100);
            const ratio=await evaluate('document.querySelector(".offer-rail-track").clientWidth/document.querySelector(".offer-card").getBoundingClientRect().width');
            check(width===1440?ratio>=3&&ratio<3.5:width===768?ratio>=2&&ratio<2.5:ratio>=1&&ratio<1.3,'Responsive visible card count '+direction+' '+width);
          }
          const outward=direction==='rtl'?'left':'right',back=direction==='rtl'?'right':'left';
          await evaluate('document.querySelector("[data-scroll-direction='+outward+']").click()');await pause(250);
          check(await evaluate('Math.abs(document.querySelector(".offer-rail-track").scrollLeft)>10'),'Arrow moves offers '+direction);
          await evaluate('document.querySelector("[data-scroll-direction='+back+']").click()');await pause(250);
          check(await evaluate('Math.abs(document.querySelector(".offer-rail-track").scrollLeft)<5'),'Reverse arrow returns to start '+direction);
          check(await evaluate('document.querySelector("[data-scroll-direction='+back+']").disabled'),'Start boundary disables arrow '+direction);
        }
        check(errors.length===0,'No browser runtime exceptions');check(badAssets.length===0,'No missing scripts or styles');
        console.log('Offer card browser checks passed: '+passed);
    } finally { if(socket) socket.close(); browser.kill(); }
})().catch(error=>{console.error(error.message);process.exitCode=1;});
