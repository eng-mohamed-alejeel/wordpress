'use strict';

// Read-only public performance contract. It records the same routes before
// and after SEO performance work; it does not submit forms or mutate content.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');
const assert = require('node:assert/strict');

const origin = (process.env.ADC_TEST_ORIGIN || 'http://localhost/wordpress').replace(/\/$/, '');
assert.match(origin, /^https?:\/\/[^/]+\/wordpress$/);
const outputFile = path.resolve(process.env.ADC_PUBLIC_PERF_OUTPUT || 'docs/artifacts/public-seo-performance-1.66.0/baseline.json');
const routes = [
    '/',
    '/cars/',
    '/cars/car-43/',
    '/offers/',
    '/offers/offer-111/',
    '/finance/',
    '/contact/'
];
const languages = ['ar', 'en'];
const viewports = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'mobile', width: 390, height: 844 }
];

const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const round = value => Number.isFinite(value) ? Math.round(value * 100) / 100 : null;

(async () => {
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'adc-public-perf-'));
    const browser = spawn(process.env.ADC_BROWSER || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', [
        '--headless=new', '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=0',
        '--no-first-run', '--disable-background-networking', '--disable-sync',
        '--user-data-dir=' + profile, 'about:blank'
    ], { windowsHide: true, stdio: 'ignore' });
    let socket;
    const cases = [];
    try {
        const active = path.join(profile, 'DevToolsActivePort');
        for (let i = 0; i < 200 && !fs.existsSync(active); i++) await pause(100);
        assert.ok(fs.existsSync(active), 'Browser must start.');
        const port = Number(fs.readFileSync(active, 'utf8').split('\n')[0]);
        const targets = await (await fetch('http://127.0.0.1:' + port + '/json/list')).json();
        const pageTarget = targets.find(target => target.type === 'page');
        assert.ok(pageTarget, 'Browser page target must be available.');
        socket = new WebSocket(pageTarget.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });

        let sequence = 0;
        const pending = new Map();
        const call = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++sequence;
            const timer = setTimeout(() => { pending.delete(id); reject(new Error('CDP timeout: ' + method)); }, 15000);
            pending.set(id, { resolve, reject, timer });
            socket.send(JSON.stringify({ id, method, params }));
        });
        socket.onmessage = event => {
            const data = JSON.parse(event.data);
            const request = pending.get(data.id);
            if (!request) return;
            clearTimeout(request.timer);
            pending.delete(data.id);
            data.error ? request.reject(new Error(data.error.message)) : request.resolve(data.result);
        };
        await call('Page.enable');
        await call('Runtime.enable');
        await call('Network.enable');
        await call('Network.setCacheDisabled', { cacheDisabled: true });
        const evaluate = async expression => {
            const result = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
            if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
            return result.result.value;
        };
        const wait = async () => {
            for (let i = 0; i < 120; i++) {
                if (await evaluate('document.readyState === "complete" && !!document.body')) return;
                await pause(100);
            }
            throw new Error('Timed out waiting for document.');
        };
        for (const language of languages) {
            for (const viewport of viewports) {
                await call('Emulation.setDeviceMetricsOverride', { width: viewport.width, height: viewport.height, deviceScaleFactor: 1, mobile: viewport.width <= 390 });
                for (const route of routes) {
                    const url = origin + route + (route.includes('?') ? '&' : '?') + 'lang=' + language;
                    const started = Date.now();
                    try {
                        await call('Page.navigate', { url });
                        await wait();
                        const state = await evaluate(`(function(){
                            var nav=performance.getEntriesByType('navigation')[0];
                            var paints=performance.getEntriesByType('paint');
                            var fcp=paints.find(function(entry){return entry.name==='first-contentful-paint';});
                            var resources=performance.getEntriesByType('resource');
                            var transfer=resources.reduce(function(sum,entry){return sum+(entry.transferSize||0);},0);
                            var decoded=resources.reduce(function(sum,entry){return sum+(entry.decodedBodySize||0);},0);
                            var types={}; resources.forEach(function(entry){var key=entry.initiatorType||'other';types[key]=(types[key]||0)+(entry.transferSize||0);});
                            return {status:document.title && document.body ? 200 : 0, navigation:nav?{ttfb:nav.responseStart-nav.requestStart,dom:nav.domContentLoadedEventEnd,load:nav.loadEventEnd}: {}, fcp:fcp?fcp.startTime:null, transfer:transfer,decoded:decoded,types:types,resources:resources.length,dom:document.querySelectorAll('*').length,html:new TextEncoder().encode(document.documentElement.outerHTML).length,styles:document.querySelectorAll('link[rel="stylesheet"],style').length,scripts:document.scripts.length,images:document.images.length,lazyImages:Array.from(document.images).filter(function(image){return image.loading==='lazy';}).length,scroll:document.documentElement.scrollWidth,viewport:innerWidth,heroPreload:!!document.querySelector('link[rel="preload"][as="image"]'),heroPriority:!!document.querySelector('img[fetchpriority="high"]')};
                        })()`);
                        const vitals = await evaluate(`(function(){
                            return new Promise(function(resolve){
                                var lcp=0;
                                try { new PerformanceObserver(function(list){ var entries=list.getEntries(); if(entries.length) lcp=entries[entries.length-1].startTime; }).observe({type:'largest-contentful-paint', buffered:true}); } catch(e) {}
                                setTimeout(function(){ resolve({lcp:lcp || null}); }, 250);
                            });
                        })()`);
                        const item = { route, language, viewport: viewport.name, width: viewport.width, url, status: 'ok', elapsed_wall_ms: Date.now() - started, metrics: {
                            ttfb: round(state.navigation.ttfb), dom_content_loaded: round(state.navigation.dom), load: round(state.navigation.load), first_contentful_paint: round(state.fcp), largest_contentful_paint: round(vitals.lcp), transfer_bytes: state.transfer, decoded_bytes: state.decoded, resource_bytes_by_type: state.types, resource_count: state.resources, dom_nodes: state.dom, html_bytes: state.html, stylesheet_count: state.styles, script_count: state.scripts, image_count: state.images, lazy_image_count: state.lazyImages, overflow_px: state.scroll - state.viewport, hero_preload: state.heroPreload, hero_priority_image: state.heroPriority
                        }};
                        if (item.metrics.overflow_px > 2) item.status = 'failed';
                        cases.push(item);
                        process.stdout.write(`${item.status.toUpperCase()} ${language}/${viewport.width} ${route} transfer=${item.metrics.transfer_bytes} resources=${item.metrics.resource_count}\n`);
                    } catch (error) {
                        cases.push({ route, language, viewport: viewport.name, width: viewport.width, url, status: 'failed', elapsed_wall_ms: Date.now() - started, errors: [error.message] });
                        process.stdout.write(`FAILED ${language}/${viewport.width} ${route} ${error.message}\n`);
                    }
                }
            }
        }
        const aggregate = values => {
            const valid = values.filter(value => Number.isFinite(value)).sort((a,b)=>a-b);
            if (!valid.length) return null;
            return round(valid[Math.floor(valid.length / 2)]);
        };
        const summary = { total_cases: cases.length, successful_cases: cases.filter(item => item.status === 'ok').length, failed_cases: cases.filter(item => item.status !== 'ok').length, median_transfer_bytes: aggregate(cases.map(item => item.metrics && item.metrics.transfer_bytes)), median_resource_count: aggregate(cases.map(item => item.metrics && item.metrics.resource_count)), median_fcp_ms: aggregate(cases.map(item => item.metrics && item.metrics.first_contentful_paint)), median_lcp_ms: aggregate(cases.map(item => item.metrics && item.metrics.largest_contentful_paint)) };
        fs.mkdirSync(path.dirname(outputFile), { recursive: true });
        fs.writeFileSync(outputFile, JSON.stringify({ generated_at_utc: new Date().toISOString(), origin, routes, languages, viewports, summary, cases }, null, 2) + '\n');
        process.stdout.write(`Public performance baseline written to ${outputFile}\n`);
        if (summary.failed_cases) process.exitCode = 1;
        await call('Browser.close').catch(() => {});
    } finally {
        if (socket) socket.close();
        if (browser.exitCode === null) browser.kill();
        await pause(500);
        const resolved = path.resolve(profile);
        if (path.dirname(resolved) === path.resolve(os.tmpdir()) && path.basename(resolved).startsWith('adc-public-perf-')) {
            try { fs.rmSync(resolved, { recursive: true, force: true }); } catch {}
        }
    }
})().catch(error => { console.error(error.stack || error.message); process.exitCode = 1; });
