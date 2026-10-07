# Public site defaults

The Car Dealer theme ships `inc/public-site-defaults.json` with the approved Arabic and English pages, four policies and contact defaults. `inc/public-site-setup.php` initializes them on theme activation, plugin activation or the next administrator visit, once per default version.

Existing pages, populated navigation menus and saved settings are preserved. Only the untouched WordPress privacy draft is replaced by the supplied policy. Missing menus receive Home, All cars and public editorial links in the primary location, and policies in the footer. Primary menu automatic page addition is disabled so policies are not added to the header. Pages remain editable in WordPress.

To refresh the packaged baseline, edit the approved source JSON files and run `node tools/build-public-defaults.cjs` from the repository root. Ship the generated JSON with the theme. Subsequent database edits do not automatically change this baseline; back up the database to retain them after reinstalling. Logos and newly uploaded media also require backup.

Public defaults contain no vehicle, customer, booking, sale or other operational records. Activation does not recreate those records.
