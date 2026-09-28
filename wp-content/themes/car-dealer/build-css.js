/**
 * Car Dealer — CSS Build Script
 * يجمع جميع ملفات CSS في ملف واحد مصغر
 *
 * الاستخدام: node build-css.js
 */

const fs = require('fs');
const path = require('path');

const cssDir = path.join(__dirname, 'assets', 'css');
const outputFile = path.join(cssDir, 'main.min.css');

// ترتيب الملفات للدمج
const files = [
  'abstracts/_variables.css',
  'base/_reset.css',
  'base/_typography.css',
  'components/_buttons.css',
  'components/_cards.css',
  'components/_forms.css',
  'components/_floating.css',
  'layout/_header.css',
  'layout/_footer.css',
  'layout/_grid.css',
  'pages/_home.css',
  'pages/_cars.css',
  'pages/_account.css',
  'pages/_admin.css',
  'themes/_auto-brands.css',
];

let combined = '';

// ترويسة الملف
combined += '/* ═══════════════════════════════════════════════════════\n';
combined += '   Car Dealer — Main Stylesheet (Minified)\n';
combined += '   تم إنشاؤه تلقائياً بواسطة build-css.js\n';
combined += '   ═══════════════════════════════════════════════════════ */\n\n';

// دمج الملفات
files.forEach(file => {
  const filePath = path.join(cssDir, file);
  if (fs.existsSync(filePath)) {
    const content = fs.readFileSync(filePath, 'utf8');
    combined += `/* ── ${file} ── */\n`;
    combined += content;
    combined += '\n';
  } else {
    console.warn(`⚠️ الملف غير موجود: ${file}`);
  }
});

// تصغير CSS بسيط
function minify(css) {
  return css
    // إزالة التعليقات
    .replace(/\/\*[\s\S]*?\*\//g, '')
    // إزالة المسافات الزائدة
    .replace(/\s+/g, ' ')
    // إزالة المسافات حول الرموز
    .replace(/\s*([{}:;,])\s*/g, '$1')
    // إزالة الفواصل المنقوطة الأخيرة
    .replace(/;}/g, '}')
    // إزالة الأسطر الجديدة
    .replace(/\n/g, '')
    .trim();
}

const minified = minify(combined);

// كتابة الملف
fs.writeFileSync(outputFile, minified, 'utf8');

console.log('✅ تم إنشاء ملف main.min.css بنجاح');
console.log(`📦 الحجم الأصلي: ${(combined.length / 1024).toFixed(2)} KB`);
console.log(`📦 الحجم المصغر: ${(minified.length / 1024).toFixed(2)} KB`);
console.log(`📉 نسبة التصغير: ${((1 - minified.length / combined.length) * 100).toFixed(1)}%`);
