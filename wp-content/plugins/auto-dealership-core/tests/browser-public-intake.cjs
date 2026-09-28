/* Run only on request: node tests/browser-public-intake.cjs [Edge/Chrome executable].
 * Uses a fresh browser profile and about:blank; no site/database is loaded.
 */
'use strict';
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');
const assert = require('node:assert/strict');

(async () => {
    const executable = process.argv[2] || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
    assert.ok(fs.existsSync(executable), 'Pass an installed Chromium browser executable.');
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'adc-browser-'));
    const browser = spawn(executable, ['--headless=new', '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=0', '--no-first-run', '--disable-background-networking', '--disable-sync', '--disable-component-update', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
    let browserErrors = '';
    browser.stderr.on('data', chunk => { browserErrors = (browserErrors + chunk.toString()).slice(-2000); });
    let socket;
    try {
        const deadline = Date.now() + 20000;
        const activePort = path.join(profile, 'DevToolsActivePort');
        while (!fs.existsSync(activePort) && Date.now() < deadline) { await new Promise(resolve => setTimeout(resolve, 100)); }
        assert.ok(fs.existsSync(activePort), `Headless browser debugging endpoint must start. Exit ${browser.exitCode}: ${browserErrors}`);
        const port = Number(fs.readFileSync(activePort, 'utf8').split('\n')[0]);
        const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        const target = targets.find(entry => entry.type === 'page');
        assert.ok(target, 'Browser page must be available.');
        socket = new WebSocket(target.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
        let sequence = 0;
        const pending = new Map();
        socket.onmessage = event => {
            const message = JSON.parse(event.data);
            const request = pending.get(message.id);
            if (!request) return;
            clearTimeout(request.timer); pending.delete(message.id);
            message.error ? request.reject(new Error(message.error.message)) : request.resolve(message.result);
        };
        const call = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++sequence;
            const timer = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 10000);
            pending.set(id, { resolve, reject, timer }); socket.send(JSON.stringify({ id, method, params }));
        });
        const source = fs.readFileSync(path.join(__dirname, '../assets/js/public-intake.js'), 'utf8');
        const result = await call('Runtime.evaluate', {
            returnByValue: true,
            expression: `(() => {
                document.body.innerHTML = '<form class="cd-ajax-form" data-action="car_dealer_contact"><input name="message" value="first"><input name="nonce" value="nonce-1"></form><form class="cd-ajax-form" data-action="car_dealer_booking"><input name="date" value="2030-01-01"></form><form id="unrelated"></form>';
                ${source}
                const forms = document.querySelectorAll('form');
                const seen = [];
                document.addEventListener('submit', event => { event.preventDefault(); seen.push(new FormData(event.target).get('idempotency_key')); });
                const submit = form => { form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); return seen.at(-1); };
                const first = submit(forms[0]);
                const retry = submit(forms[0]);
                forms[0].elements.nonce.value = 'nonce-2';
                const nonceRetry = submit(forms[0]);
                forms[0].elements.message.value = 'changed';
                const changed = submit(forms[0]);
                forms[0].reset();
                const resetCleared = !forms[0].querySelector('[name="idempotency_key"]');
                const reset = submit(forms[0]);
                const booking = submit(forms[1]);
                const unrelated = submit(forms[2]);
                return { first, retry, nonceRetry, changed, resetCleared, reset, booking, unrelated, fields: forms[0].querySelectorAll('[name="idempotency_key"]').length };
            })()`
        });
        assert.ok(!result.exceptionDetails, JSON.stringify(result.exceptionDetails));
        const value = result.result.value;
        let checks = 0;
        const check = (condition, message) => { assert.ok(condition, message); ++checks; console.log(`PASS: ${message}`); };
        check(/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(value.first), 'Capture handler provides a UUID v4 before bubble FormData serialization.');
        check(value.first === value.retry, 'Unchanged form retry retains its request key.');
        check(value.first === value.nonceRetry, 'Refreshing a nonce does not duplicate the request key.');
        check(value.changed !== value.first, 'Changed form content receives a fresh request key.');
        check(value.resetCleared && value.reset !== value.changed && value.reset !== value.first, 'Reset clears the prior key and starts a new request.');
        check(value.booking && value.booking !== value.reset, 'Booking form has an independent request key.');
        check(value.unrelated === null && value.fields === 1, 'Unrelated forms are ignored and no duplicate hidden fields are added.');
        console.log(`Completed ${checks} real Chromium DOM checks; no site or database contacted.`);
        await call('Browser.close').catch(() => {});
    } finally {
        if (socket) socket.close();
        if (browser.exitCode === null) browser.kill();
        // Keep only this disposable profile if the browser still owns files.
        await new Promise(resolve => setTimeout(resolve, 500));
        const resolved = path.resolve(profile);
        if (path.dirname(resolved) === path.resolve(os.tmpdir()) && path.basename(resolved).startsWith('adc-browser-')) {
            try { fs.rmSync(resolved, { recursive: true, force: true }); } catch { console.log(`Temporary browser profile retained: ${resolved}`); }
        }
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
