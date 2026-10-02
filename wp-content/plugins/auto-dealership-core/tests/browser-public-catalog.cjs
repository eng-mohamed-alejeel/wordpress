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
    assert.ok(Number.isInteger(input.car_id) && input.car_id > 0, 'A mapped catalog post is required.');
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'adc-catalog-browser-'));
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
        console.log(`CATALOG BROWSER PASS: ${message}`);
    };
    try {
        const active = path.join(profile, 'DevToolsActivePort');
        for (let i = 0; i < 200 && !fs.existsSync(active); i++) await pause(100);
        assert.ok(fs.existsSync(active), 'Browser must start.');
        const port = Number(fs.readFileSync(active, 'utf8').split('\n')[0]);
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
        const artifacts = path.resolve(__dirname, '../../../../docs/artifacts/catalog');
        fs.mkdirSync(artifacts, { recursive: true });

        await navigate('/?post_type=car', '.ab-filter-authoritative');
        check(await evaluate("document.querySelector('.archive-content').lang === 'ar' && document.querySelector('.archive-content').dir === 'rtl' && getComputedStyle(document.querySelector('.archive-content')).direction === 'rtl'"), 'Authoritative catalog declares Arabic content and renders RTL.');
        check(await evaluate("document.querySelectorAll('.car-card').length === 1 && document.querySelector('.car-card').innerText.includes('Synthetic') && document.querySelector('.car-card').innerText.includes('2026')"), 'Archive card is sourced from the mapped operational vehicle.');
        check(await evaluate("Array.from(document.querySelectorAll('.ab-filter-authoritative input:not([type=hidden]),.ab-filter-authoritative select')).every(control => control.labels && control.labels.length > 0)"), 'Every authoritative catalog filter has an accessible label.');
        check(await evaluate("document.querySelector('[name=brand]').textContent.includes('Synthetic') && document.querySelector('[name=body_type]').textContent.includes('suv') && document.querySelector('[name=branch_id]').options.length > 1"), 'Filter choices are populated from eligible operational inventory.');
        await evaluate("(() => { const form=document.querySelector('.ab-filter-authoritative'); form.elements.search.value='HTTP-201'; form.elements.brand.value='Synthetic'; form.requestSubmit(); })()");
        await wait("document.readyState === 'complete' && new URL(location.href).searchParams.get('post_type') === 'car' && new URL(location.href).searchParams.get('search') === 'HTTP-201' && !!document.querySelector('.car-card')", 'native catalog filter submission');
        check(await evaluate("document.querySelectorAll('.car-card').length === 1"), 'Native GET filter submission preserves the vehicle archive route.');

        const filterQuery = new URLSearchParams({
            post_type: 'car', brand: 'Synthetic', model: 'Catalog HTTP', min_year: '2026', max_year: '2026',
            min_price: '120000', max_price: '120000', min_mileage: '200', max_mileage: '300',
            body_type: 'suv', fuel_type: 'hybrid', transmission: 'automatic', engine_size: '2.0 L',
            drivetrain: 'awd', exterior_color: 'Silver', interior_color: 'Black', condition: 'new',
            branch_id: String(input.branch_id), search: 'HTTP-201', sort: 'price_asc'
        });
        await navigate('/?' + filterQuery.toString(), '.car-card');
        check(await evaluate("document.querySelectorAll('.car-card').length === 1 && document.querySelector('[name=search]').value === 'HTTP-201' && document.querySelector('[name=sort]').value === 'price_asc'"), 'Combined browser filters retain state and return the expected vehicle.');
        check(await evaluate("document.querySelector('meta[name=robots]')?.content.includes('noindex') && document.querySelector('meta[name=robots]')?.content.includes('follow')"), 'Filtered catalog pages emit noindex and follow robots directives.');
        check(await evaluate("(() => { const canonical=document.querySelector('link[rel=canonical]'); return canonical && new URL(canonical.href).searchParams.get('post_type') === 'car' && !new URL(canonical.href).searchParams.has('brand'); })()"), 'Filtered catalog canonical points to the unfiltered archive.');

        await navigate('/?post_type=car&search=NO-MATCH-120', '.empty-state');
        check(await evaluate("document.querySelectorAll('.car-card').length === 0 && document.querySelector('.empty-state').textContent.trim().length > 0"), 'No-result filters render the catalog empty state without stale cards.');

        await navigate('/?post_type=car&p=' + input.car_id, '.car-single-price');
        check(await evaluate("document.querySelector('.car-single-price').textContent.includes('120,000') && document.querySelector('.car-specification').innerText.includes('HTTP-201') && document.querySelector('.car-specification').innerText.includes('Catalog HTTP')"), 'Vehicle detail uses operational price, stock number and model data.');
        const schema = await evaluate("(() => { const nodes=Array.from(document.querySelectorAll('script[type=\"application/ld+json\"]')).map(node => { try { return JSON.parse(node.textContent); } catch { return null; } }); return nodes.find(node => node && node['@type'] === 'Vehicle'); })()");
        check(schema && schema.brand.name === 'Synthetic' && schema.model === 'Catalog HTTP' && schema.sku === 'HTTP-201' && schema.offers.price === 120000, 'Vehicle structured data reflects the public operational read model.');
        const serializedSchema = JSON.stringify(schema);
        check(!serializedSchema.includes('3M8GDM9AXKP000201') && !serializedSchema.includes('purchase_cost') && !serializedSchema.includes('minimum_price'), 'Vehicle page and structured data do not expose private inventory fields.');

        await navigate('/?post_type=car', '.ab-filter-authoritative');
        for (const width of [1440, 768, 390, 320]) {
            await call('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width <= 390 });
            await pause(150);
            check(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), `Catalog fits viewport ${width}px without document overflow.`);
            const screenshot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
            fs.writeFileSync(path.join(artifacts, `catalog-${width}.png`), Buffer.from(screenshot.data, 'base64'));
        }
        await call('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
        await call('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
        await evaluate("document.querySelector('.ab-filter-authoritative input:not([type=hidden])').focus()");
        check(await evaluate("(() => { const style=getComputedStyle(document.activeElement); return style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0; })()"), 'Keyboard focus remains visibly indicated on catalog controls.');

		await call('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
		await navigate('/?post_type=car&lang=en', '.ab-filter-authoritative');
		check(await evaluate("document.documentElement.lang === 'en' && document.documentElement.dir === 'ltr' && getComputedStyle(document.documentElement).direction === 'ltr' && document.querySelector('.archive-content').lang === 'en'"), 'English catalog declares English and renders LTR at the document and content levels.');
		const englishArchiveCopy = await evaluate("(() => ({ heading: document.querySelector('.archive-hero h1')?.textContent.trim() || '', search: document.querySelector('[name=search]')?.labels?.[0]?.innerText.trim() || '', count: document.querySelector('.catalog-result-count')?.innerText.trim() || '' }))()");
		check(englishArchiveCopy.heading.includes('Browse AUTO BRANDS Vehicles') && englishArchiveCopy.search.includes('Search') && englishArchiveCopy.count.includes('matching vehicle'), `English archive heading, filters and result announcement are translated (${JSON.stringify(englishArchiveCopy)}).`);
		check(await evaluate("document.querySelector('.catalog-language-switch [lang=en]').getAttribute('aria-current') === 'page' && new URL(document.querySelector('.catalog-language-switch [lang=ar]').href).searchParams.get('lang') === null"), 'Catalog language switch exposes the active language and a clean Arabic URL.');
		check(await evaluate("document.querySelector('.car-price').textContent.startsWith('SAR ') && new URL(document.querySelector('.car-title a').href).searchParams.get('lang') === 'en' && !/[\u0600-\u06ff]/.test(document.querySelector('.archive-content').innerText)"), 'English cards retain language links, format SAR and contain no Arabic interface copy.');
		await evaluate("(() => { const form=document.querySelector('.ab-filter-authoritative'); form.elements.search.value='HTTP-201'; form.requestSubmit(); })()");
		await wait("document.readyState === 'complete' && new URL(location.href).searchParams.get('lang') === 'en' && new URL(location.href).searchParams.get('search') === 'HTTP-201' && !!document.querySelector('.car-card')", 'English native catalog filter submission');
		check(await evaluate("(() => { const canonical=new URL(document.querySelector('link[rel=canonical]').href); const alternates=Array.from(document.querySelectorAll('link[rel=alternate][hreflang]')).map(link=>link.hreflang); return canonical.searchParams.get('lang') === 'en' && !canonical.searchParams.has('search') && ['ar','en','x-default'].every(lang=>alternates.includes(lang)); })()"), 'English filtered URL preserves its language in canonical and complete hreflang alternates.');

		await navigate('/?post_type=car&p=' + input.car_id + '&lang=en', '.car-single');
		check(await evaluate("document.querySelector('.car-single').lang === 'en' && document.querySelector('.car-single').dir === 'ltr' && document.querySelector('.car-single-price').textContent.startsWith('SAR ') && document.querySelector('.car-single-actions').innerText.includes('Request price')"), 'English vehicle detail renders LTR with translated actions and SAR price.');
		check(await evaluate("document.querySelector('.car-specification').innerText.includes('Stock number') && document.querySelector('.car-specification').innerText.includes('Hybrid') && document.querySelector('.car-specification').innerText.includes('SUV') && document.querySelector('.cd-booking-form h2').innerText.includes('Book a test drive')"), 'English detail specifications and lead forms use translated public copy.');
		await evaluate("(() => { const form=document.querySelector('.cd-lead-form'); form.elements.name.value='English Catalog Visitor'; form.elements.email.value='catalog-en@example.test'; form.elements.phone.value='+966500000321'; form.requestSubmit(); })()");
		await wait("document.querySelector('.cd-lead-form .cd-form-status').innerText.includes('request has been received')", 'English catalog lead response');
		check(await evaluate("document.querySelector('.cd-lead-form .cd-form-status').innerText.includes('successfully')"), 'English catalog lead submission returns a localized success message.');
		const englishSchema = await evaluate("(() => Array.from(document.querySelectorAll('script[type=\"application/ld+json\"]')).map(node=>{try{return JSON.parse(node.textContent)}catch{return null}}).find(node=>node&&node['@type']==='Vehicle'))()");
		check(englishSchema && new URL(englishSchema.url).searchParams.get('lang') === 'en' && new URL(englishSchema.offers.url).searchParams.get('lang') === 'en', 'English Vehicle structured data uses the English public URL.');
		const accessibility = await evaluate(`(() => {
			const visible = element => !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
			const scope = document.querySelector('main');
			const controls = Array.from(scope.querySelectorAll('input:not([type=hidden]),select,textarea')).filter(visible);
			const unnamedControls = controls.filter(control => !(control.labels && control.labels.length) && !control.getAttribute('aria-label'));
			const unnamedActions = Array.from(scope.querySelectorAll('a,button')).filter(visible).filter(action => !(action.innerText.trim() || action.getAttribute('aria-label') || action.querySelector('img[alt]')));
			const ids = Array.from(document.querySelectorAll('[id]')).map(element => element.id).filter(Boolean);
			const duplicateIds = ids.filter((id, index) => ids.indexOf(id) !== index);
			const imagesWithoutAlt = Array.from(scope.querySelectorAll('img')).filter(image => !image.hasAttribute('alt'));
			return { mains: document.querySelectorAll('main').length, h1: scope.querySelectorAll('h1').length, unnamedControls: unnamedControls.length, unnamedActions: unnamedActions.length, duplicateIds: duplicateIds.length, imagesWithoutAlt: imagesWithoutAlt.length };
		})()`);
		check(accessibility.mains === 1 && accessibility.h1 === 1 && accessibility.unnamedControls === 0 && accessibility.unnamedActions === 0 && accessibility.duplicateIds === 0 && accessibility.imagesWithoutAlt === 0, 'English detail passes structural name, label, landmark, heading, ID and image-alt checks.');
		for (const width of [1440, 768, 390, 320]) {
			await call('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width <= 390 });
			await pause(150);
			check(await evaluate("document.documentElement.scrollWidth <= innerWidth + 1 && getComputedStyle(document.querySelector('.car-single')).direction === 'ltr'"), `English detail fits viewport ${width}px and remains LTR.`);
			const screenshot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
			fs.writeFileSync(path.join(artifacts, `catalog-en-${width}.png`), Buffer.from(screenshot.data, 'base64'));
		}
		const timing = await evaluate("(() => { const nav=performance.getEntriesByType('navigation')[0]; return { duration: nav.duration, dom: nav.domContentLoadedEventEnd, resources: performance.getEntriesByType('resource').filter(entry => entry.name.startsWith(location.origin)).length }; })()");
		check(timing.duration < 5000 && timing.dom < 4000 && timing.resources <= 40, `Synthetic English catalog meets local navigation budgets (${Math.round(timing.duration)}ms load, ${timing.resources} local resources).`);
        check(errors.length === 0, `No uncaught catalog JavaScript errors: ${errors.join(';')}`);
        check(badAssets.length === 0, `Catalog CSS and JavaScript assets load successfully: ${badAssets.join(';')}`);
        console.log(`Completed ${checks} real-theme catalog browser checks. Screenshots: ${artifacts}`);
        await call('Browser.close').catch(() => {});
    } finally {
        if (socket) socket.close();
        if (browser.exitCode === null) browser.kill();
        await pause(500);
        const resolved = path.resolve(profile);
        if (path.dirname(resolved) === path.resolve(os.tmpdir()) && path.basename(resolved).startsWith('adc-catalog-browser-')) {
            try { fs.rmSync(resolved, { recursive: true, force: true }); } catch {}
        }
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
