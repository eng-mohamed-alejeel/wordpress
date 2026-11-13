'use strict';
const fs = require('node:fs');
const bundle = JSON.parse(fs.readFileSync('docs/launch-content/editorial.ar-en.json', 'utf8'));
const policies = JSON.parse(fs.readFileSync('docs/legal/website-policies.json', 'utf8'));
const genericize = value => String(value)
  .replaceAll('شركة أوتو براندز', 'المنشأة')
  .replaceAll('أوتو براندز', 'المنشأة')
  .replaceAll('Auto Brands Company', 'the dealership')
  .replaceAll('Auto Brands', 'the dealership')
  .replaceAll('autobrands2020@gmail.com', '')
  .replaceAll('0550928190', '')
  .replaceAll('+966 55 092 8190', '')
  .replaceAll('جدة، حي الجوهرة', 'الموقع المحدد في إعدادات المنصة')
  .replaceAll('Al Jawharah district, Jeddah', 'the location configured in platform settings')
  .replaceAll('الفرع الرئيسي: الموقع المحدد في إعدادات المنصة.', 'الفرع الرئيسي حسب بيانات المنصة.')
  .replaceAll('Main branch: the location configured in platform settings.', 'Main branch details are managed in platform settings.')
  .replaceAll('الفرع الثاني: مكة المكرمة، مدينة السيارات — تحت الإنشاء وغير متاح للزيارة حاليًا.', 'تُدار بيانات الفروع ومواعيد الزيارة من إعدادات المنصة.')
  .replaceAll('Second branch: Cars City, Makkah — under construction and not currently open for visits.', 'Branch details and visiting hours are managed in platform settings.')
  .replaceAll('فرع مكة المكرمة في مدينة السيارات تحت الإنشاء. تواصل معنا لتنسيق زيارة الفرع الرئيسي وتأكيد التوفر والموعد.', 'تواصل معنا لتنسيق الزيارة وتأكيد التوفر والموعد.')
  .replaceAll('The Makkah branch in Cars City is under construction. Contact us to arrange a visit to the main branch and confirm availability and timing.', 'Contact us to arrange a visit and confirm availability and timing.')
  .replace(/(?:الهاتف|Phone):\s*<br\s*\/?>?/gi, '')
  .replace(/(?:البريد الإلكتروني|Email):\s*/gi, '');
const pages = bundle.pages.map(p => ({slug:p.slug,title:p.ar.title,content:genericize(p.ar.content),title_en:p.slug==='faq'?'FAQ':p.en.title,content_en:genericize(p.en.content),template:'default'}));
const crypto = require('node:crypto');
const contact = pages.find(p=>p.slug==='contact');
contact.previous_content_hash = crypto.createHash('sha256').update(contact.content).digest('hex');
contact.previous_content_en_hash = crypto.createHash('sha256').update(contact.content_en).digest('hex');
contact.content = contact.content.replace(/<p>[\s\S]*?<\/p>/, '[ab_contact_grid]');
contact.content_en = contact.content_en.replace(/<p>[\s\S]*?<\/p>/, '[ab_contact_grid]');
for(const p of policies) pages.push({slug:p.slug,title:p.arTitle,content:genericize(p.ar),title_en:p.enTitle,content_en:genericize(p.en),template:'page-legal.php'});
fs.writeFileSync('wp-content/themes/car-dealer/inc/public-site-defaults.json', JSON.stringify({version:1,site_name:'',tagline:'',settings:{primary_color:'#102a43',accent_color:'#1677c8',phone:'',email:'',address:''},pages},null,2)+'\n');
