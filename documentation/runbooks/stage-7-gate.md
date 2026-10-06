# Stage 7 gate: media pipeline

Stage 7 is closed only when every box below is checked **by a command that was run**. Nothing here is marked done in advance.

Authority: `stage-7-media-pipeline-architecture.md`, `stage-7-media-pipeline-owner-lock.md`, `stage-7-media-pipeline-implementation-blueprint.md`, `stage-7-implementation-plan.md`.

## Scope

Stage 7 delivers the media pipeline as services and one admin entry point. It owns image sizes, upload validation, modern formats, replacement and restore, regeneration, the reference guard, galleries, poster extraction, alt text, the retention store and the media audit. Tasks 1 to 12 are merged. This remediation adds the integration layer: `Services\Media\MediaModule`, `Admin\Media\BulkUploader` and one line in `Plugin::boot()`.

## Fixed rules to keep true

- **Identity:** the WordPress attachment ID is the only media identity. There is no media table, entity, status row or second identifier.
- **Retention, replacement, recovery:** `RetentionStore` owns retained files. `Replacer` and `Restorer` use it; `MediaAudit` purges artifacts older than 30 days; `delete_attachment` removes an attachment's artifacts. Originals are kept and modern formats are additive.
- **Gallery:** order is array position.
- **REST boundary:** Stage 7 adds no REST route. Media write endpoints belong to the later REST stage.
- **Admin boundary:** `BulkUploader` is one AJAX action. The media screen, health screen and attach-to-owner action belong to the admin stage.
- **No new capability, option, meta key, table or taxonomy.**

## What the integration adds

- `MediaModule`: id `media`; requires `PostTypes`, `Meta` and `ProductModule`; no constructor; `register()` stores the container, declares lazy services and adds hooks only.
  - `init`: register the five sizes.
  - `wp_generate_attachment_metadata`: add modern-format siblings.
  - `pre_delete_attachment`: reference guard.
  - `delete_attachment`: purge that attachment's artifacts.
  - `wp_ajax_rj_media_upload`: bulk upload. No `nopriv` variant.
  - `rj_media_audit`: run the audit.
  - `admin_init`: schedule the daily audit if absent, so the front end never writes the cron option.
- `BulkUploader`: nonce, `upload_files`, the slot's business capability (`product` → `rj_manage_catalogue`, `category` → `rj_manage_categories`, `reel` → `rj_manage_reels`), `edit_post` when a post is named, `UploadValidator`, WordPress's upload handling with `upload_mimes` limited to images for the call only, then alt text if blank. Reply shape `{ status, attachment_id, error_code }`. Formats are added by the metadata hook, not by this class.

## Verification gates (run from the repository root)

- [ ] `composer --working-dir=build test -- --filter "MediaModule|BulkUploader|MediaIntegrationSource"`: all pass.
- [ ] `composer --working-dir=build lint`: 0 errors, 0 warnings.
- [ ] `composer --working-dir=build analyse`: 0 errors.
- [ ] `$env:COMPOSER_PROCESS_TIMEOUT="900"; composer --working-dir=build test`: no failures, no errors. Record the test and assertion counts here: ______.
- [ ] `git diff --check`: clean.
- [ ] `git diff --name-only`: only the files listed under Files below.
- [ ] CI matrix (PHP 8.2 + WordPress 6.5, PHP 8.4 + latest WordPress): both PHPUnit jobs pass.

## Regression expectations

- Every Stage 0 to 6 test and every Task 1 to 12 test still passes unchanged.
- Existing modules (registries, category rules, product guard) register as before; `media` registers after `product`.
- `MediaAuditSourceTest` still passes: scheduling lives in `MediaModule`, not in `MediaAudit`.

## Environment noise already recorded by the project

PHP deprecation notices from the installed WordPress test library on PHP 8.4 can appear in a separate-process CLI test. They are not a Stage 7 failure. No other noise is accepted.

## Files

Added: `src/Services/Media/MediaModule.php`, `src/Admin/Media/BulkUploader.php`, `tests/integration/Media/MediaModuleTest.php`, `tests/integration/Media/BulkUploaderTest.php`, `tests/unit/Media/MediaIntegrationSourceTest.php`, this file.
Modified: `src/Plugin.php` (one class added to the module list, one comment line).

## Open points to confirm

- The Blueprint's "attach to owner" action calls `ProductService` whose update signature was never verified; it is not implemented.
- The audit is scheduled from `admin_init`; the plan deferred the cron lifecycle to this step. Unscheduling on deactivation is not implemented.
- Source-quality confirmation is not part of the bulk upload reply.

## Pass and fail

**PASS** only when every box above is checked from real output. **FAIL** on any red check, any unlisted changed file, or any new REST route, table, option or capability.
