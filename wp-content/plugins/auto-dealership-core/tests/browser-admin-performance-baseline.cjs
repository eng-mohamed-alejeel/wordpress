'use strict';

// Read-only performance baseline for the administrative dashboard.
// Requires a temporary administrator session created by dashboard-browser-session.php.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '../../../..');
const origin = (process.env.ADC_SITE_ORIGIN || 'http://localhost/wordpress').replace(/\/$/, '');
assert.match(origin, /^http:\/\/(?:localhost|127\.0\.0\.1)(?::\d+)?\/wordpress$/);
const sessionFile = path.resolve(root, '.tmp/dashboard-browser-session.json');
const outputDirectory = path.resolve(root, 'docs/artifacts/admin-dashboard-performance-baseline-1.66.0');
const outputFile = process.env.ADC_PERF_OUTPUT_FILE
  ? path.resolve(root, process.env.ADC_PERF_OUTPUT_FILE)
  : path.join(outputDirectory, 'baseline.json');
const playwright = process.env.ADC_PLAYWRIGHT_MODULE || path.join(root, '.tmp/accessibility-tools/node_modules/playwright-core');
const { chromium } = require(playwright);

const routes = [
  'adc-workspace',
  'adc-area-customers',
  'adc-crm',
  'adc-area-inventory',
  'adc-onyx',
  'adc-area-purchasing',
  'adc-area-finance',
  'adc-onyx-documents',
  'adc-area-delivery',
  'adc-area-reports',
  'adc-operational-reports',
  'adc-crm-reports',
  'adc-area-settings',
  'adc-settings',
  'adc-roles',
  'adc-access-review',
];
const languages = [
  { code: 'ar', direction: 'rtl' },
  { code: 'en', direction: 'ltr' },
];
const viewports = [
  { width: 1440, height: 1000, name: 'desktop' },
  { width: 390, height: 1000, name: 'mobile' },
];

const round = value => Number.isFinite(value) ? Math.round(value * 100) / 100 : null;
const median = values => {
  const sorted = values.filter(Number.isFinite).sort((a, b) => a - b);
  if (!sorted.length) return null;
  const middle = Math.floor(sorted.length / 2);
  return round(sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2);
};
const percentile = (values, fraction) => {
  const sorted = values.filter(Number.isFinite).sort((a, b) => a - b);
  if (!sorted.length) return null;
  return round(sorted[Math.min(sorted.length - 1, Math.ceil(sorted.length * fraction) - 1)]);
};

const summarize = cases => {
  const metric = name => cases.map(item => item.metrics && item.metrics[name]);
  return {
    total_cases: cases.length,
    successful_cases: cases.filter(item => item.status === 'ok').length,
    failed_cases: cases.filter(item => item.status !== 'ok').length,
    pages: [...new Set(cases.map(item => item.route))],
    metrics_ms: Object.fromEntries(['ttfb', 'dom_content_loaded', 'load', 'first_contentful_paint'].map(name => [name, {
      median: median(metric(name)),
      p95: percentile(metric(name), 0.95),
    }])),
    transfer_bytes: {
      median: median(metric('transfer_bytes')),
      p95: percentile(metric('transfer_bytes'), 0.95),
    },
    resources: {
      median: median(metric('resource_count')),
      p95: percentile(metric('resource_count'), 0.95),
    },
  };
};

(async () => {
  const sessionFiles = {
    ar: process.env.ADC_PERF_AR_SESSION_FILE || sessionFile,
    en: process.env.ADC_PERF_EN_SESSION_FILE || sessionFile,
  };
  const sessions = {};
  for (const language of languages) {
    assert.ok(fs.existsSync(sessionFiles[language.code]), `Temporary ${language.code} dashboard session is missing: ${sessionFiles[language.code]}`);
    sessions[language.code] = JSON.parse(fs.readFileSync(sessionFiles[language.code], 'utf8'));
    assert.ok(Array.isArray(sessions[language.code].cookies) && sessions[language.code].cookies.length > 0, `Temporary ${language.code} dashboard session has no cookies.`);
  }

  const browser = await chromium.launch({
    executablePath: process.env.ADC_BROWSER || 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    headless: true,
  });
  const cases = [];
  try {
    for (const language of languages) {
      for (const viewport of viewports) {
        for (const route of routes) {
          const context = await browser.newContext({
            viewport: { width: viewport.width, height: viewport.height },
            locale: language.code === 'ar' ? 'ar' : 'en-US',
          });
          const page = await context.newPage();
          const pageErrors = [];
          const consoleErrors = [];
          const badAssets = [];
          page.on('pageerror', error => pageErrors.push(error.message));
          page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text()); });
          page.on('response', response => {
            const resourceType = response.request().resourceType();
            if (response.status() >= 400 && ['stylesheet', 'script', 'font'].includes(resourceType)) {
              badAssets.push({ url: response.url(), status: response.status(), resource_type: resourceType });
            }
          });
          const started = Date.now();
          const item = {
            route,
            language: language.code,
            direction: language.direction,
            viewport: viewport.name,
            width: viewport.width,
            status: 'failed',
            url: `${origin}/wp-admin/admin.php?page=${route}&lang=${language.code}`,
            elapsed_wall_ms: null,
            metrics: null,
            errors: [],
            bad_assets: badAssets,
          };
          try {
            await context.addCookies(sessions[language.code].cookies.map(cookie => ({ ...cookie, url: `${origin}/` })));
            const response = await page.goto(item.url, { waitUntil: 'load', timeout: 30000 });
            await page.waitForTimeout(150);
            const state = await page.evaluate(() => {
              const navigation = performance.getEntriesByType('navigation')[0];
              const paints = Object.fromEntries(performance.getEntriesByType('paint').map(entry => [entry.name, entry.startTime]));
              const resources = performance.getEntriesByType('resource');
              const transferBytes = resources.reduce((sum, entry) => sum + (entry.transferSize || 0), 0);
              const decodedBytes = resources.reduce((sum, entry) => sum + (entry.decodedBodySize || 0), 0);
              const resourceBytesByType = {};
              resources.forEach(entry => {
                const type = entry.initiatorType || 'other';
                resourceBytesByType[type] = (resourceBytesByType[type] || 0) + (entry.transferSize || 0);
              });
              const topResources = resources
                .filter(entry => entry.transferSize > 0)
                .sort((a, b) => b.transferSize - a.transferSize)
                .slice(0, 12)
                .map(entry => ({
                  path: new URL(entry.name).pathname,
                  initiator_type: entry.initiatorType || 'other',
                  transfer_bytes: entry.transferSize || 0,
                  decoded_bytes: entry.decodedBodySize || 0,
                  duration_ms: entry.duration,
                }));
              return {
                navigation: navigation ? {
                  ttfb: navigation.responseStart - navigation.requestStart,
                  dom_content_loaded: navigation.domContentLoadedEventEnd,
                  load: navigation.loadEventEnd,
                } : {},
                first_contentful_paint: paints['first-contentful-paint'] || null,
                resource_count: resources.length,
                transfer_bytes: transferBytes,
                decoded_bytes: decodedBytes,
                resource_bytes_by_type: resourceBytesByType,
                top_resources: topResources,
                dom_nodes: document.querySelectorAll('*').length,
                html_bytes: new TextEncoder().encode(document.documentElement.outerHTML).length,
                stylesheet_count: document.querySelectorAll('link[rel="stylesheet"], style').length,
                script_count: document.scripts.length,
                scroll_width: document.documentElement.scrollWidth,
                viewport_width: window.innerWidth,
                direction: getComputedStyle(document.documentElement).direction,
                title: document.title,
                fatal_text: /Fatal error|Parse error|Warning:/.test(document.body.innerText),
              };
            });
            const status = response ? response.status() : 0;
            item.metrics = {
              ttfb: round(state.navigation.ttfb),
              dom_content_loaded: round(state.navigation.dom_content_loaded),
              load: round(state.navigation.load),
              first_contentful_paint: round(state.first_contentful_paint),
              transfer_bytes: state.transfer_bytes,
              decoded_bytes: state.decoded_bytes,
              resource_bytes_by_type: state.resource_bytes_by_type,
              top_resources: state.top_resources,
              resource_count: state.resource_count,
              dom_nodes: state.dom_nodes,
              html_bytes: state.html_bytes,
              stylesheet_count: state.stylesheet_count,
              script_count: state.script_count,
              overflow_px: state.scroll_width - state.viewport_width,
            };
            item.status = status === 200 && !state.fatal_text && state.direction === language.direction && item.metrics.overflow_px <= 2 && !badAssets.length && !pageErrors.length && !consoleErrors.length ? 'ok' : 'failed';
            item.errors.push(...pageErrors.map(error => `pageerror: ${error}`));
            item.errors.push(...consoleErrors.map(error => `console: ${error}`));
            if (status !== 200) item.errors.push(`document_status: ${status}`);
            if (state.fatal_text) item.errors.push('fatal_or_warning_text_detected');
            if (state.direction !== language.direction) item.errors.push(`direction: expected ${language.direction}, got ${state.direction}`);
            if (item.metrics.overflow_px > 2) item.errors.push(`overflow_px: ${item.metrics.overflow_px}`);
          } catch (error) {
            item.errors.push(error.message);
          } finally {
            item.elapsed_wall_ms = Date.now() - started;
            cases.push(item);
            await context.close();
            process.stdout.write(`${item.status.toUpperCase()} ${language.code}/${viewport.width} ${route}${item.errors.length ? ` — ${item.errors.join(' | ')}` : ''}\n`);
          }
        }
      }
    }
  } finally {
    await browser.close();
  }

  const result = {
    generated_at_utc: new Date().toISOString(),
    source: 'local read-only administrator session',
    origin,
    role: 'administrator',
    scope: 'global',
    routes,
    languages,
    viewports,
    summary: summarize(cases),
    cases,
  };
  fs.mkdirSync(path.dirname(outputFile), { recursive: true });
  fs.writeFileSync(outputFile, JSON.stringify(result, null, 2) + '\n');
  process.stdout.write(`Performance baseline written to ${outputFile}\n`);
  if (result.summary.failed_cases) process.exitCode = 1;
})().catch(error => {
  console.error(error.stack || error.message);
  process.exitCode = 1;
});
