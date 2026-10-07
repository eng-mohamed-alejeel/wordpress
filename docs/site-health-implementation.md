# Site health implementation — 2026-10-07

## Installed and verified

- Backups: `C:/Users/cv/Documents/DealershipBackups/site-health-20261007-210226`. Includes SQL, site files and original PHP/Apache/WordPress configuration. Meta Box is archived there rather than destroyed.
- Twenty Twenty-Five was installed as the inactive official fallback theme. Car Dealer remains active.
- PHP 8.4.26 TS x64 installed independently in `C:/xampp/php84`. ZIP, Intl, GD, Imagick 3.8.1 and OPcache loaded successfully through a separate Apache instance. Required DLLs are explicitly loaded in Apache configuration. Development OPcache validates file timestamps on every request.
- MariaDB 10.11.19 installed separately on loopback port 3307. The 45 imported tables matched source checksums. WordPress uses a new schema-scoped account. The old port-3306 data directory was retained without replacing InnoDB files.
- Windows denied installing a database service without administrator privileges. A current-user Startup launcher runs `tools/start-local-database.ps1`; it starts the separate database only if port 3307 has no listener. XAMPP's old MySQL controls do not control this instance. It must be managed separately; production should use a properly managed database service.
- WP Super Cache enabled in PHP mode for anonymous public pages with a 300-second lifetime. Logged-in users and all query-string requests bypass page caching (including language, search, account and Customizer routes). Menu/identity option/Customizer publication clears public cache. English query routes are deliberately uncached.
- HTTP access to `.git`, `.tmp`, `docs`, CLI `tools`, SQL/config/backup/script files is denied. Generated cache/config files are excluded from Git.
- PHP84/MariaDB1011 isolated Customizer tests passed for Arabic/English contact and description previews. Frontend pages, English, login, registration and contact returned HTTP 200 through the test PHP runtime. Backup HTTP requests returned 403.

## Awaiting Windows actions

### Verification after Apache restart

- The main Apache site now serves PHP 8.4.26 and MariaDB 10.11.19. HTTP runtime verification confirmed GD, Imagick, Intl, ZIP and OPcache are loaded and OPcache is running.
- The separate database was stopped after the restart. It was restarted without reverting or replacing the migrated data; the homepage returned HTTP 200 with a cache hit.
- `tools/start-local-database.cmd` provides a manual launcher that works despite the current PowerShell script execution policy. XAMPP's old MySQL button does not start the separate port-3307 database. The existing user-login Startup launcher remains installed.
- Live narrow-screen browser checks passed: logo and icon media pickers open and close, section back navigation works, and Customizer exit follows its return URL. No JavaScript exceptions or failed assets were recorded in this test. Upload/crop/publish were not repeated on the live database.

- Apache restart is complete and the new runtime is verified on the main site. Future service configuration still requires Windows administrator privileges.
- A localhost certificate was created with localhost/loopback SANs and configured in Apache SSL. Windows requires its root-store confirmation before it is trusted. Confirm the pending certificate installation prompt. Until trust is verified, the site's canonical URLs stay HTTP; changing them prematurely would interrupt browser access.
- Git remains in the development checkout. Its automatic-update advisory is intentional: do not remove version control or suppress the warning. Use tested release packages excluding `.git`, `.tmp`, tools and backups for production, then verify scheduled updates there.

## Completing and rollback

After restarting Apache, verify runtime/modules using Site Health → Info and retest media upload/crop, Customizer save/cancel, account requests and REST endpoints. Once local certificate trust is confirmed, migrate canonical home/site URLs and serialized internal links to HTTPS with backup, clear caches and verify HTTP redirects, media and login cookies. Do not serve a local self-signed certificate as a public production certificate.

To roll back, restore the saved Apache/PHP configs and `wp-config.php`, stop/start Apache through XAMPP and connect to the retained old database. The old database is a cutover snapshot: if new operational records are written after cutover, export/reconcile them before rollback. Disable the current-user database Startup launcher if reverting completely. Do not delete or swap database engine files.
