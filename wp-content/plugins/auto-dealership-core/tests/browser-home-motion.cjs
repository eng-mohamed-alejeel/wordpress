'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { dependency, output, axe } = require('./accessibility-browser.cjs');
const { chromium } = dependency('playwright-core');

(async () => {
    const browser = await chromium.launch({ executablePath: process.env.ADC_BROWSER || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'no-preference' });
    await context.route('**/*', route => {
        let url;
        try { url = new URL(route.request().url()); } catch { return route.abort(); }
        return ['localhost', '127.0.0.1'].includes(url.hostname) || ['data:', 'blob:'].includes(url.protocol) ? route.continue() : route.abort();
    });
    const page = await context.newPage();
    try {
        await page.goto('http://localhost/wordpress/', { waitUntil: 'networkidle' });
        assert.equal(await page.locator('[data-hero-motion-toggle]').count(), 0, 'Hero has no manual motion control');
        assert.equal(await page.locator('.ab-hero-particles span').count(), 5, 'Five decorative particles are present');
        assert.ok(await page.locator('.ab-hero-scroll-hint').evaluate(link => !!document.querySelector(link.hash)), 'Discover-more link targets the next visible section');

        const before = await page.evaluate(() => {
            const particle = document.querySelector('.ab-hero-particles span');
            const arrow = document.querySelector('.ab-hero-scroll-hint svg');
            return {
                particleTime: particle.getAnimations()[0]?.currentTime || 0,
                arrowTime: arrow.getAnimations()[0]?.currentTime || 0,
                particleIterations: getComputedStyle(particle).animationIterationCount,
                arrowIterations: getComputedStyle(arrow).animationIterationCount
            };
        });
        await page.waitForTimeout(650);
        const after = await page.evaluate(() => ({
            particleTime: document.querySelector('.ab-hero-particles span').getAnimations()[0]?.currentTime || 0,
            arrowTime: document.querySelector('.ab-hero-scroll-hint svg').getAnimations()[0]?.currentTime || 0
        }));
        assert.equal(before.particleIterations, 'infinite', 'Particles continue moving');
        assert.equal(before.arrowIterations, 'infinite', 'Scroll arrow continues moving');
        assert.ok(after.particleTime > before.particleTime && after.arrowTime > before.arrowTime, 'Particle and arrow animation timelines advance');

        const accessibility = await axe(page);
        assert.equal(accessibility.violations.length, 0, `Animated hero passes axe: ${JSON.stringify(accessibility.violations)}`);
        await page.screenshot({ path: path.join(output, 'home-motion-desktop.png') });

        for (const width of [390, 320]) {
            await page.setViewportSize({ width, height: 844 });
            const mobile = await page.evaluate(() => {
                const hero = document.querySelector('.ab-hero-v2').getBoundingClientRect();
                const panel = document.querySelector('.ab-hero-panel').getBoundingClientRect();
                const hint = document.querySelector('.ab-hero-scroll-hint').getBoundingClientRect();
                return { overflow: document.documentElement.scrollWidth - innerWidth, gap: hint.top - panel.bottom, hintBottom: hint.bottom, heroBottom: hero.bottom };
            });
            assert.ok(mobile.overflow <= 2, `Animated hero fits the ${width}px viewport`);
            assert.ok(mobile.gap >= 12, `Discover-more hint clears the vehicle-count panel at ${width}px`);
            assert.ok(mobile.hintBottom <= mobile.heroBottom + 1, `Discover-more hint remains inside the hero at ${width}px`);
            if (width === 390) {
                await page.evaluate(() => document.querySelectorAll('.cd-cookie-banner, .cd-cookie-overlay').forEach(element => element.remove()));
                await page.locator('.ab-hero-v2').screenshot({ path: path.join(output, 'home-motion-mobile.png') });
            }
        }

        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.waitForTimeout(100);
        assert.equal(await page.locator('.ab-hero-particles span').first().evaluate(element => getComputedStyle(element).animationName), 'none', 'Reduced-motion preference removes particle animation');
        console.log('HOME MOTION PASS: continuous motion, no manual control, mobile and reduced motion');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
