# Customizer repair results — 2026-10-07

## Applied changes

- Registered five surviving original images in the live media library, generated their metadata/thumbnails, and deduplicated two identical files. Original image files were preserved. A repeat recovery run created no additional registrations. Windows attachment references use portable relative paths.
- Customizer controls no longer inherit the plugin's broad typography overrides. Frontend typography remains available. Native section/panel/media/exit handlers were retained.
- Color settings use postMessage and a dedicated preview script. Tests verified immediate CSS updates without iframe navigation. Server-rendered contact fields retain refresh behavior.
- When no sidebars exist, omit the unused Customizer widget editor bootstrap. It is retained if a plugin or theme registers a sidebar. After this change the measured launch route no longer requested widget-types/settings/users through the widget editor.

## Verification

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
