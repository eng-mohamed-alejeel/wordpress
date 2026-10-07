# Customizer repair results — 2026-10-07

## Applied changes

- Registered five surviving original images in the live media library, generated their metadata/thumbnails, and deduplicated two identical files. Original image files were preserved. A repeat recovery run created no additional registrations. Windows attachment references use portable relative paths.
- Customizer controls no longer inherit the plugin's broad typography overrides. Frontend typography remains available. Native section/panel/media/exit handlers were retained.
- Color settings use postMessage and a dedicated preview script. Tests verified immediate CSS updates without iframe navigation. Server-rendered contact fields retain refresh behavior.
- When no sidebars exist, omit the unused Customizer widget editor bootstrap. It is retained if a plugin or theme registers a sidebar. After this change the measured launch route no longer requested widget-types/settings/users through the widget editor.

## Verification

### Report of unresponsive identity buttons after Apache restart

The user confirmed the Customizer repair worked and requested coverage for all affected screens. The guard was therefore moved to `assets/js/wordpress-underscore.js` and registered centrally by `inc/script-compatibility.php` on the `wp_default_scripts` hook. It is attached once, immediately after the native Underscore handle, without enqueuing that dependency on pages that do not need it. A preinitialized plugin script registry is also handled. The Customizer-only registration was removed.

Live browser regression tests simulated an incompatible global assignment in the media library, page editor, theme administration and logged-in frontend, exercising native media dialogs where available. No content or logo/icon settings were published. The Customizer regression still passed after this centralization. Existing earlier references below to `customize-underscore.js` describe the initial repair before this expansion.

The supplied browser console subsequently identified `_.pluck`, `_.where` and `_.contains` as missing. It also recorded wallet-extension injection (including TronLink); the exact script that overwrote `_` is not proven by this log. No theme/plugin JavaScript reference assigning `_` was found. A controlled browser test reproduced broken controls by replacing the global Underscore object.

The theme now inserts `assets/js/customize-underscore.js` immediately after WordPress's Underscore dependency, only in Customizer controls. It captures the native library and ignores later assignments of another object to `_`. It does not change WordPress core files, freeze library methods or change the public preview. The property remains configurable. This protects ordinary assignment collisions, not deliberate property redefinition or mutation by extensions.

With the guard installed, live 390- and 1440-pixel tests attempted to overwrite `_` with incompatible objects and verified that the original reference survived. Both media dialogs opened and closed, section back worked and Customizer exit worked, without JavaScript exceptions or failed script/style assets. PHP/JavaScript syntax checks passed. These tests did not publish new logo/icon settings. The user's actual extension-enabled browser must reload the Customizer to load the repair; browser extensions should also exclude localhost when possible.

On 2026-10-07, live desktop tests clicked the actual logo and site-icon buttons from the dashboard Customizer and from the account-dashboard preview URL. Both opened the native media dialog; the account-preview test recorded no JavaScript exceptions or failed script/style assets. This does not reproduce the user's reported browser failure and is not evidence that their session is repaired. Their browser/profile and cached scripts need comparison with a private-window session. No fallback click handler or global CSS override was added without a reproduced cause. Logo/icon values were not published or changed by these opening tests.

An isolated file/database clone under `.tmp/customizer-review/wordpress` and database `adc_customizer_review_02750151` was used for mutating tests. The original logo and site icon were not changed.

- Existing registered image selection and native logo cropping passed.
- A generated 600×600 PNG uploaded through the media dialog; native icon cropping passed.
- Publishing logo, icon and colors through the Customizer passed. Public output and setting values survived reload.
- Unsaved color changes did not change published output.
- Section/panel back checks passed at widths 1440 and 390 in Arabic and English admin contexts.
- Narrow-screen logo/icon dialogs opened and closed, followed by successful section back and native Customizer exit.
- Arabic/English contact previews used updated phone/email/address values; editable English description preview passed.
- PHP syntax and preview JavaScript syntax checks passed.

The live dashboard launch showed controls in roughly 0.78 seconds in one post-change sample (the recorded 2.283 seconds included a deliberate 1.5-second diagnostic wait). This is a local observation, not a guaranteed performance threshold. A single browser-generated ViewTransition cancellation exception appeared during one dashboard navigation, without preventing the tested actions; later targeted navigation/preview tests reported no runtime errors. The previously reported intermittent unresponsive arrow was not reproduced.

## Recovery and repeatability

The pre-change live database dump is `.tmp/before-customizer-repair.sql`. Media registration can be inspected with `php tools/recover-media-library.php --inspect` and applied with `--apply`. Back up the database before applying recovery on another site. The tool does not choose or publish a logo/icon automatically.
