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
        const session=JSON.parse(fs.readFileSync(authFile,'utf8'));
        for(const cookie of session.cookies) await call('Network.setCookie',{name:cookie.name,value:cookie.value,url:root+'/',path:'/wordpress/',httpOnly:true,sameSite:'Lax'});


        await call('Page.navigate',{url:root+'/wp-admin/admin.php?page=adc-car-publishing'});
        await wait('document.readyState === "complete" && !!document.querySelector(".adc-publishing")','Catalog fixture listing');
        const editorPost = Number(session.catalog_post_id);
        assert.ok(editorPost>0,'A current catalog vehicle is required for the editor acceptance fixture');
        for (const route of ['edit.php?post_type=car','edit-tags.php?taxonomy=car_brand&post_type=car','edit-tags.php?taxonomy=car_category&post_type=car','post.php?post='+editorPost+'&action=edit']) {
            await call('Page.navigate',{url:root+'/wp-admin/'+route});
            await wait('document.readyState === "complete" && !!document.querySelector(".adc-publishing")',route);
            check(await evaluate('location.href.includes("admin.php?page=adc-car-")'),'Legacy route opens the custom interface: '+route);
            check(await evaluate('!document.querySelector("#post,#poststuff,.block-editor")'),'No article editing screen: '+route);
            check(await evaluate('!!document.querySelector(".adc-publishing-header nav")'),'Catalog navigation is available: '+route);
        }
        check(await evaluate('document.querySelector("input[name^=\\"color_main[\\"]") && document.querySelector("input[name^=\\"color_galleries[\\"]") && document.querySelector(".onyx-variant-table")'),'Mapped model editor contains per-color main images, galleries and live Onyx variants');
        await evaluate('document.querySelector("input[value=private_transport]").click(); document.querySelector("input[value=commercial_transport]").click();');
        check(await evaluate('!document.querySelector("input[value=private_transport]").checked && document.querySelector("input[value=commercial_transport]").checked'),'Plate checkboxes remain mutually exclusive');
        await evaluate('document.querySelector("[data-select-images]").click()');
        await wait('!!document.querySelector(".media-modal")','Media library opens');
        check(await evaluate('!!document.querySelector(".media-modal .media-frame-content")'),'Dedicated photo picker opens WordPress media library');
        await evaluate('document.querySelector(".media-modal-close").click()');
        // Discard browser-only checkbox changes before navigating; never submit content.
        await evaluate('document.querySelector(".adc-publishing-form").dispatchEvent(new Event("submit"))');
        for (const width of [1440,768,390]) {
            await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
            await pause(150);
            check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'Editor fits viewport '+width+' '+JSON.stringify(await evaluate('({width:innerWidth,scroll:document.documentElement.scrollWidth,items:[...document.querySelectorAll(".adc-publishing *")].filter(e=>e.getBoundingClientRect().width>0&&!e.closest(".adc-table-scroll,.onyx-variant-scroll")&&(e.getBoundingClientRect().left<0||e.getBoundingClientRect().right>innerWidth+2)).slice(0,8).map(e=>({tag:e.tagName,cls:e.className,w:e.getBoundingClientRect().width}))})')));
            const capture=await call('Page.captureScreenshot',{format:'png'});
            fs.writeFileSync(path.join(captures,'vehicle-'+width+'.png'),Buffer.from(capture.data,'base64'));
        }
        await call('Page.navigate',{url:root+'/wp-admin/admin.php?page=adc-onyx'});
        await wait('document.readyState === "complete" && !!document.querySelector(".adc-onyx-stock")','Onyx inventory');
        check(await evaluate('document.querySelectorAll("input[value=adc_onyx_model]").length===0'),'All current model pages already have drafts or published pages');
        check(await evaluate('!!document.querySelector("input[value=adc_onyx_upload]") && !document.querySelector("select[name=mode],input[value=adc_onyx_apply],input[value=adc_onyx_drafts]")'),'Onyx provides one automatic final-stock upload without mode selection or manual approval');
        check(await evaluate('!!document.querySelector(".adc-onyx-summary a[href*=adc-car-publishing]")'),'Onyx links to the website vehicles workspace');
        check(await evaluate('![...document.links].some(a=>a.href.includes("post.php?post="))'),'Onyx does not duplicate the vehicle page list');
        await call('Page.navigate',{url:root+'/wp-admin/admin.php?page=adc-car-publishing'});
        await wait('document.readyState === "complete" && !!document.querySelector(".adc-publishing-filters")','Website vehicles');
        check(await evaluate('document.querySelectorAll(".adc-table-scroll thead th").length===7 && document.querySelectorAll(".adc-table-scroll tbody tr").length===20'),'Vehicle list shows quantity, colors and paginated rows');
        check(await evaluate('[...document.querySelectorAll(".adc-table-scroll tbody tr")].every(row=>{const edit=row.querySelector(".adc-car-row a"), form=row.querySelector("form"), id=form.querySelector("input[name=post_id]").value, links=[...row.querySelectorAll("a.button")];return new URL(edit.href).searchParams.get("post_id")===id && links.some(a=>a.href===edit.href) && links.some(a=>new URL(a.href).pathname.includes("/cars/")) && form.method==="post" && new URL(form.getAttribute("action"),location.href).pathname.endsWith("/admin-post.php") && form.querySelector("input[name=_wpnonce]").value && form.querySelector("input[name=revision]").value && form.querySelector("input[name=change]").value==="trash"})'),'Every vehicle row has matching edit identity, public URL and protected trash form');
        check(await evaluate('["brand","model","trim_name","model_year","availability"].every(n=>document.querySelector("[name="+n+"]"))'),'Source identity and availability filters are present');
        check(await evaluate('!!document.querySelector(".adc-car-row img") && [...document.querySelectorAll(".adc-pagination a")].some(a=>a.href.includes("paged=2"))'),'List uses images and valid page links');
        await call('Page.navigate',{url:root+'/wp-admin/admin.php?page=adc-car-publishing&brand='+encodeURIComponent('نيسان')+'&model='+encodeURIComponent('التيما')+'&availability=available'});
        await wait('document.readyState === "complete" && !!document.querySelector(".adc-publishing-filters")','Filtered website vehicles');
        check(await evaluate('document.querySelectorAll(".adc-car-row").length===2 && [...document.querySelectorAll(".adc-car-row")].every(e=>e.textContent.includes("نيسان التيما"))'),'Combined source and availability filters return the two Altima configurations');
        check(await evaluate('!document.querySelector("input[value=adc_onyx_link]")'),'Onyx upload screen does not expose operational linking');
        await call('Page.navigate',{url:root+'/wp-admin/admin.php?page=adc-inventory'});
        await wait('document.readyState === "complete" && !!document.querySelector(".adc-inventory-table")','Unified inventory');
        check(await evaluate('document.querySelector("h1").textContent.includes("إدارة السيارات") && document.querySelectorAll(".adc-inventory-table tbody tr").length===50 && document.querySelectorAll(".adc-inventory-table th").length===16'),'Vehicle management displays source stock, independent fields and 50 rows');
        check(await evaluate('["brand","model","trim_name","model_year","warehouse_name","completion","availability","conflicts","branch_id"].every(n=>document.querySelector("[name="+n+"]"))'),'Inventory filters cover source fields, branch, completion and conflicts');
        const complete=await evaluate('document.querySelector(".adc-inventory-table a[href*=source_id]").href');
        await call('Page.navigate',{url:complete});
        await wait('document.readyState === "complete" && !!document.querySelector("input[name=onyx_stock_id]").value','Source completion lookup');
        check(await evaluate('document.querySelector("input[name=stock_number]").value.startsWith("ONYX-") && document.querySelector("input[name=brand]").value && document.querySelector("input[name=retail_price]").value && document.querySelector("input[name=vin]").value===""'),'Completion preloads source identity and net price without inventing full VIN');
        await call('Page.navigate',{url:root+'/wp-admin/admin.php?page=adc-vehicle-specifications'});
        await wait('document.readyState === "complete" && !!document.querySelector("input[name=vehicle_id]")','Operational vehicle details');
        check(await evaluate('!!document.querySelector("input[name=vehicle_id]")'),'Operational vehicle workspace provides scoped record selection');
        for(const route of ['adc-inventory&legacy=1','adc-transfers&section=new','adc-inventory-identity&section=receipt','adc-inventory-identity&section=inspection','adc-vehicle-acquisition']){
            await call('Page.navigate',{url:root+'/wp-admin/admin.php?page='+route});
            await wait('document.readyState === "complete" && !!document.querySelector("table.widefat tbody")','Inventory state '+route);
            const emptyState = await evaluate('document.querySelector(".adc-inventory-state")?.textContent || ""');
            if (emptyState) {
                const phaseMessages = {
                    'adc-transfers&section=new':'لا توجد سيارات مؤهلة للتحويل حاليًا.',
                    'adc-inventory-identity&section=receipt':'لا توجد سيارات تنتظر توثيق الاستلام.',
                    'adc-inventory-identity&section=inspection':'لا توجد سيارات تنتظر الفحص.'
                };
                const pendingCompletion = emptyState.includes(String(session.source_stock_count)) && emptyState.includes('رقم الهيكل الكامل');
                const emptyPhase = phaseMessages[route] && emptyState.includes(phaseMessages[route]);
                check((pendingCompletion || emptyPhase) && await evaluate('!!document.querySelector(".adc-inventory-state a[href*=adc-inventory]")'),'Operational empty state explains pending completion or the empty workflow phase '+route+' '+emptyState);
            } else {
                check(await evaluate('[...document.querySelectorAll("table.widefat tbody form")].some(form=>form.querySelector("input[name=id],input[name=vehicle_id]")?.value>0 && !!form.querySelector("input[name=_wpnonce]"))'),'Populated operational view keeps scoped records and protected actions '+route);
            }
        }
        await call('Page.navigate',{url:root+'/wp-admin/admin.php?page=adc-inventory&new=1'});
        await wait('document.readyState === "complete" && !!document.querySelector("input[value=adc_create_vehicle]")','Standalone vehicle form');
        check(await evaluate('!document.querySelector("table,.adc-inventory-state")'),'Standalone new vehicle form has no misleading empty inventory');
        await call('Page.navigate',{url:root+'/wp-admin/edit.php?post_type=car_offer'});
        await wait('document.readyState === "complete" && !!document.querySelector(".adc-offer-publishing")','Custom offer list');
        check(await evaluate('location.search.includes("page=adc-offer-publishing") && !!document.querySelector(".adc-publishing-filters") && !document.querySelector("#posts-filter")'),'Legacy offer list opens the custom offer workspace');
        const existingOffer=await evaluate('[...document.querySelectorAll(".adc-car-row a")].map(a=>a.href)[0] || ""');
        if(existingOffer){const offerId=new URL(existingOffer).searchParams.get('post_id');await call('Page.navigate',{url:root+'/wp-admin/post.php?post='+offerId+'&action=edit'});await wait('document.readyState === "complete" && !!document.querySelector("input[name=new_price]")','Existing offer editor');check(await evaluate('!!document.querySelector(".adc-offer-publishing") && !document.querySelector("#poststuff,#editor")'),'Existing offer edit URL opens its dedicated editor');}
        await call('Page.navigate',{url:root+'/wp-admin/post-new.php?post_type=car_offer'});
        await wait('document.readyState === "complete" && !!document.querySelector("input[name=new_price]")','Custom new offer');
        check(await evaluate('location.search.includes("page=adc-offer-publishing") && ["car_id","old_price","new_price","monthly_payment","expires","description","thumbnail_id","status"].every(n=>document.querySelector("[name="+n+"]")) && !document.querySelector("#poststuff,#editor")'),'Legacy offer creation exposes dedicated vehicle, price, expiry, photo and publication fields');
        await evaluate('document.querySelector("[data-select-images]").click()');await wait('!!document.querySelector(".media-modal")','Offer media picker');check(await evaluate('!!document.querySelector(".media-modal")'),'Offer image uses the accessible WordPress media library');await evaluate('document.querySelector(".media-modal-close").click()');
        for(const width of [1440,768,390]){await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});await pause(150);check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'Offer editor fits viewport '+width);}
        check(errors.length===0,'No browser runtime exceptions');
        check(badAssets.length===0,'No missing JavaScript or CSS assets');
        console.log('Catalog publishing browser checks passed: '+passed);
    } finally {
        if(socket) socket.close();
        browser.kill();
    }
})().catch(error=>{console.error(error.message);process.exitCode=1;});
