'use strict';
// Compare this working tree with a supplied Git baseline; read-only audit.
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const revision = process.argv[2] || 'HEAD';
const root = 'wp-content/plugins/auto-dealership-core/';
const baseline = file => execFileSync('git', ['show', `${revision}:${file}`], { encoding: 'utf8' });
const current = file => fs.readFileSync(file, 'utf8');
const unique = values => [...new Set(values)];
const names = source => unique([...source.matchAll(/name="([a-z_]+)(?:\[\])?"/g)].map(m => m[1]));
const methods = source => unique([...source.matchAll(/(?:public|private|protected)\s+(?:static\s+)?function\s+([a-zA-Z_]+)/g)].map(m => m[1]));
const fields = names(baseline(root + 'src/Admin/SettingsPage.php'));
assert.deepEqual(fields.filter(field => !names(current(root + 'src/Admin/SettingsPage.php')).includes(field)), [], 'An original platform form field was removed');
const theme = 'wp-content/themes/car-dealer/inc/customization-manager.php';
const themeKeys = unique([...baseline(theme).matchAll(/name="car_dealer_theme_settings\[([a-z_]+)\]"/g)].map(m => m[1]));
for (const key of themeKeys) assert.ok(current(theme).includes(`'${key}'`) || current(theme).includes(`[${key}]`), `Missing theme control: ${key}`);
let checkedMethods = 0;
for (const file of execFileSync('git', ['diff', '--name-only', revision, '--', '*.php'], { encoding: 'utf8' }).trim().split(/\r?\n/).filter(Boolean)) {
  const previous = baseline(file), next = current(file);
  for (const name of methods(previous)) {
    // The private card-array factory was replaced by Navigation's definitions.
    // It is neither a public API nor an action handler.
    if (file === root + 'src/Admin/WorkspacePage.php' && name === 'item') continue;
    assert.ok(methods(next).includes(name), `${file}: removed method ${name}`); checkedMethods++;
  }
  const hooks = unique([...previous.matchAll(/['"](admin_post_[a-z_]+)['"]/g)].map(m => m[1]));
  for (const hook of hooks) assert.ok(next.includes(hook), `${file}: removed handler ${hook}`);
}
const adminFiles = execFileSync('git', ['ls-tree', '-r', '--name-only', revision, '--', root + 'src/Admin'], { encoding: 'utf8' }).trim().split(/\r?\n/);
// Match menu calls, not unrelated asset handles or CSS class names.
const menuSlugs = source => {
  const result = [];
  for (const match of source.matchAll(/add_(sub)?menu_page\s*\(/g)) {
    let depth = 1, quote = '', arg = '', args = [];
    for (let i = match.index + match[0].length; i < source.length; i++) {
      const c = source[i];
      if (quote) {
        arg += c;
        if (c === '\\') { arg += source[++i]; }
        else if (c === quote) quote = '';
      } else if (c === "'" || c === '"') { quote = c; arg += c; }
      else if (c === '(' || c === '[') { depth++; arg += c; }
      else if (c === ')' || c === ']') {
        if (--depth === 0) { args.push(arg.trim()); break; }
        arg += c;
      } else if (c === ',' && depth === 1) { args.push(arg.trim()); arg = ''; }
      else arg += c;
    }
    const literal = (args[match[1] ? 4 : 3] || '').match(/^['"]([^'"]+)['"]$/);
    if (literal) result.push(literal[1]);
  }
  return result;
};
const oldPages = unique(adminFiles.flatMap(file => menuSlugs(baseline(file))));
const navigation = current(root + 'src/Admin/Navigation.php');
for (const page of oldPages) assert.ok(navigation.includes(`'${page}'`), `Missing original administration destination: ${page}`);
const oldWorkspace = baseline(root + 'src/Admin/WorkspacePage.php');
const oldPaths = unique([...oldWorkspace.matchAll(/'(admin\.php\?page=[^']+|edit\.php\?post_type=[^']+|users\.php)'/g)].map(m => m[1]));
for (const path of oldPaths) assert.ok(navigation.includes(`'${path}'`), `Missing original card description/path: ${path}`);
const config = root + 'src/Core/ConfigurationService.php';
const optionBlock = source => source.match(/private const OPTIONS = array\(([\s\S]*?)\);/)[1];
assert.equal(optionBlock(current(config)), optionBlock(baseline(config)), 'Persistent configuration option keys changed');
const schema = root + 'src/Database/Schema.php';
assert.equal(current(schema), baseline(schema), 'Database schema changed during dashboard reorganization');
const identity = current(root + 'src/Admin/InventoryIdentityPage.php');
for (const action of ['adc_record_vehicle_receipt','adc_record_vehicle_inspection','adc_move_vehicle_location','adc_change_vehicle_vin']) assert.ok(identity.includes(action), `Missing inventory form action ${action}`);
console.log(JSON.stringify({ baseline: execFileSync('git', ['rev-parse', revision], { encoding: 'utf8' }).trim(), originalPages: oldPages.length, originalPlatformFields: fields.length, themeFields: themeKeys.length, originalWorkspaceCards: oldPaths.length, originalMethods: checkedMethods, persistentOptionKeysUnchanged: true, schemaUnchanged: true, result: 'PASS' }, null, 2));
