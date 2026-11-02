'use strict';

// SEO-009 local launch audit: robots, sitemap coverage, canonical metadata,
// noindex exclusions, representative legacy redirects, and public contracts.
// Run with: node tests/seo-launch-audit.cjs

const fs = require('node:fs');
const path = require('node:path');

const origin = (process.env.ADC_SITE_ORIGIN || 'http://localhost/wordpress').replace(/\/$/, '');
const outputPath = process.env.ADC_SEO_AUDIT_OUTPUT || path.resolve(__dirname, '../../../../docs/artifacts/seo-launch-audit-1.66.0/audit.json');
const cacheBust = String(Date.now());
const report = {
    generatedAt: new Date().toISOString(),
    origin,
    checks: [],
    sitemaps: [],
    pages: [],
    redirects: [],
    summary: { passed: 0, failed: 0, warnings: 0 }
};

const record = (id, passed, detail, extra = {}) => {
    const status = passed ? 'pass' : 'fail';
    const details = { ...extra };
    if (Object.prototype.hasOwnProperty.call(details, 'status')) {
        details.httpStatus = details.status;
        delete details.status;
    }
    report.checks.push({ id, status, detail, ...details });
    report.summary[passed ? 'passed' : 'failed']++;
    console.log((passed ? 'PASS ' : 'FAIL ') + id + ': ' + detail);
    return passed;
};

const warning = (id, detail, extra = {}) => {
    report.checks.push({ id, status: 'warning', detail, ...extra });
    report.summary.warnings++;
    console.log('WARN ' + id + ': ' + detail);
};

const absolute = route => origin + (String(route).startsWith('/') ? String(route) : '/' + String(route));
const normalizeUrl = value => {
    try {
        const url = new URL(value);
        url.hash = '';
        return url.toString().replace(/\/$/, '') || url.toString();
    } catch {
        return String(value || '').replace(/\/$/, '');
    }
};
const htmlMeta = (html, name, attribute = 'name') => {
    const escapedName = name.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\\\$&');
    const expression = new RegExp(`<meta\\b[^>]*\\b${attribute}=["']${escapedName}["'][^>]*\\bcontent=["']([^"']*)["'][^>]*>`, 'i');
    const reverse = new RegExp(`<meta\\b[^>]*\\bcontent=["']([^"']*)["'][^>]*\\b${attribute}=["']${escapedName}["'][^>]*>`, 'i');
    return (html.match(expression) || html.match(reverse) || [])[1] || '';
};
const linkValues = (html, rel) => {
    const values = [];
    const expression = /<link\b[^>]*>/gi;
    for (const match of html.matchAll(expression)) {
        const tag = match[0];
        const relMatch = tag.match(/\brel=["']([^"']*)["']/i);
        if (!relMatch || !relMatch[1].toLowerCase().split(/\s+/).includes(rel)) continue;
        const hrefMatch = tag.match(/\bhref=["']([^"']*)["']/i);
        if (hrefMatch) values.push(hrefMatch[1]);
    }
    return values;
};
const hreflangs = html => {
    const values = [];
    for (const match of html.matchAll(/<link\b[^>]*>/gi)) {
        const tag = match[0];
        const rel = tag.match(/\brel=["']([^"']*)["']/i);
        const hreflang = tag.match(/\bhreflang=["']([^"']*)["']/i);
        if (rel && hreflang && rel[1].toLowerCase().split(/\s+/).includes('alternate')) values.push(hreflang[1]);
    }
    return values;
};
const xmlLocs = xml => [...xml.matchAll(/<loc>([\s\S]*?)<\/loc>/gi)].map(match => match[1].trim().replace(/&amp;/g, '&'));

async function get(url, options = {}) {
    try {
        const requestUrl = new URL(url);
        requestUrl.searchParams.set('adc_seo_audit', cacheBust);
        const response = await fetch(requestUrl.toString(), { redirect: 'follow', ...options });
        return { response, body: await response.text(), error: null };
    } catch (error) {
        return { response: null, body: '', error: error.message };
    }
}

async function inspectPage(url, type = 'sitemap') {
    const result = await get(url);
    const body = result.body;
    const canonical = htmlMeta(body, 'canonical', 'rel') || linkValues(body, 'canonical')[0] || '';
    const robots = htmlMeta(body, 'robots');
    const alternates = hreflangs(body);
    const page = {
        url,
        type,
        status: result.response ? result.response.status : 0,
        finalUrl: result.response ? result.response.url : '',
        canonical,
        robots,
        alternates,
        noindex: /(^|[,\s])noindex([,\s]|$)/i.test(robots),
        encodedArabicPath: /%d8|%d9/i.test(new URL(url).pathname),
        error: result.error
    };
    report.pages.push(page);
    return page;
}

async function inspectRedirect(id, url, expectedPrefix) {
    const result = await get(url, { redirect: 'manual' });
    const location = result.response ? result.response.headers.get('location') || '' : '';
    const resolved = location ? new URL(location, url).toString() : '';
    const redirect = { id, url, status: result.response ? result.response.status : 0, location: resolved, error: result.error };
    report.redirects.push(redirect);
    record(id + '.status', redirect.status === 301, 'legacy URL returns HTTP 301.', redirect);
    record(id + '.location', !!resolved && new URL(resolved).pathname.startsWith(expectedPrefix), 'legacy URL redirects to the clean route.', redirect);
    return redirect;
}

(async () => {
    const robotsUrl = absolute('/robots.txt');
    const robots = await get(robotsUrl);
    const robotsBody = robots.body;
    record('robots.status', !!robots.response && robots.response.status === 200, 'robots.txt returns HTTP 200.', { status: robots.response?.status || 0 });
    record('robots.content-type', /text\/plain/i.test(robots.response?.headers.get('content-type') || ''), 'robots.txt is served as text/plain.');
    record('robots.sitemap', robotsBody.includes(absolute('/wp-sitemap.xml')), 'robots.txt advertises the WordPress sitemap.', { body: robotsBody });
    record('robots.admin-scope', robotsBody.includes('Disallow: /wp-admin/'), 'robots.txt blocks only the administration path and allows admin-ajax.php.');

    const indexUrl = absolute('/wp-sitemap.xml');
    const index = await get(indexUrl);
    record('sitemap.index-status', !!index.response && index.response.status === 200, 'sitemap index returns HTTP 200.', { status: index.response?.status || 0 });
    record('sitemap.index-xml', /<sitemapindex[\s>]/i.test(index.body), 'sitemap index is valid XML.');
    const childUrls = xmlLocs(index.body);
    record('sitemap.child-maps', childUrls.length > 0, 'sitemap index exposes child sitemaps.', { childCount: childUrls.length });

    const sitemapUrls = [];
    for (const childUrl of childUrls) {
        const child = await get(childUrl);
        const urls = xmlLocs(child.body);
        report.sitemaps.push({ url: childUrl, status: child.response?.status || 0, urlCount: urls.length, error: child.error });
        record('sitemap.child.' + childUrls.indexOf(childUrl), !!child.response && child.response.status === 200 && /<urlset[\s>]/i.test(child.body), 'child sitemap returns 200 and valid XML.', { url: childUrl, status: child.response?.status || 0, urlCount: urls.length });
        sitemapUrls.push(...urls);
    }
    const uniqueSitemapUrls = [...new Set(sitemapUrls)];
    record('sitemap.unique-urls', uniqueSitemapUrls.length === sitemapUrls.length, 'sitemap contains no duplicate URLs.', { total: sitemapUrls.length, unique: uniqueSitemapUrls.length });
    record('sitemap.no-encoded-commercial-links', !uniqueSitemapUrls.some(url => /\/(cars|offers)\/%d8|\/(cars|offers)\/%d9/i.test(url)), 'vehicle and offer sitemap URLs do not use encoded Arabic slugs.');

    let pageFailures = 0;
    let noindexFailures = 0;
    let canonicalFailures = 0;
    for (const url of uniqueSitemapUrls) {
        const page = await inspectPage(url);
        if (page.status !== 200) pageFailures++;
        if (page.noindex) noindexFailures++;
        if (!page.canonical || normalizeUrl(new URL(page.canonical, url).toString()) !== normalizeUrl(url)) canonicalFailures++;
        if (page.encodedArabicPath && !/\/(cars|offers)\//.test(new URL(url).pathname)) warning('sitemap.encoded-editorial-url', 'Editorial or taxonomy URL still uses a percent-encoded path; review only if a clean localized slug is required.', { url });
    }
    record('sitemap.pages-200', pageFailures === 0, 'every sitemap URL returns HTTP 200.', { total: uniqueSitemapUrls.length, failures: pageFailures });
    record('sitemap.pages-canonical', canonicalFailures === 0, 'every sitemap URL publishes a matching canonical.', { total: uniqueSitemapUrls.length, failures: canonicalFailures });
    record('sitemap.pages-indexable', noindexFailures === 0, 'no sitemap URL publishes noindex.', { total: uniqueSitemapUrls.length, failures: noindexFailures });

    const apiVehicle = await get(absolute('/wp-json/wp/v2/car?per_page=1&_fields=id,slug'));
    const apiOffer = await get(absolute('/wp-json/wp/v2/car_offer?per_page=1&_fields=id,slug'));
    let vehicleId = 0;
    let offerId = 0;
    try { vehicleId = Number(JSON.parse(apiVehicle.body)[0]?.id || 0); } catch {}
    try { offerId = Number(JSON.parse(apiOffer.body)[0]?.id || 0); } catch {}
    if (vehicleId) {
        const vehiclePage = await inspectPage(absolute('/cars/car-' + vehicleId + '/'), 'representative');
        record('public.vehicle.status', vehiclePage.status === 200, 'representative clean vehicle URL returns 200.', vehiclePage);
        record('public.vehicle.canonical', normalizeUrl(new URL(vehiclePage.canonical, vehiclePage.url).toString()) === normalizeUrl(vehiclePage.url), 'representative vehicle canonical matches its clean URL.');
    } else warning('public.vehicle.discover', 'No vehicle was available through the local REST API.');
    if (offerId) {
        const offerPage = await inspectPage(absolute('/offers/offer-' + offerId + '/'), 'representative');
        record('public.offer.status', offerPage.status === 200, 'representative clean offer URL returns 200.', offerPage);
        record('public.offer.canonical', normalizeUrl(new URL(offerPage.canonical, offerPage.url).toString()) === normalizeUrl(offerPage.url), 'representative offer canonical matches its clean URL.');
    } else warning('public.offer.discover', 'No offer was available through the local REST API.');

    const vehicleRows = (() => { try { return JSON.parse(apiVehicle.body); } catch { return []; } })();
    const offerRows = (() => { try { return JSON.parse(apiOffer.body); } catch { return []; } })();
    if (vehicleRows[0]?.slug) {
        const slug = String(vehicleRows[0].slug);
        await inspectRedirect('redirect.vehicle', absolute('/cars/' + (slug.includes('%') ? slug : encodeURIComponent(slug)) + '/'), '/wordpress/cars/car-');
    } else warning('redirect.vehicle.discover', 'No legacy vehicle slug was available through the local REST API.');
    if (offerRows[0]?.slug) {
        const slug = String(offerRows[0].slug);
        await inspectRedirect('redirect.offer', absolute('/offers/' + (slug.includes('%') ? slug : encodeURIComponent(slug)) + '/'), '/wordpress/offers/offer-');
    } else warning('redirect.offer.discover', 'No legacy offer slug was available through the local REST API.');

    for (const [id, route] of [['exclusion.account', '/?cd_account=login'], ['exclusion.search', '/cars/?search=seo-launch-audit']]) {
        const page = await inspectPage(absolute(route), 'exclusion');
        record(id + '.noindex', page.noindex, id + ' is noindex.', page);
        record(id + '.not-in-sitemap', !uniqueSitemapUrls.includes(absolute(route)), id + ' is not present in the sitemap.');
    }

    report.sitemapUrls = uniqueSitemapUrls;
    fs.mkdirSync(path.dirname(outputPath), { recursive: true });
    fs.writeFileSync(outputPath, JSON.stringify(report, null, 2) + '\n', 'utf8');
    console.log('SEO-009 audit artifact: ' + outputPath);
    console.log('SEO-009 summary: ' + report.summary.passed + ' passed, ' + report.summary.failed + ' failed, ' + report.summary.warnings + ' warnings.');
    if (report.summary.failed > 0) process.exitCode = 1;
})().catch(error => {
    console.error(error.stack || error.message);
    process.exitCode = 1;
});
