'use strict';
// Local editorial preview only. Does not connect to WordPress or any service.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const bundle = JSON.parse(fs.readFileSync(path.join(__dirname, 'editorial.ar-en.json'), 'utf8'));
const escape = value => String(value).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const slugs = new Set();
const allowedTags = new Set(['p', 'h2', 'ol', 'ul', 'li', 'br']);
function contentMarkup(content, language) {
  assert.equal(typeof content, 'string');
  assert.ok(content.length > 50 && !content.includes('[['), 'Content must be substantive and contain no completion markers.');
  let html = '';
  let offset = 0;
  const stack = [];
  for (const match of content.matchAll(/<[^>]*>/g)) {
    html += escape(content.slice(offset, match.index));
    assert.match(match[0], /^(?:<\/?(?:p|h2|ol|ul|li)>|<br>)$/, 'Only simple editorial markup is allowed.');
    const tag = match[0].replace(/[<>/]/g, '');
    assert.ok(allowedTags.has(tag));
    if (match[0].startsWith('</')) assert.equal(stack.pop(), tag, 'HTML tags must be balanced.');
    else if (tag !== 'br') stack.push(tag);
    html += match[0];
    offset = match.index + match[0].length;
  }
  assert.equal(stack.length, 0);
  html += escape(content.slice(offset));
  return html.replace(/\[([a-z_]+)\]/g, (_, shortcode) => {
    assert.ok(['car_dealer_contact_form', 'car_dealer_loan_calculator'].includes(shortcode));
    const label = language === 'ar' ? 'موضع النموذج أو الحاسبة — معاينة دون إرسال' : 'Form or calculator placement — preview only';
    return `<aside class="placement">${label}<code>[${escape(shortcode)}]</code></aside>`;
  });
}
function pageMarkup(page, language) {
  const copy = page[language];
  assert.ok(copy && copy.title && copy.summary && copy.content);
  return `<article><span class="slug">${escape(page.slug)}</span><h2>${escape(copy.title)}</h2><p class="intro">${escape(copy.summary)}</p><div class="copy">${contentMarkup(copy.content, language)}</div></article>`;
}
for (const page of bundle.pages) {
  assert.match(page.slug, /^[a-z]+(?:-[a-z]+)*$/);
  assert.ok(!slugs.has(page.slug), 'Page slugs must be unique.');
  slugs.add(page.slug);
}
assert.equal(bundle.pages.length, 5);
assert.ok(['pending_business_review', 'owner_facts_confirmed_editorial_review'].includes(bundle.status), 'Preview builder expects review-stage content.');
const arabic = bundle.pages.map(page => pageMarkup(page, 'ar')).join('\n');
const english = bundle.pages.map(page => pageMarkup(page, 'en')).join('\n');
for (const copy of Object.values(bundle.microcopy)) assert.ok(copy.ar && copy.en);
const html = `<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>مراجعة محتوى الإطلاق | Launch content review</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f5f7;color:#182a3a;font-family:Arial,sans-serif;line-height:1.85}header,main{max-width:1050px;margin:auto;padding:28px 22px}header{padding-bottom:12px}h1{font-size:clamp(25px,4vw,38px);line-height:1.4;margin:8px 0}.eyebrow,.slug{font-size:13px;color:#526879}.notice{background:#fff3d9;border:1px solid #d5b870;padding:14px 18px;border-radius:12px}.switch{display:inline-block;padding:8px 24px;background:white;border:1px solid #bccbd4;border-radius:8px;cursor:pointer;margin:16px 0 0 8px}input[type=radio]{margin-inline-start:18px;accent-color:#176657}input:checked+label{background:#176657;color:white}.english{display:none}#english:checked~main .arabic{display:none}#english:checked~main .english{display:block}article{background:white;border:1px solid #dce4e9;border-radius:16px;padding:24px 30px;margin:0 0 24px;box-shadow:0 5px 20px #182a3a06}article>h2{font-size:27px;margin:4px 0}.intro{font-size:18px;color:#425b6a;border-bottom:1px solid #e4e9ec;padding-bottom:18px}.copy h2{font-size:20px;margin-top:24px}.placement{padding:18px;background:#edf5f3;border:1px dashed #648e82;border-radius:10px;color:#285b4c}code{display:block;direction:ltr;text-align:left;font-size:13px}li{padding-bottom:6px}@media(max-width:500px){article{padding:20px}header,main{padding:20px 14px}}
</style></head><body>
<header><div class="eyebrow">حزمة المرحلة B · 3 أكتوبر 2026</div><h1>مراجعة محتوى الإطلاق</h1><div class="notice">نصوص مقترحة بانتظار مراجعة المنشأة واعتمادها. لا تُرسل هذه المعاينة أي بيانات.<br><span lang="en" dir="ltr">Proposed copy awaiting business review and approval. This preview sends no data.</span></div></header>
<input id="arabic" name="language" type="radio" checked><label class="switch" for="arabic">العربية</label>
<input id="english" name="language" type="radio"><label class="switch" for="english">English</label>
<main><section class="arabic" lang="ar" dir="rtl">${arabic}</section><section class="english" lang="en" dir="ltr">${english}</section></main>
</body></html>`;
fs.writeFileSync(path.join(__dirname, 'preview.html'), html, 'utf8');
console.log(`Validated ${bundle.pages.length} bilingual pages and ${Object.keys(bundle.microcopy).length} bilingual microcopy entries. Preview created.`);
