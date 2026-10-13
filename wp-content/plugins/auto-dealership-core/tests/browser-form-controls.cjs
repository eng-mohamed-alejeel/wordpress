'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { output, launch, mode, axe } = require('./accessibility-browser.cjs');

const publicRoutes = process.env.ADC_FORM_PUBLIC_ROUTES ? JSON.parse(process.env.ADC_FORM_PUBLIC_ROUTES) : ['/', '/contact/', '/finance/', '/cars/', '/?cd_account=login', '/?cd_account=register', '/wp-login.php'];
const selectedShots = new Set(['/contact/', '/finance/', '/cars/', '/?cd_account=register']);
const publicWidths = process.env.ADC_FORM_PUBLIC_WIDTHS ? JSON.parse(process.env.ADC_FORM_PUBLIC_WIDTHS) : [320, 390, 768, 1440];
const adminWidths = process.env.ADC_FORM_ADMIN_WIDTHS ? JSON.parse(process.env.ADC_FORM_ADMIN_WIDTHS) : [390, 1440];
const publicAppearances = ['light', 'dark', 'system'];
const adminAppearances = ['light', 'dark'];
const checks = [];
const inventory = [];
const failures = [];
const scans = [];
const check = (pass, label, details = null) => {
  const item = { pass: !!pass, label, details };
  checks.push(item);
  if (!item.pass) failures.push(item);
  console.log(`${item.pass ? 'PASS' : 'FAIL'} ${label}${details ? ` ${JSON.stringify(details)}` : ''}`);
};

const auditControls = async (page, surface, width, appearance) => page.evaluate(({ surface, width, appearance }) => {
  const visible = element => {
    const style = getComputedStyle(element), rect = element.getBoundingClientRect();
    const closed = element.closest('details:not([open])');
    return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0 && !element.closest('[hidden], [aria-hidden="true"], #wpadminbar') && (!closed || element.matches('summary'));
  };
  const rgba = value => {
    const match = value.match(/[\d.]+/g);
    if (!match) return null;
    const values = match.map(Number);
    return { r: values[0], g: values[1], b: values[2], a: values.length > 3 ? values[3] : 1 };
  };
  const backdrop = element => {
    for (let node = element; node; node = node.parentElement) {
      const color = rgba(getComputedStyle(node).backgroundColor);
      if (color && color.a >= .99) return color;
    }
    return { r: 255, g: 255, b: 255, a: 1 };
  };
  const lum = color => {
    const channel = value => { value /= 255; return value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4; };
    return .2126 * channel(color.r) + .7152 * channel(color.g) + .0722 * channel(color.b);
  };
  const contrast = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); };
  const controls = [...document.querySelectorAll('input, select, textarea')].filter(control => visible(control) && !control.matches('[type="hidden"], [type="submit"], [type="button"], [type="reset"], [type="image"], .wp-editor-area, .wp-color-picker'));
  const results = controls.map((control, index) => {
    const style = getComputedStyle(control), rect = control.getBoundingClientRect();
    const type = control.matches('select') ? (control.multiple ? 'select-multiple' : 'select') : control.matches('textarea') ? 'textarea' : (control.type || 'text');
    const binary = ['checkbox', 'radio', 'range', 'color'].includes(type);
    const backgroundColor = style.backgroundColor, color = style.color, borderTopColor = style.borderTopColor;
    const bg = rgba(backgroundColor) || backdrop(control.parentElement);
    const around = backdrop(control.parentElement);
    const fg = rgba(color);
    const border = rgba(borderTopColor);
    const standardSelect = type === 'select' && !!control.closest('form') && !control.hasAttribute('size');
    const selectedText = standardSelect ? (control.selectedOptions[0]?.textContent || '').trim() : '';
    let selectedTextClipped = false;
    if (selectedText) {
      const canvas = document.createElement('canvas'), context = canvas.getContext('2d');
      context.font = `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
      const available = control.clientWidth - parseFloat(style.paddingInlineStart || 0) - parseFloat(style.paddingInlineEnd || 0) - 24;
      selectedTextClipped = context.measureText(selectedText).width > available;
    }
    const labelled = !!(control.labels?.length || control.getAttribute('aria-label') || control.getAttribute('aria-labelledby') || control.title);
    let focus = true;
    if (index < 12 && !control.disabled) {
      control.focus();
      const focused = getComputedStyle(control);
      focus = parseFloat(focused.outlineWidth) >= 2 && focused.outlineStyle !== 'none';
    }
    return {
      type, name: control.name || control.id || '', labelled, focus,
      height: rect.height, width: rect.width,
      standardSelect, selectedTextClipped, selectedTextExposed: !selectedTextClipped || control.title === selectedText,
      textContrast: !binary && fg && bg ? contrast(fg, bg) : null,
      borderContrast: !binary && border ? contrast(border, around) : null,
      overflow: !control.closest('.adc-table-scroll, .adc-finance-scroll, [data-horizontal-scroll]') && (rect.right > innerWidth + 2 || rect.left < -2),
      disabled: control.disabled
    };
  });
  return { surface, width, appearance, controls: results, documentOverflow: document.documentElement.scrollWidth > innerWidth + 2, documentWidth: document.documentElement.scrollWidth, viewportWidth: innerWidth };
}, { surface, width, appearance });

async function inspect(page, route, surface, widths, appearances, screenshotPrefix = '') {
  for (const width of widths) {
    await page.setViewportSize({ width, height: 1000 });
    await page.waitForTimeout(150);
    for (const appearance of appearances) {
      await mode(page, appearance);
      const result = await auditControls(page, surface, width, appearance);
      inventory.push({ route, ...result });
      const active = result.controls.filter(control => !control.disabled);
      check(result.controls.every(control => control.labelled), `${surface} controls have accessible names: ${route} ${width} ${appearance}`, result.controls.filter(control => !control.labelled));
      check(active.every(control => control.focus), `${surface} sampled controls have visible focus: ${route} ${width} ${appearance}`, active.filter(control => !control.focus));
      const overflowing = result.controls.filter(control => control.overflow);
      const fitsViewport = !overflowing.length && !result.documentOverflow;
      check(fitsViewport, `${surface} controls fit viewport: ${route} ${width} ${appearance}`, fitsViewport ? null : { documentWidth: result.documentWidth, viewportWidth: result.viewportWidth, controls: overflowing });
      check(result.controls.every(control => ['checkbox', 'radio', 'range', 'color', 'textarea', 'select-multiple'].includes(control.type) || control.height >= (width <= 782 ? 43.5 : 39.5)), `${surface} controls meet height target: ${route} ${width} ${appearance}`, result.controls.filter(control => !['checkbox', 'radio', 'range', 'color', 'textarea', 'select-multiple'].includes(control.type) && control.height < (width <= 782 ? 43.5 : 39.5)));
      check(result.controls.every(control => control.type !== 'select' || control.height <= 56), `${surface} single selects keep a standard control height: ${route} ${width} ${appearance}`, result.controls.filter(control => control.type === 'select' && control.height > 56));
      check(result.controls.every(control => control.textContrast === null || control.disabled || control.textContrast >= 4.45), `${surface} field text contrast passes: ${route} ${width} ${appearance}`, result.controls.filter(control => control.textContrast !== null && !control.disabled && control.textContrast < 4.45));
      check(result.controls.every(control => control.borderContrast === null || control.disabled || control.borderContrast >= 2.95), `${surface} field boundary contrast passes: ${route} ${width} ${appearance}`, result.controls.filter(control => control.borderContrast !== null && !control.disabled && control.borderContrast < 2.95));
      check(result.controls.every(control => !control.standardSelect || control.width <= 512.5), `${surface} select widths respect the 32rem cap: ${route} ${width} ${appearance}`, result.controls.filter(control => control.standardSelect && control.width > 512.5));
      check(result.controls.every(control => control.selectedTextExposed), `${surface} clipped select values remain discoverable: ${route} ${width} ${appearance}`, result.controls.filter(control => !control.selectedTextExposed));
      if (screenshotPrefix && appearance !== 'system') {
        await page.screenshot({ path: path.join(output, `${screenshotPrefix}-${width}-${appearance}.png`), fullPage: true, animations: 'disabled' });
      }
    }
  }
}

(async () => {
  const sessionPath = path.join(__dirname, '../../../..', '.tmp/dashboard-browser-session.json');
  const hasSession = fs.existsSync(sessionPath);
  const { browser, context, page } = await launch(false);
  try {
    for (const language of ['ar', 'en']) {
      for (const route of publicRoutes) {
        const url = new URL(`http://localhost/wordpress${route}`);
        if (language === 'en') url.searchParams.set('lang', 'en');
        await page.goto(url.href, { waitUntil: 'networkidle' });
        const screenshotPrefix = selectedShots.has(route) ? `fields-${language}-${route.replace(/[^a-z0-9]+/gi, '-') || 'home'}` : '';
        await inspect(page, route, `public-${language}`, publicWidths, publicAppearances, screenshotPrefix);
      }
    }

    await page.goto('http://localhost/wordpress/', { waitUntil: 'networkidle' });
    await page.locator('.site').evaluate(site => {
      const fixture = document.createElement('form');
      fixture.id = 'ab-field-fixture';
      fixture.style.cssText = 'box-sizing:border-box;display:grid;gap:16px;padding:16px;inline-size:calc(100vw - 32px);max-width:720px;margin:auto';
      fixture.innerHTML = `
        <label>Text<input name="fixture_text" required></label>
        <label>Email<input type="email" name="fixture_email" required></label>
        <label>Number<input type="number" name="fixture_number"></label>
        <label>Date<input type="date" name="fixture_date"></label>
        <label>Time<input type="time" name="fixture_time"></label>
        <label>Date and time<input type="datetime-local" name="fixture_datetime"></label>
        <label>Select<select name="fixture_select"><option>Option</option><option selected>Supplier ONYX-0123456789 — A deliberately long supplier name used to verify bounded select fields and full-value disclosure</option></select></label>
        <label>Multiple<select name="fixture_multiple" multiple><option>Option</option></select></label>
        <label>Textarea<textarea name="fixture_textarea"></textarea></label>
        <label>File<input type="file" name="fixture_file"></label>
        <label>Range<input type="range" name="fixture_range"></label>
        <label>Color<input type="color" name="fixture_color"></label>
        <label><input type="checkbox" name="fixture_checkbox" checked> Checkbox</label>
        <label><input type="radio" name="fixture_radio"> Radio</label>
        <label>Readonly<input name="fixture_readonly" value="Readonly" readonly></label>
        <label>Disabled<input name="fixture_disabled" value="Disabled" disabled></label>`;
      site.append(fixture);
    });
    for (const appearance of ['light', 'dark']) {
      await inspect(page, '#ab-field-fixture', 'fixture', publicWidths, [appearance]);
      const invalid = page.locator('[name="fixture_email"]');
      await invalid.fill('');
      await invalid.evaluate(control => control.reportValidity());
      check(await invalid.getAttribute('aria-invalid') === 'true', `fixture ${appearance} invalid control exposes aria-invalid`);
      const described = await invalid.getAttribute('aria-describedby');
      check(!!described && await page.locator(`#${described.split(' ').at(-1)}`).count() === 1, `fixture ${appearance} invalid message is associated`);
      await invalid.fill('valid@example.test');
      check(await invalid.getAttribute('aria-invalid') === null, `fixture ${appearance} clears invalid state after correction`);
      const longSelect = page.locator('[name="fixture_select"]');
      const selectState = await longSelect.evaluate(control => ({ width: control.getBoundingClientRect().width, title: control.title, text: control.selectedOptions[0].textContent.trim(), marked: control.hasAttribute('data-ab-long-options') }));
      check(selectState.width <= 512.5 && selectState.marked && selectState.title === selectState.text, `fixture ${appearance} long select is bounded and exposes its full value`, selectState);
    }

    if (hasSession && process.env.ADC_FORM_SKIP_ADMIN !== '1') {
      const session = JSON.parse(fs.readFileSync(sessionPath));
      await context.addCookies(session.cookies.map(cookie => ({ ...cookie, domain: 'localhost', path: '/wordpress/', httpOnly: true, sameSite: 'Lax' })));
      await page.goto('http://localhost/wordpress/wp-admin/', { waitUntil: 'networkidle' });
      const adminRoutes = process.env.ADC_FORM_ADMIN_ROUTES ? JSON.parse(process.env.ADC_FORM_ADMIN_ROUTES) : await page.locator('#adminmenu a[href*="page=adc-"]').evaluateAll(links => [...new Set(links.map(link => { const url = new URL(link.href); return url.pathname.replace(/^\/wordpress/, '') + url.search; }))]);
      if (!adminRoutes.includes('/wp-admin/')) adminRoutes.unshift('/wp-admin/');
      for (const route of adminRoutes) {
        await page.goto(`http://localhost/wordpress${route}`, { waitUntil: 'networkidle' });
        await inspect(page, route, 'admin', adminWidths, adminAppearances);
      }
      await page.goto('http://localhost/wordpress/wp-admin/admin.php?page=adc-finance-calculator', { waitUntil: 'networkidle' });
      await mode(page, 'light');
      const wpCheckbox = page.locator('.wrap input[type="checkbox"]').first();
      if (await wpCheckbox.count()) {
        await wpCheckbox.evaluate(control => { control.checked = true; });
        const checkboxState = await wpCheckbox.evaluate(control => {
          const style = getComputedStyle(control);
          const rgb = (style.backgroundColor.match(/[\d.]+/g) || []).map(Number);
          const channel = value => { value /= 255; return value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4; };
          const luminance = .2126 * channel(rgb[0] || 0) + .7152 * channel(rgb[1] || 0) + .0722 * channel(rgb[2] || 0);
          const whiteContrast = 1.05 / (luminance + .05);
          return { appearance: style.appearance, background: style.backgroundColor, whiteContrast };
        });
        check(checkboxState.appearance !== 'none' || checkboxState.whiteContrast >= 3, 'WordPress light-mode checkbox tick has a contrasting checked surface', checkboxState);
      } else {
        check(false, 'WordPress light-mode checkbox fixture is available');
      }
    }

    await context.clearCookies();
    for (const route of ['/contact/', '/finance/', '/?cd_account=register']) {
      await page.goto(`http://localhost/wordpress${route}`, { waitUntil: 'networkidle' });
      scans.push({ route, ...await axe(page) });
    }
    check(scans.every(scan => !scan.violations.length), 'representative form pages pass axe', scans.flatMap(scan => scan.violations));
    fs.writeFileSync(path.join(output, 'form-controls.json'), JSON.stringify({ checks, inventory, scans, failures }, null, 2));
    if (failures.length) process.exitCode = 1;
  } finally {
    fs.writeFileSync(path.join(output, 'form-controls.json'), JSON.stringify({ checks, inventory, scans, failures }, null, 2));
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
