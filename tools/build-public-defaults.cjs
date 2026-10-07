'use strict';
const fs = require('node:fs');
const bundle = JSON.parse(fs.readFileSync('docs/launch-content/editorial.ar-en.json', 'utf8'));
const policies = JSON.parse(fs.readFileSync('docs/legal/website-policies.json', 'utf8'));
const pages = bundle.pages.map(p => ({slug:p.slug,title:p.ar.title,content:p.ar.content,title_en:p.slug==='faq'?'FAQ':p.en.title,content_en:p.en.content,template:'default'}));
for(const p of policies) pages.push({slug:p.slug,title:p.arTitle,content:p.ar,title_en:p.enTitle,content_en:p.en,template:'page-legal.php'});
fs.writeFileSync('wp-content/themes/car-dealer/inc/public-site-defaults.json', JSON.stringify({version:1,site_name:'شركة أوتو براندز',tagline:'مبيعات السيارات الجديدة وخدمات ما بعد البيع في جدة',settings:{primary_color:'#102a43',accent_color:'#1677c8',phone:'0550928190',email:'autobrands2020@gmail.com',address:'جدة، حي الجوهرة'},pages},null,2)+'\n');
