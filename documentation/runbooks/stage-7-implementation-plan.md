# Stage 7 — Media Pipeline: Phase 0 Implementation Plan

Basis: `main` @ deb63fb (tree listing, `Plugin.php`, `Module.php`, `ProductModule.php`, `ProductService.php`, `Capabilities.php` read in full; `Meta.php`, `Product/*`, `Category/*` located by targeted search) and the Stage 7 blueprint (§1–3, §12–13, §20 read; remaining sections by heading). The three Stage 7 runbooks are on `main`, not on the branch `stage-7-media-pipeline-implementation`, which I could not see. No source was modified.

## A. Existing architecture findings
1. One PSR-4 prefix `Rameshwari\Core\` → `src/`. New namespaces (`Services\Media`, `Admin\Media`, `Cron`) need no loader change.
2. `Plugin::boot()` holds a hard-coded list of 8 modules; `ModuleRegistry` orders them by `requires()`. `Module` contract: `id()`, `requires()`, `register(Container)`; constructors do no work.
3. Product writes go through `ProductService::save( ProductData, ?int )` → `ProductRepository`. `ProductGuard` blocks product writes made outside the service. `ProductData` allow-lists `gallery` as `attachments:12`; no `thumbnail` / featured-image reference exists anywhere under `Product/`.
4. Media keys are registered in `Meta.php` with validators `attachment`, `image`, `video`, `attachments:N`: product gallery (12), showroom gallery (`attachments:0`, meaning unverified), reel `_rj_attachment_id` / `_rj_poster_id`, `_rj_cover_id`, `_rj_photo_id`, `_rj_image_desktop` / `_rj_image_mobile`, category `_rj_image_id` / `_rj_icon_id`, option `default_image_id`.
5. Capabilities: eleven platform caps; `rj_catalogue_manager` holds `upload_files`, `rj_manage_catalogue`, `rj_manage_categories`, `rj_manage_reels`. `rj_manage_showrooms` is held by administrator only. No Stage 7 capability is needed (frozen).
6. `Support` supplies Assets, Cache, Lock, Logger, Sanitizer, Str, Hash, Escaper, Template, RateLimiter. No media code exists (`add_image_size`, `wp_get_attachment*`, `pre_delete_attachment`, `set_post_thumbnail` are all absent).
7. `Activator` / `Deactivator` / `Uninstaller` are Stage 3 files and contain no cron scheduling.

## B. Stage 7 integration points
| Point | With | Direction |
|---|---|---|
| `MediaModule` in `Plugin::boot()` array | Plugin, ModuleRegistry | one added line |
| Gallery writes | `ProductService::save` (`gallery` field) | Stage 7 calls Stage 6, never `update_post_meta` |
| Featured image | native `_thumbnail_id` | Stage 7 validates and reads; write path see I-1 |
| Attachment-typed meta (F7–F11) | `Meta.php` validators | read-only, by key name |
| Delete protection | `pre_delete_attachment` filter | new hook |
| Sizes / formats | `init`, `intermediate_image_sizes_advanced`, `wp_generate_attachment_metadata` | new hooks |
| Locking | `Support\Lock` | Regenerator, Replacer |
| Caching | `Support\Cache` | optional, request-local first |
| Audit schedule | `wp_schedule_event` | see I-2 |

## C. Proposed additions (blueprint §20, unchanged)
`src/Services/Media/`: `MediaModule`, `Sizes`, `Formats`, `Responsive`, `Loading`, `UploadValidator`, `Gallery`, `Replacer`, `RetentionStore`, `ReferenceGuard`, `ReferenceSources`, `CodeMatcher`, `Regenerator`, `PosterExtractor`, `AltText`, `Value/*`. `src/Admin/Media/BulkUploader`. `src/Cron/MediaAudit`. Tests under `tests/unit/Media/` and `tests/integration/Media/`. `documentation/runbooks/stage-7-gate.md`.

## D. Modifications to existing files
- `src/Plugin.php`: add `Services\Media\MediaModule::class` to the array. Nothing else.
- `build/stubs/wordpress-tests.php`: likely needs attachment-factory / upload helpers for PHPStan (test-only; confirm in Task 0).
- Nothing in `Product/*`, `Category/*`, `Data/*`, `Support/*`, `Activator` / `Deactivator` / `Uninstaller`.

## E. Test plan → requirement
| Requirement | Test |
|---|---|
| JPEG / PNG / WebP only; SVG rejected; ≤15 MB; ≤6000×6000; ≤24 MP | unit: `UploadValidator` (each limit, each boundary, extension / content mismatch) |
| Five sizes exact | integration: registered sizes equal 300×300, 600×600, 1200×1500, 1920×1200, 720×1280; 1080×1350 is not registered |
| WebP / AVIF sidecars, original kept, never sole format | integration: generation where supported; fallback when unsupported; original file survives |
| Srcset / sizes from registered sizes; dimensions declared | unit: `Responsive` |
| Hero / first row eager + priority; rest lazy / async | unit: `Loading` |
| Gallery add / remove / reorder, order = array order, dedupe, limit | unit: `Gallery`; integration: persisted via `ProductService`, limit stays 12 |
| Bulk upload per file, client-resumable | integration: `BulkUploader` one-file requests, nonce, capability |
| Filename ↔ product code suggestion only | unit: `CodeMatcher` (zero / one / many matches) |
| Replace keeps ID, 30-day retention, hard delete cleans artifacts | integration: `Replacer`, `RetentionStore` |
| Referenced attachment cannot be deleted; references named | integration: `ReferenceGuard` for every owner key in F7–F11 |
| Regenerator batched, resumable, no CLI | integration: cursor / batch, interrupted run resumes |
| Poster extraction; reel plays without poster | unit / integration: `PosterExtractor`; missing poster never blocks |
| Product alt text never blank; manual alt preserved | unit: `AltText` |
| Daily `MediaAudit`, one failing check isolated | integration |
| No new capability, table, option or meta key | regression: schema and registration snapshot unchanged |
| Stage 0–6 contracts | full existing suite (314 tests, 9647 assertions at baseline) must stay green |

Reusable helpers: `DatabaseTestCase`, the WordPress test stubs in `build/stubs`, existing Product test fixtures.

## F. Implementation order (dependency graph)
0 → 1 value types → 2 `UploadValidator`, `Sizes` → 3 `Formats`, `Responsive`, `Loading` → 4 `Gallery` → 5 `RetentionStore`, `Replacer` → 6 `ReferenceSources`, `ReferenceGuard` → 7 `CodeMatcher`, `AltText` → 8 `Regenerator` → 9 `PosterExtractor` → 10 `BulkUploader` → 11 `MediaAudit` → 12 `MediaModule` + `Plugin.php` line → 13 gate document. `MediaModule` is wired last so every earlier step is testable without touching the live plugin.

## G. Task breakdown
Each task: failing test first → minimal code → project lint / analyse / test → no edit to a frozen file. Tasks 1–11 add classes only; task 12 is the only change to an existing production file.

## H. Regression risks
1. `PluginTest` / `RegistriesTest` may assert the module list or count; adding `MediaModule` can change it. Check before step 12.
2. Image-size registration changes what `wp_generate_attachment_metadata` produces for every upload, including existing Stage 5 / 6 tests that create attachments.
3. `pre_delete_attachment` guard can break tests that delete fixture attachments.
4. PHPStan analyses `tests/`; attachment helpers missing from the stub will fail analysis.
5. PHPStan memory: `build/composer.json` on `main` already carries `--memory-limit=2G` (verified); more code still raises the risk of a crash, so watch the CI log.
6. Sidecar WebP / AVIF discovery without persisted state means filesystem checks at render time; keep them behind `Cache`.
7. `Meta.php` accepts any attachment for `attachment` keys; Stage 7 must not tighten validators (frozen).

## I. Questions requiring clarification
- **I-1 Featured image write path.** RESOLVED by the Task 0 read of `ProductGuard.php`: native `_thumbnail_id` writes and REST `featured_media` are not blocked by the guard, so Stage 7 needs no Stage 6 change.
- **I-2 Cron scheduling.** `MediaAudit` needs `wp_schedule_event`, but the blueprint modifies only `Plugin.php`; `Activator`, `Deactivator` and `Uninstaller` are frozen. Proposal: schedule lazily in `MediaModule::register()` on `admin_init` if absent; clearing on deactivation is then not possible without a Stage 3 edit. Needs a decision.
- **I-3 Showroom gallery limit.** RESOLVED: `attachments:0` stays unbounded; Stage 7 enforces no limit and the caller supplies `$max`.
- **I-4 Branch.** RESOLVED: `stage-7-media-pipeline-implementation` is confirmed based on `main` @ deb63fb (merge-base deb63fb).

None of I-1 to I-4 touches a locked decision. I-2 needs an answer before step 11; I-1 before step 4.

## J. Exact first implementation task
**Task 0 (corrected):** record git state and the observed full PHPUnit baseline on the branch; do not re-apply the 2G change (already on `main`); read `ProductGuard.php` in full (done: see Task 0 report). I-1 is answered by that read: native `_thumbnail_id` writes are not blocked by the guard.
**Task 1:** create the immutable value types `ValidationResult`, `LoadingHint`, `GalleryResult`, `ReplaceResult`, `ReferenceReport` under `src/Services/Media/Value/`, each with a unit test written first in `tests/unit/Media/`. No WordPress calls, no hooks, no change to any existing file.

## Status
**STAGE 7 IMPLEMENTATION PLAN STATUS: READY** for Tasks 0 and 1. I-1 must be answered before the Gallery step and I-2 before the MediaAudit step; neither blocks the start.

## Task status
- **Task 0:** COMPLETE and verified on the repository. Baseline: branch `stage-7-media-pipeline-implementation`, HEAD deb63fb, merge-base with main deb63fb, clean tree; PHPCS 103/103 PASS; PHPStan PASS; PHPUnit 314 tests, 9647 assertions, PASS.
- **Task 1:** files written, awaiting machine verification. Failure-code syntax for `ValidationResult` and `ReplaceResult` is a proposal-level choice (non-empty only), not a frozen contract. `LoadingHint` validates HTML attribute values only; hero or above-the-fold decisions belong to `Services\Media\Loading`.
