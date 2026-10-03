# Local plugin/theme release candidate — 2026-10-03

**Superseded as current-code evidence:** this archive was built from `66cf4f2` and does not establish the contents of the later release baseline `4893af9`. The earlier statement that bilingual, search and footer changes were still uncommitted was historical: the working tree was clean before priority stabilization began. The 53-check local presentation acceptance is recorded in `BILINGUAL-SITE-ACCEPTANCE-2026-10-03.md`. Stabilization now changes a test and documentation; no new immutable archive has been built. Build a new candidate from the reviewed clean release commit after final acceptance; do not deploy the ZIP below as the current implementation.

`tools/build-release-package.ps1` built a code-only candidate from Git commit `66cf4f2bb9886536370aecd0fa3e77dcd4ba8b29` (plugin/theme version `1.29.13`). It did not publish or deploy anything.

| Item | Evidence |
|---|---|
| Archive | `.tmp/release-packages/auto-dealership-1.29.13-66cf4f2bb988.zip` |
| Manifest | Same path plus `.manifest.json`; includes every packaged file's SHA-256 and size. |
| Archive SHA-256 | `2eeffb32a1f1fd02f75102608a3f9bd61805bf6f229abd2f6c09fc3e905e6487` |
| Content | 163 committed runtime files under `wp-content/plugins/auto-dealership-core` and `wp-content/themes/car-dealer`. |
| Exclusions | `wp-config.php`, database/uploads, plugin tests, theme CSS build tools, unrelated repository files and uncommitted changes. |

The builder checks that the plugin/theme working paths are clean, their versions match, configuration is absent from HEAD and index, every committed file in those paths is classified, and the archive contains exactly the selected runtime files. An independent ZIP inspection confirmed 163 entries, a matching archive hash and zero excluded entries. The package and manifest are local generated artifacts ignored by Git. A repeat build for the same commit refuses to overwrite them.

This is a **candidate only**. It still needs a reviewed release commit, historical-secret review, HTTPS staging environment, approved legal and business content, real-data catalog reconciliation, target-environment smoke/security/accessibility/performance/recovery evidence and sign-off. The current source database retains synthetic development data; none is in the code archive.
