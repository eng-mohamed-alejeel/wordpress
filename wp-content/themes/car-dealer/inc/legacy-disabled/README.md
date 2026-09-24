# Legacy disabled modules

These files were moved out of the active `inc` directory because they are not
loaded by the current theme bootstrap and several of them declare duplicate
functions or depend on missing scripts/templates.

Do not include these files directly. Rebuild or migrate a feature into a single
active module first, then wire it from `functions.php`.

Currently active modules:

- `inc/admin-dashboard.php`
- `inc/white-label.php`
