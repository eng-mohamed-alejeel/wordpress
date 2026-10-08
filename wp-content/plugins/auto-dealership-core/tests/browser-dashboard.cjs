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
            throw new Error(`Timed out: ${description}; URL: ${await evaluate('location.href')}`);
        };
        const visit = async route => {
            await call('Page.navigate', { url: root + route });
            await wait("document.readyState === 'complete' && !!document.querySelector('#colophon')", route);
        };
        const session=JSON.parse(fs.readFileSync(authFile,'utf8'));
        for(const cookie of session.cookies) await call('Network.setCookie',{name:cookie.name,value:cookie.value,url:root+'/',path:'/wordpress/',httpOnly:true,sameSite:'Lax'});

        async function admin(route) {
          await call('Page.navigate',{url:root+'/wp-admin/'+route});
          await wait('document.readyState==="complete" && !!document.querySelector("#wpbody-content .wrap")',route);
          const state=await evaluate('({url:location.href,text:document.body.innerText,heading:document.querySelector(".wrap h1")?.innerText})');
          check(!/Fatal error|Warning:|Parse error|لا تملك صلاحية|لا توجد لديك صلاحية/.test(state.text),'Page renders: '+route);
          if (process.env.ADC_LAYOUT_AUDIT === '1') {
            if (process.env.ADC_LAYOUT_FIXTURE === '1' && await evaluate('document.body.classList.contains("adc-admin")')) {
              await evaluate(`(() => {
                const fixture=document.createElement('div');fixture.dataset.layoutFixture='true';
                fixture.innerHTML='<div class="adc-table-scroll"><table class="widefat"><tbody><tr><td>Layout review</td><td><form><label class="adc-field">Document reference with a deliberately long label<input name="layout_reference" value="Reference"></label><label class="adc-field">Condition<select name="layout_condition"><option>Good condition</option></select></label><label class="adc-field">Review date<input name="layout_date" type="date"></label><label class="adc-field">Notes<textarea name="layout_notes">Long notes for reviewing the action layout.</textarea></label><button type="button" class="button button-primary">Save changes</button></form></td></tr></tbody></table></div>';
                document.querySelector('#wpbody-content > .wrap').append(fixture);
              })()`);
            }
            if (process.env.ADC_LAYOUT_DIRECTION === 'ltr') await evaluate('document.querySelector("#wpbody-content > .wrap").dir="ltr"');
            for (const width of [1440, 768, 390]) {
              await call('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 783 });
              await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
              const layout = await evaluate(`(() => {
                const plugin = document.body.classList.contains('adc-admin');
                const visible = e => e.getBoundingClientRect().width > 0 && e.getBoundingClientRect().height > 0;
                const controls = [...document.querySelectorAll('.adc-admin .wrap input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="submit"]), .adc-admin .wrap select:not([multiple])')].filter(visible);
                const expected = parseFloat(getComputedStyle(document.body).getPropertyValue('--adc-control-height'));
                const buttons=[...document.querySelectorAll('.adc-admin .wrap .button')].filter(visible);
                const misaligned=buttons.filter(button => {
                  const parent=button.parentElement;
                  if (!['FORM','P'].includes(parent.tagName) || getComputedStyle(parent).display !== 'flex') return false;
                  const b=button.getBoundingClientRect();
                  return [...parent.children].some(child => {
                    const control=child.matches('input:not([type="hidden"]),select') ? child : child.matches('label') ? child.querySelector('input:not([type="checkbox"]):not([type="radio"]),select') : null;
                    if (!control || !visible(control)) return false;
                    const r=control.getBoundingClientRect();
                    return Math.min(r.bottom,b.bottom)-Math.max(r.top,b.top)>5 && Math.abs(r.bottom-b.bottom)>1;
                  });
                }).map(e=>e.textContent.trim() || e.value);
                return { plugin, native: document.body.classList.contains('adc-admin-native'), overflow: document.documentElement.scrollWidth - innerWidth,
                  shortButtons: buttons.filter(e=>e.getBoundingClientRect().height < expected-1).map(e=>e.textContent.trim() || e.value), misaligned,
                  wrongHeights: controls.filter(e => Math.abs(e.getBoundingClientRect().height - expected) > 1).map(e => ({name:e.name,height:e.getBoundingClientRect().height})),
                  clipped: controls.filter(e => { const r=e.getBoundingClientRect(); return !e.closest('.adc-table-scroll') && (r.left < -1 || r.right > innerWidth + 1); }).map(e=>e.name) };
              })()`);
              if (layout.plugin) {
                check(layout.overflow <= 2, 'Viewport fits '+width+': '+route+'; overflow='+layout.overflow);
                check(layout.wrongHeights.length === 0, 'Consistent controls '+width+': '+route+' '+JSON.stringify(layout.wrongHeights));
                check(layout.clipped.length === 0, 'Unclipped fields '+width+': '+route+' '+JSON.stringify(layout.clipped));
                check(layout.shortButtons.length === 0, 'Consistent buttons '+width+': '+route+' '+JSON.stringify(layout.shortButtons));
                check(layout.misaligned.length === 0, 'Aligned actions '+width+': '+route+' '+JSON.stringify(layout.misaligned));
              }
              if (layout.native) check(layout.overflow <= 2, 'Native content viewport fits '+width+': '+route+'; overflow='+layout.overflow);
              if (width !== 768 && /adc-(inventory-identity|suppliers|vehicle-issues|finance-calculator|settings)|post_type=car/.test(route)) {
                const name=route.replace(/[^a-z0-9-]/gi,'_');
                await call('Page.captureScreenshot',{format:'png'}).then(r=>fs.writeFileSync(path.join(captures,name+'-'+width+'.png'),Buffer.from(r.data,'base64')));
              }
            }
            await call('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
            await evaluate('document.querySelector("[data-layout-fixture]")?.remove()');
          }
          return state;
        }
        if (process.env.ADC_LAYOUT_ROUTES) {
          for (const route of process.env.ADC_LAYOUT_ROUTES.split(',')) await admin(route);
          check(errors.length===0,'No browser runtime exceptions');
          check(badAssets.length===0,'No failed JS/CSS responses');
          console.log('Dashboard focused layout acceptance: '+passed+' checks passed.');
          return;
        }
        const groups=['customers','inventory','purchasing','approvals','finance','delivery','content','marketing','reports','settings','audit'];
        await admin('admin.php?page=adc-workspace');
        check(await evaluate('document.querySelectorAll(".adc-workspace-card small").length>=27'),'Original workspace help descriptions preserved');
        check(await evaluate('document.querySelectorAll(".adc-workspace-section").length===11'),'Workspace has eleven distinct sections');
        const cards=await evaluate('[...document.querySelectorAll(".adc-workspace-card")].map(a=>a.href)');
        check(cards.length===new Set(cards).size,'Workspace destinations are unique');
        check(await evaluate('!document.querySelector("#adminmenu a[href*=car-dealer-settings]")'),'Duplicate appearance editor removed from sidebar');
        for(const group of groups) {
          await admin('admin.php?page=adc-area-'+group);
          check(await evaluate('document.querySelectorAll(".adc-workspace-section").length===1 && document.querySelectorAll(".adc-workspace-card").length>0'),'Group landing: '+group);
        }
        const fields={pricing:['vat_rate_bps','pricing_fee_amount','promotion_code','seller_name'],reservations:['reservation_hours','reservation_deposit_type'],branches:['default_branch_id','code'],delivery:['delivery_required_documents[]'],privacy:['privacy_retention_days'],catalog:['public_catalog_mode']};
        for(const [section,expected] of Object.entries(fields)) {
          await admin('admin.php?page=adc-settings&section='+section);
          const names=await evaluate('[...document.querySelectorAll("#wpbody-content .wrap input,#wpbody-content .wrap select,#wpbody-content .wrap textarea")].map(e=>e.name)');
          check(expected.every(name=>names.includes(name)),'Settings fields: '+section);
          for(const [other,list] of Object.entries(fields)) if(other!==section) check(!list.some(name=>names.includes(name)),'No '+other+' fields in '+section);
        }
        for(const section of ['receipt','inspection','locations']) {
          await admin('admin.php?page=adc-inventory-identity&section='+section);
          check(await evaluate('!document.querySelector("input[name=vin]")'),'VIN correction absent from '+section);
          check(await evaluate('document.querySelectorAll("table.widefat thead th").length===2'),'Focused inventory table: '+section);
        }
        await admin('admin.php?page=adc-vehicle-vin');
        check(await evaluate('![...document.querySelectorAll("input[name=action]")].some(e=>["adc_record_vehicle_receipt","adc_record_vehicle_inspection","adc_move_vehicle_location"].includes(e.value))'),'VIN page contains no intake or movement actions');
        for(const section of ['brands','locations']) {
          await admin('admin.php?page=adc-reference&section='+section);
          check(await evaluate('document.querySelectorAll("table.widefat").length===1'),'Reference domain separated: '+section);
        }
        for(const section of ['hold','maintenance']) {
          await admin('admin.php?page=adc-vehicle-issues&section='+section);
          check(await evaluate('document.querySelector("input[name=type]").value==='+JSON.stringify(section)),'Issue form fixed to '+section);
        }
        for(const section of ['discounts','sales']) {
          await admin('admin.php?page=adc-approvals&section='+section);
          check(await evaluate('document.querySelectorAll("table.widefat").length===1'),'Approval queue separated: '+section);
        }
        for(const route of ['adc-finance','adc-approvals&section=discounts','adc-approvals&section=sales','adc-vehicle-issues&section=hold','adc-vehicle-issues&section=maintenance']) {
          await admin('admin.php?page='+route);
          check(await evaluate('document.querySelectorAll(".adc-queue-search").length===1'),'One queue search form: '+route);
          await evaluate('document.querySelector(".adc-queue-search [name=search]").value="100%_";document.querySelector(".adc-queue-search").requestSubmit()');
          await wait('new URL(location.href).searchParams.get("search")==="100%_" && document.readyState==="complete"','Search submitted: '+route);
          check(await evaluate('document.querySelector(".adc-queue-search [name=search]").value==="100%_"'),'Search value preserved: '+route);
          check(await evaluate('!document.body.innerText.includes("Fatal error") && !document.body.innerText.includes("Warning:")'),'Search response renders: '+route);
        }
        const other=['adc-crm','car-dealer-messages','car-dealer-bookings','adc-quotes','adc-customer-identities','adc-inventory','adc-vehicle-specifications','adc-transfers','adc-transfer-queue','adc-vehicle-returns','adc-suppliers','adc-vehicle-acquisition','adc-finance','adc-payments','adc-refunds','adc-financial-export','adc-delivery','adc-editorial-setup','car-dealer-subscribers','adc-operational-reports','adc-finance-calculator','adc-catalog-cutover','adc-stored-translations','adc-audit','adc-public-security','adc-integrations','adc-outbox','adc-sale-cancellations'];
        for(const page of other) await admin('admin.php?page='+page);
        await admin('edit.php?post_type=car');
        check(await evaluate('!!document.querySelector("#adminmenu .wp-has-current-submenu a[href*=adc-area-content]")'),'Published cars highlight content menu');
        await admin('edit.php?post_type=car_offer');
        await admin('admin.php?page=adc-workspace');
        await call('Page.captureScreenshot',{format:'png'}).then(r=>fs.writeFileSync(path.join(captures,'workspace-desktop.png'),Buffer.from(r.data,'base64')));
        await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
        await admin('admin.php?page=adc-settings&section=reservations');
        check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'Mobile settings fit viewport');
        await call('Page.captureScreenshot',{format:'png'}).then(r=>fs.writeFileSync(path.join(captures,'settings-mobile.png'),Buffer.from(r.data,'base64')));
        await call('Page.navigate',{url:root+'/wp-admin/themes.php?page=car-dealer-settings'});
        await wait('location.pathname.endsWith("customize.php") && document.readyState==="complete"','Legacy appearance redirect');
        check(await evaluate('!!document.querySelector("#accordion-section-car_dealer_social")'),'Social settings have their own Customizer section');
        check(errors.length===0,'No browser runtime exceptions');
        check(badAssets.length===0,'No failed JS/CSS responses');
        console.log('Dashboard browser acceptance: '+passed+' checks passed.');
    } finally { if(socket)socket.close(); browser.kill(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
