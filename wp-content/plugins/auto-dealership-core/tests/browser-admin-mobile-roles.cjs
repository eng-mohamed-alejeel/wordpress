'use strict';
// Real-browser mobile acceptance for every dealership role with a dashboard scope.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');

(async () => {
    const origin = process.env.ADC_SITE_ORIGIN || 'http://localhost/wordpress';
    assert.match(origin, /^http:\/\/(?:localhost|127\.0\.0\.1)(?::\d+)?\/wordpress\/?$/);
    const root = origin.replace(/\/$/, '');
    const profileRoot = path.resolve(__dirname, '../../../../.tmp/browser-profiles');
    const captures = path.resolve(__dirname, '../../../../.tmp/dashboard-acceptance');
    const fixturePath = path.resolve(__dirname, '../../../../.tmp/ad033-mobile-roles-fixture.json');
    fs.mkdirSync(profileRoot, { recursive: true });
    fs.mkdirSync(captures, { recursive: true });
    const profile = fs.mkdtempSync(path.join(profileRoot, 'adc-ad033-'));
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
    const check = (value, description) => { assert.ok(value, description); passed++; process.stdout.write(`PASS ${description}\n`); };
    const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
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
        const wait = async expression => {
            for (let i = 0; i < 120; i++) {
                try { if (await evaluate(expression)) return; } catch {}
                await pause(100);
            }
            throw new Error(`Timed out at ${await evaluate('location.href')}: ${await evaluate('document.body.innerText.slice(0,1200)')}`);
        };
        const layout = async (role, width, route) => {
            await call('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: true });
            await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
            const result = await evaluate(`(() => {
                const visible = e => { const r=e.getBoundingClientRect(); return r.width>0 && r.height>0; };
                const interactive = [...document.querySelectorAll('.wrap a,.wrap button,.wrap input:not([type=hidden]),.wrap select,.wrap textarea')].filter(visible);
                const clipped = interactive.filter(e => { const r=e.getBoundingClientRect(); const scrollRegion=e.closest('.adc-crm-board-track,.adc-table-scroll,.adc-policy-table-scroll,.adc-work-table,.adc-inventory-table-scroll,[role=region],table.widefat'); return !scrollRegion && (r.left < -1 || r.right > innerWidth + 1); }).map(e => e.textContent.trim() || e.getAttribute('name') || e.getAttribute('href'));
                return { overflow: document.documentElement.scrollWidth-innerWidth, clipped, body: document.body.innerText.slice(0, 3000), wrap: !!document.querySelector('#wpbody-content .wrap') };
            })()`);
            check(result.wrap, `${role} renders ${route} at ${width}px`);
            check(result.overflow <= 2, `${role} ${route} fits ${width}px; overflow=${result.overflow}`);
            check(result.clipped.length === 0, `${role} ${route} has no clipped controls at ${width}px ${JSON.stringify(result.clipped)}`);
            const issue = result.body.match(/Fatal error[^\\n]*|Parse error[^\\n]*|Warning:[^\\n]*|Access denied[^\\n]*|ليست لديك صلاحية[^\\n]*|غير مسموح[^\\n]*/i);
            check(!issue, `${role} ${route} has no server/access error at ${width}px${issue ? `: ${result.body.slice(Math.max(0, result.body.search(/Fatal error|Parse error|Warning:|Access denied|ليست لديك صلاحية|غير مسموح/i)), 1200)}` : ''}`);
            return result;
        };
        const visit = async (role, route, width = 320) => {
            await call('Page.navigate', { url: root + '/wp-admin/' + route });
            await wait('document.readyState === "complete" && !!document.querySelector("#wpbody-content .wrap")');
            return layout(role, width, route);
        };
        const unique = values => [...new Set(values.filter(Boolean))];
        const roleEntries = Object.entries(fixture.users).filter(([role]) => !process.env.ADC_MOBILE_ROLES || process.env.ADC_MOBILE_ROLES.split(',').includes(role));
        for (const [role, session] of roleEntries) {
            await call('Network.clearBrowserCookies');
            for (const cookie of session.cookies) await call('Network.setCookie', { name: cookie.name, value: cookie.value, url: root + '/', path: '/wordpress/', httpOnly: true, sameSite: 'Lax' });
            await visit(role, 'admin.php?page=adc-workspace', 390);
            const home = await evaluate(`(() => ({
                direction: document.querySelector('.adc-workspace')?.dir,
                areas: [...document.querySelectorAll('.adc-area-card')].map(a => { const u=new URL(a.href); return u.pathname.replace(/^\\/wordpress\\/wp-admin\\//, '') + u.search; }),
                text: document.body.innerText
            }))()`);
            check(home.direction === 'rtl', `${role} workspace keeps Arabic direction`);
            check(home.areas.length > 0, `${role} exposes only permitted mobile areas`);
            check(!/adc-area-(settings|audit)/.test(home.areas.join(' ')) || ['dealership_general_manager','dealership_auditor'].includes(role), `${role} does not expose unexpected restricted areas`);
            await call('Page.captureScreenshot', { format: 'png' }).then(r => fs.writeFileSync(path.join(captures, `ad033-${role}-workspace-390.png`), Buffer.from(r.data, 'base64')));
            for (const area of home.areas) {
                const areaResult = await visit(role, area, 320);
                const links = await evaluate(`(() => [...document.querySelectorAll('.adc-workspace-card')].map(a => { const u=new URL(a.href); return u.pathname.replace(/^\\/wordpress\\/wp-admin\\//, '') + u.search; }))()`);
                for (const route of unique(links)) await visit(role, route, 320);
                check(areaResult.overflow <= 2, `${role} area remains bounded at 320px`);
            }
        }
        check(errors.length === 0, `No browser runtime exceptions: ${errors.join('; ')}`);
        check(badAssets.length === 0, `No failed browser assets: ${badAssets.join('; ')}`);
        console.log(`AD-033 mobile role acceptance: ${passed} checks passed.`);
    } finally { if (socket) socket.close(); browser.kill(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
