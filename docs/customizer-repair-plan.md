# Customizer diagnosis and repair plan

## Observations (local site, 2026-10-07)

- Launch through the dashboard Customize link worked. Controls appeared in approximately 0.85–0.93 seconds; the test then deliberately waited 1.5 seconds before recording its 2.34–2.42-second elapsed time. This is not proof of the intermittent delay reported by the owner.
- Real pointer clicks opened both the logo and site icon media dialogs. Both dialogs reported an empty library. Database inspection found no attachment posts, while seven image files remain under uploads/2026. Reinstalling WordPress discarded media registrations; files alone cannot populate its media picker.
- A real pointer click on Site Identity's back button collapsed the section. Earlier desktop and mobile section/panel checks passed. The intermittent navigation failure remains unreproduced.
- No runtime exception or missing CSS/JavaScript response was captured in the tested route. Uploading, cropping and publishing images have not been exercised by this read-only audit.
- Theme contact/appearance settings use refresh transport; each edit refreshes the entire preview. This is a real source of preview work, but is not evidence that opening a media dialog fails.
- The shared plugin typography stylesheet applies broad font-family overrides to WordPress controls. Audit its scope before attributing any navigation failure to it.

## Implementation sequence

1. **Protect and observe.** Back up the current database. Add a development-only browser diagnostic harness for both dashboard entry links and direct entry. Record JS stacks, failed AJAX/REST responses, request timing, viewport, actual visible media dialog and element hit testing. Reproduce the intermittent failure before applying a workaround.
2. **Repair the media library.** Inventory remaining images, deduplicate exact files, and register selected original assets through WordPress attachment APIs with generated metadata. Use current URLs and newly assigned IDs. Do not blindly select a logo or icon based on filenames. Test existing selection, new upload, image edit/crop, cancel and reopen. Verify PHP GD through the HTTP PHP runtime and validate crop AJAX responses. Keep native WordPress media controls; extend icon handling only if a concrete server limitation requires it.
3. **Repair navigation where the reproduction points.** Test section back, panel back, media close and full Customizer exit independently on desktop/mobile and Arabic/English. Inspect overlays, click targets, loading state, iframe messaging and return URLs. Scope any plugin styles/scripts to their intended admin screens; preserve native WordPress navigation and unsaved-change prompts.
4. **Improve measured performance.** Profile cold dashboard launch, document load and preview readiness separately. Exclude diagnostic waits. Address slow AJAX/REST/PHP calls and unnecessary assets only when measurements identify them. Use postMessage/selective refresh for supported appearance/contact outputs, retaining refresh where server-rendered content requires it.
5. **Verify persistence and regressions.** On a disposable database copy, publish test logo/icon and settings, reload, and verify public output and saved values. Verify canceled changes remain unpublished. Repeat opening dialogs and navigating sections after preview edits. Check keyboard focus, scrolling, RTL and mobile layouts.

## Acceptance criteria

- Both dashboard links and direct entry reliably expose responsive controls and a loaded preview.
- Logo/icon dialogs open on repeated attempts; registered media can be selected, uploaded, cropped and saved without a failed request.
- Back/close/exit controls work independently and preserve unsaved-change confirmation.
- Arabic and English previews receive current contact data and their respective descriptions.
- Test publishing is performed on an isolated copy, and operational/customer records are untouched.
- Report measured timings and any remaining browser-specific intermittent failure explicitly.
