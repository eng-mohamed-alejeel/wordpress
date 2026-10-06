/** Guard public theme gettext strings against untranslated English pages. */
'use strict';
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../wp-content/themes/car-dealer');
const dictionary = fs.readFileSync(path.join(root, 'inc/public-catalog.php'), 'utf8') + fs.readFileSync(path.join(root, 'languages/ui.php'), 'utf8');
const paths = [
  'index.php', 'header.php', 'footer.php', '404.php', 'single-car.php',
  'archive-car.php', 'archive-car_offer.php', 'single-car_offer.php',
  'inc/contact-form-manager.php', 'templates/components/car-card.php',
  'inc/accounts.php', 'templates/account.php', 'inc/about-contact-pages.php',
  'inc/customization-manager.php', 'templates/components/filter-bar.php', 'functions.php',
];
const missing = [];
const expression = /(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*'((?:\\'|[^'])*)'\s*,\s*'car-dealer'/g;
for (const name of paths) {
  const source = fs.readFileSync(path.join(root, name), 'utf8');
  for (const match of source.matchAll(expression)) {
    const key = match[1];
    if (!/[\u0600-\u06ff]/u.test(key)) continue;
    if (!dictionary.includes(`'${key}' =>`)) missing.push(`${name}: ${key}`);
  }
}
if (missing.length) {
  process.stderr.write(`Missing English UI copy:\n${missing.join('\n')}\n`);
  process.exitCode = 1;
} else {
  process.stdout.write('Public gettext strings have English copy entries.\n');
}
