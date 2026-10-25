'use strict';

// Verifies that admin components consume the live BrandPalette semantic tokens.
// The palette is overridden in the document only; no site option is saved.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '../../../..');
const origin = (process.env.ADC_SITE_ORIGIN || 'http://localhost/wordpress').replace(/\/$/, '');
const sessionFile = path.resolve(root, '.tmp/dashboard-browser-session.json');
const { chromium } = require(process.env.ADC_PLAYWRIGHT_MODULE || path.join(root, '.tmp/accessibility-tools/node_modules/playwright-core'));
const routes = [
  'adc-workspace',
  'adc-area-customers',
  'adc-area-settings',
  'adc-settings&section=pricing',
  'adc-crm',
  'adc-area-reports',
];
const viewports = [1440, 390];
const palettes = {
  light: {
    '--ab-bg': '#102030', '--ab-surface': '#243447', '--ab-soft': '#31465c', '--ab-text': '#F4F8FB', '--ab-heading': '#FFFFFF',
    '--ab-muted': '#C9D5DF', '--ab-border': '#6F8294', '--ab-control-border': '#AFC1D0', '--ab-action': '#FF7A18', '--ab-action-hover': '#FF9A45',
    '--ab-primary': '#112233', '--ab-link': '#D40000', '--ab-on-action': '#151515', '--ab-inverse': '#FFFFFF', '--ab-focus': '#FFEE00',
    '--ab-success': '#86EFAC', '--ab-success-bg': '#163B2B', '--ab-warning': '#FDE68A', '--ab-warning-bg': '#493A18',
    '--ab-error': '#FCA5A5', '--ab-error-bg': '#4A202C', '--ab-info': '#8BD0FF', '--ab-info-bg': '#1B3B55', '--ab-shadow': '0 4px 18px #00000040',
  },
  dark: {
    '--ab-bg': '#080D14', '--ab-surface': '#16212E', '--ab-soft': '#223347', '--ab-text': '#F1F6FA', '--ab-heading': '#FFFFFF',
    '--ab-muted': '#B8C6D5', '--ab-border': '#526B80', '--ab-control-border': '#94AFC4', '--ab-action': '#55B8FF', '--ab-action-hover': '#8BD0FF',
    '--ab-primary': '#0E1C2B', '--ab-link': '#FF6B6B', '--ab-on-action': '#051018', '--ab-inverse': '#FFFFFF', '--ab-focus': '#FFFF7A',
    '--ab-success': '#86EFAC', '--ab-success-bg': '#143829', '--ab-warning': '#FDE68A', '--ab-warning-bg': '#3B2B14',
    '--ab-error': '#FCA5A5', '--ab-error-bg': '#421F2B', '--ab-info': '#8BD0FF', '--ab-info-bg': '#1B304C', '--ab-shadow': '0 4px 18px #00000080',
  },
};
const rgb = hex => {
  const value = hex.replace('#', '');
  if (value.length !== 6) return hex;
  return `rgb(${parseInt(value.slice(0, 2), 16)}, ${parseInt(value.slice(2, 4), 16)}, ${parseInt(value.slice(4, 6), 16)})`;
};
const setPalette = palette => Object.fromEntries(Object.entries(palette).map(([key, value]) => [key, rgb(value)]));

(async () => {
  assert.ok(fs.existsSync(sessionFile), `Temporary dashboard session is missing: ${sessionFile}`);
  const session = JSON.parse(fs.readFileSync(sessionFile, 'utf8'));
  assert.ok(Array.isArray(session.cookies) && session.cookies.length > 0, 'Temporary dashboard session has no cookies.');
  for (const file of ['admin-tokens.css', 'admin-structure.css']) {
    const css = fs.readFileSync(path.join(root, 'wp-content/plugins/auto-dealership-core/assets/css', file), 'utf8');
    assert.doesNotMatch(css, /var\(--(?:adc|ab)-[\w-]+\s*,\s*#[0-9a-f]{3,8}\)/i, `${file} has no fixed color fallbacks inside semantic tokens`);
  }

  const browser = await chromium.launch({ executablePath: process.env.ADC_BROWSER || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  let checks = 0;
  const check = (condition, message) => { assert.ok(condition, message); checks++; console.log(`PASS ${message}`); };
  try {
    for (const [appearance, palette] of Object.entries(palettes)) {
      for (const width of viewports) {
        const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
        await context.addCookies(session.cookies.map(cookie => ({ ...cookie, url: `${origin}/` })));
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        for (const route of routes) {
          await page.goto(`${origin}/wp-admin/admin.php?page=${route}`, { waitUntil: 'load', timeout: 30000 });
          await page.waitForTimeout(100);
          await page.evaluate(async value => {
            if (window.autoBrandsAppearance) window.autoBrandsAppearance.select(value);
            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
          }, appearance);
          await page.evaluate(values => {
            for (const [name, value] of Object.entries(values)) document.documentElement.style.setProperty(name, value);
          }, setPalette(palette));
          await page.waitForTimeout(500);
          const state = await page.evaluate(() => {
            const style = selector => {
              const element = document.querySelector(selector);
              if (!element) return null;
              const computed = getComputedStyle(element);
              return { background: computed.backgroundColor, color: computed.color, border: computed.borderTopColor, outline: computed.outlineColor };
            };
            return {
              area: style('.adc-area-card'),
              icon: style('.adc-area-card > .dashicons'),
              scope: style('.adc-scope-badge'),
              intro: style('.adc-center-intro'),
              activeTab: style('.nav-tab-active'),
              primaryButton: style('.button-primary'),
              direction: getComputedStyle(document.documentElement).direction,
              tokens: { ink: getComputedStyle(document.body).getPropertyValue('--adc-ink').trim(), heading: getComputedStyle(document.body).getPropertyValue('--adc-heading').trim(), surface: getComputedStyle(document.body).getPropertyValue('--adc-surface').trim(), border: getComputedStyle(document.body).getPropertyValue('--adc-border').trim(), action: getComputedStyle(document.body).getPropertyValue('--adc-accent').trim() },
              overflow: document.documentElement.scrollWidth - innerWidth,
              fatal: /Fatal error|Parse error|Warning:/.test(document.body.innerText),
            };
          });
          if (state.area) {
            check(state.area.background === rgb(palette['--ab-surface']), `${appearance}/${width}/${route}: cards use customized surface`);
            check(state.area.color === rgb(palette['--ab-text']), `${appearance}/${width}/${route}: cards use customized text (${state.area.color}; token=${state.tokens.ink})`);
            check(state.area.border === rgb(palette['--ab-border']), `${appearance}/${width}/${route}: cards use customized border`);
          }
          if (state.icon) {
            check(state.icon.background === rgb(palette['--ab-soft']), `${appearance}/${width}/${route}: icons use customized soft surface`);
            check(state.icon.color === rgb(palette['--ab-action']), `${appearance}/${width}/${route}: icons use customized action color`);
          }
          if (state.scope) check(state.scope.background === rgb(palette['--ab-soft']) && state.scope.color === rgb(palette['--ab-text']), `${appearance}/${width}/${route}: scope badge follows palette`);
          if (state.intro) check(state.intro.border === rgb(palette['--ab-border']), `${appearance}/${width}/${route}: center intro follows palette border`);
          if (state.activeTab) check(state.activeTab.background === rgb(palette['--ab-primary']), `${appearance}/${width}/${route}: active tab follows palette primary`);
          if (state.primaryButton) check(state.primaryButton.background === rgb(palette['--ab-action']), `${appearance}/${width}/${route}: primary button follows palette action`);
          check(['ltr', 'rtl'].includes(state.direction), `${appearance}/${width}/${route}: direction remains defined`);
          check(state.overflow <= 2 && !state.fatal && errors.length === 0, `${appearance}/${width}/${route}: no layout or runtime regression`);
        }
        await context.close();
      }
    }
  } finally { await browser.close(); }
  console.log(`ADMIN COLOR CONTRACT PASS: ${checks} checks`);
})().catch(error => { console.error(error.stack || error.message); process.exitCode = 1; });
