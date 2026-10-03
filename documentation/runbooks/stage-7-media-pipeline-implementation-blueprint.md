# Stage 7 — Media Pipeline: Implementation Blueprint

Status: **READY FOR IMPLEMENTATION** (design only; no code exists).
Architecture: **FROZEN** (`stage-7-media-pipeline-architecture.md`). Owner lock: **11/11 LOCKED** (`stage-7-media-pipeline-owner-lock.md`). Deferred: Q11, Q13, Q15, Q16, Q17, Q18.

## 0. How to read this document
Every statement carries one tag.

| Tag | Meaning |
|---|---|
| **[FACT]** | Verified in repository source on `main` @ `cbb600b` in this pass |
| **[FROZEN]** | Required by the frozen architecture, the owner lock, or Blueprint §18 |
| **[PROPOSAL]** | Implementation design derived from the two sources above. Not a new product decision. If implementation finds it cannot work, it goes to architecture review, not a silent change |
| **[UNVERIFIED]** | Not confirmed in this pass; confirm before relying on it |

No frozen decision is changed here. The six deferred questions are not answered here.

## 1. Repository facts inspected
**Read in full this pass:** `Module.php`, `Product/ProductModule.php`, `Plugin.php`. **Read in full in the preceding pass:** `Support/Autoloader.php`, `Data/Capabilities.php`. **Located by file listing or targeted search only:** `Data/Meta.php`, `Data/PostTypes.php`, `Data/Options.php`, `Product/ProductRepository.php`, `Category/CategoryImporter.php`, `rameshwari-core.php`, and the `Support/*` files. **Not read:** `ProductService.php`, `ProductData.php`, `ProductCode.php`, `ProductGuard.php`, any test file. Anything that depends on those is tagged [UNVERIFIED].

| # | Fact | Source |
|---|---|---|
| F1 | One PSR-4 prefix `Rameshwari\Core\` maps to `src/`. No second loader. | `Support/Autoloader.php`; `rameshwari-core.php:143` |
| F2 | `Module` interface: `static id(): string`, `static requires(): array`, `register(Container): void`. Constructors do no work; `register()` only adds hooks. | `Module.php` |
| F3 | `Plugin::boot()` holds a hard-coded list of **8** module classes: `PostTypes, Taxonomies, Options, Meta, Rewrites, CategoryRules, CategoryPickerBox, ProductModule`. `ModuleRegistry::sorted()` orders them by `requires()`. A registry error registers nothing and logs. | `Plugin.php` |
| F4 | `ProductModule` `requires()` returns the ids of PostTypes, Taxonomies, Meta, CategoryRules, registers only `ProductGuard::register()`, and exposes a static `service()` wiring `ProductService`, `AssignmentGuard`, `Lock`. | `ProductModule.php` |
| F5 | Existing namespaces: `Data`, `Support`, `Category`, `Product`, plus root-level `Activator`, `Installer`, `Upgrader`, `Plugin`, `Container`, `Module`, `ModuleRegistry`. **No `Services\`, `Admin\` or `Cron\` namespace exists.** | tree listing |
| F6 | `Support` already provides `Assets, Cache, Escaper, Hash, Lock, Logger, RateLimiter, Sanitizer, Str, Template`. | tree listing |
| F7 | Meta validators: `attachment` (ID is an attachment post, any MIME), `image`, `video`, `attachments:N` (list filtered to attachment posts, capped at N). | `Meta.php:378–388, 564–580` |
| F8 | Product `_rj_gallery` = `attachments:12`; ProductRepository maps `gallery` to `_rj_gallery`. | `Meta.php:53`; `ProductRepository.php:30` |
| F9 | Reel keys: `_rj_source_type` = `enum:upload|instagram|youtube|url` (`Meta.php:65`); `_rj_video_id` = `alnum`, a provider video ID (`Meta.php:67`); **`_rj_attachment_id` = `video`, the uploaded reel video attachment** (`Meta.php:68`); `_rj_poster_id` = `attachment` (`Meta.php:69`). | `Meta.php:65–69` |
| F10 | Category term meta: `_rj_image_id`, `_rj_icon_id` = `attachment`; importer maps `image` to `_rj_image_id`. | `Meta.php:132–133`; `CategoryImporter.php:30` |
| F11 | Showroom `_rj_gallery` = `attachments:0`. Whether 0 means unlimited was not verified. Other attachment-typed keys exist: `_rj_cover_id` (`Meta.php:76`), `_rj_photo_id` (`Meta.php:97`), option `default_image_id` (`Options.php:198`). Owning post types of the first two were not confirmed. | as listed |
| F12 | Capabilities: eleven, including `rj_manage_catalogue`, `rj_manage_categories`, `rj_manage_reels`, `rj_manage_showrooms`. Role `rj_catalogue_manager` holds `read`, `upload_files`, `rj_manage_catalogue`, `rj_manage_categories`, `rj_manage_reels`. `rj_enquiry_agent` and `rj_customer` hold no `upload_files`. | `Capabilities.php` |
| F13 | Category term-meta auth needs `rj_manage_categories`; other term meta needs `rj_manage_catalogue`. Reel post type uses `rj_manage_reels`. | `Meta.php:226, 235, 240`; `PostTypes.php:65` |
| F14 | **No Stage 7 media code exists.** A search of `src/` found no `add_image_size`, `wp_get_attachment*`, `delete_attachment`, `pre_delete_attachment` or `set_post_thumbnail`. The featured image is native `_thumbnail_id`, never wrapped by Stage 6. | targeted search |
| F15 | Stage 6 product writes are guarded: `ProductGuard` catches product writes made outside `ProductService`. | `ProductGuard.php` header (file not read in full) |
| F16 | A cooperative `Support\Lock` exists (owner token, expiry, reclaim). | `Support/Lock.php` |

## 2. Discrepancies (D-1 and D-2 RESOLVED; D-3 open)
- **D-1 — RESOLVED.** Reel video is `_rj_attachment_id`, provider ID is `_rj_video_id`, poster is `_rj_poster_id`. The frozen architecture's reel row (`Meta.php:68–69`) was correct. An earlier version of this blueprint said no uploaded-video key had been found; that was wrong, caused by an incomplete search, and is corrected here. No architecture edit is needed.
- **D-2 — RESOLVED (CL lock, dependency correction only).** Stage 8 consumes Stage 7 media capabilities in addition to Stage 6: Stage 8 → Stage 6 + Stage 7.
- **D-3.** `attachments:0` for the showroom gallery (F11) is unresolved; Stage 7 does not enforce a showroom limit.

## 3. Frozen contract (restated as implementation constraints)
1. Attachment ID is the only media identity. No custom media entity, table, join table, hash store or relationship table. [FROZEN: Q1, Q3, Q2]
2. Owners store attachment IDs; one attachment may have several owners. [FROZEN: Q2]
3. Product primary image = featured image (`_thumbnail_id`). Gallery = `_rj_gallery`; render order = primary first, then gallery array order; no sort field. [FROZEN: Q4, Q5]
4. Metadata is minimal and derived; no new canonical media meta key; no persisted processing status. [FROZEN: Q3, Q6]
5. Replacement keeps the attachment ID; the old file survives 30 days as a **filesystem retention artifact** under `wp_upload_dir()`, named `{attachment-id}-{timestamp}-{random-token}-{sanitized-basename}`, never a reference. [FROZEN: Q7]
6. Per-file processing; client-driven **batch** resume; no byte-range resume; no server-side resumability state. [FROZEN: Q8]
7. Accept JPEG, PNG, WebP; reject SVG; max 15 MB, 6000 × 6000 px, 24 MP; content-detected type, extension must agree. [FROZEN: Q9]
8. Duplicates allowed; no hash store. [FROZEN: Q10]
9. All catalog media is public. [FROZEN: Q12]
10. No new capability. `upload_files` is separate from the business capability. [FROZEN: Q14]
11. Namespaces `Rameshwari\Core\Services\Media\*`, `Rameshwari\Core\Admin\Media\*`, `Rameshwari\Core\Cron\*`. [FROZEN: Q19]
12. Stages 0–6 are frozen; Stage 7 changes none except one added line in `Plugin::boot()` (§5).

## 4. Layering and dependency direction [PROPOSAL, consistent with F2, F5]
```
Admin\Media\BulkUploader ─┐
Cron\MediaAudit ──────────┤        (entry points: nonce, capability, hook)
                          ▼
Services\Media\{Replacer, Regenerator, ReferenceGuard, CodeMatcher, PosterExtractor, AltText, Gallery}
                          ▼
Services\Media\{UploadValidator, Sizes, Formats, Responsive, Loading, RetentionStore}   (primitives)
                          ▼
Support\{Lock, Logger, Sanitizer, Str, Template…}  +  WordPress attachment APIs
```
- Primitives know nothing about products, categories or reels.
- Services receive owner context as plain values (owner type, owner ID, field name) and never import `Product\*` or `Category\*` classes, except `Product\ProductService` for the one write path in §11.
- Nothing in `Services\Media` reads the theme, Elementor or Gutenberg classes.

## 5. Module wiring [FACT + PROPOSAL]
- **Class:** `Rameshwari\Core\Services\Media\MediaModule implements Module`. `id()` = `'media'`. [PROPOSAL]
- **`requires()`:** `PostTypes::id()`, `Meta::id()`, and `Product\ProductModule::id()`. [PROPOSAL] Reason: the reference guard needs the registered attachment-typed keys, and Stage 7 consumes the Stage 6 product contract. It must not require Stage 5 category classes; category image references are read from term meta by key (§12).
- **`register(Container)`:** adds hooks only, constructs nothing: size registration, upload-metadata filter, `pre_delete_attachment` filter, `delete_attachment` action, admin AJAX actions, cron schedule hook. [PROPOSAL]
- **Boot integration:** add `Services\Media\MediaModule::class` to the array in `Plugin::boot()` (F3). This is the **only** edit to a Stage 0–6 file and is a one-line `MODIFY`. The registry orders it after `ProductModule` through `requires()`. [PROPOSAL]
- **Must initialise before Stage 7:** the registries (`PostTypes`, `Meta`) and `ProductModule`.
- **Later modules that depend on it** (per recorded graph): Stage 9 (hero) requires 7; Stages 11–13 consume the services. Stage 8 see D-2.

## 6. Size system [FROZEN sizes; PROPOSAL names and mechanics]
| Contract name | Width × Height | Proposed WordPress size key |
|---|---|---|
| thumb | 300 × 300 | `rj_thumb` |
| card | 600 × 600 | `rj_card` |
| detail | 1200 × 1500 | `rj_detail` |
| hero | 1920 × 1200 | `rj_hero` |
| reel poster | 720 × 1280 | `rj_reel_poster` |

- **Class:** `Services\Media\Sizes`. Single source of the five definitions. Registers them with `add_image_size()` on `init`, so a theme change cannot orphan them (Blueprint: plugin-registered). WordPress native generation is reused; no custom resizer. Exposes `all(): array`, `exists(string $contract_name): bool`, `key(string $contract_name): string`.
- **Consumer requests** use the contract name (`'card'`), never the WordPress key or a pixel size. Unknown names fail with a typed error, not a silent fallback. No additional public size contract is added.
- **Crop policy: [FROZEN — CL-3].** thumb, card and reel poster: hard crop. detail and hero: soft/subject-preserving crop. Every size still produces its exact registered dimensions, so all five are registered with `add_image_size()` in cropping mode (a non-cropping registration would not guarantee exact dimensions). "Soft" governs only how the crop position is chosen, not whether the output is exact. No focal point is stored (CL-3, CL-5).
- **Regeneration:** changing a size definition requires the Regenerator (§14); never automatic on `init`.

## 7. Format system [FROZEN requirement; PROPOSAL mechanics]
- **Class:** `Services\Media\Formats`.
- **Detection:** `wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) )` and the same for `image/avif`. AVIF depends on the WordPress 6.5 floor and on the host's image library. Host support is **[UNVERIFIED]** and must be checked on each host, not assumed. [Master Plan "Genuine unknowns"]
- **Conversion boundary:** runs inline per file, after core's sub-size generation, through the `wp_generate_attachment_metadata` filter. Failure never aborts the upload. [FROZEN: Q8 inline]
- **Output:** a modern-format sibling per generated size, written next to the derivative with the same stem and a `.webp` or `.avif` extension. **Siblings are discovered by file existence, not by stored state**, so no new meta key is needed. Whether core's own multi-format `sources` structure can carry this is **[UNVERIFIED]**; verify before choosing that route, since it must stay WordPress-owned data.
- **Fallback:** the original and the original-format derivatives always remain and are always listed. A modern format is an addition, never a replacement. A modern-format failure leaves the image fully available.
- **Health:** `Formats::health(): array` returns findings such as "WebP not supported", "AVIF not supported". It is a pure method; the Health screen (Stage 12) displays it. [PROPOSAL; Health screen not built yet]
- **Forbidden:** requiring a modern format, deleting an original after conversion, shelling out (`exec`) for conversion.

## 8. Responsive and loading
**`Services\Media\Responsive`** — `sources( int $attachment_id, string $contract_name ): array` returns a list of `{ mime, url, width, height }` entries: modern formats first where their files exist, then the original-format entries. Built from `wp_get_attachment_image_src()` and `wp_get_attachment_url()`; no path strings. Returns an empty list for a missing or non-image attachment. It does not emit markup. A `<picture>` or `srcset` element is the renderer's job (Stage 13). [PROPOSAL]

**`Services\Media\Loading`** — `hint( bool $above_fold, bool $is_hero ): LoadingHint` returns a small value: `loading`, `fetchpriority`, `decoding`. Hero and above-the-fold images: eager and prioritised. Everything else: lazy and async. The caller decides "above the fold"; the service does not guess a row count. Declared `width` and `height` come from the registered size to prevent layout shift. [FROZEN: Blueprint lazy/eager; PROPOSAL: shape]

## 9. Gallery
**`Services\Media\Gallery`** — pure array operations plus read-time composition. [PROPOSAL]
- `normalize( array $ids, int $max ): GalleryResult` — casts to positive integers, drops non-attachments, **removes later duplicates keeping first occurrence order**, trims to `$max`, and reports what it dropped. The caller passes `$max`; Stage 7 does not restate 12 (F8). Showroom `attachments:0` is passed through unchanged (D-3).
- `add`, `remove`, `move` — return a new array. They never write.
- `ordered( int $post_id ): array` — read-time order: featured image first, then `_rj_gallery` order, skipping a gallery entry equal to the featured image. Non-destructive: it never rewrites stored data.
- **No sort field, no separate order meta.** Order is array position.
- Missing or deleted attachments are skipped at read time and reported by the audit; they are not silently removed from owner data.
- **Persistence goes through the owner.** Gallery arrays are written by `ProductService` (§11), never by a direct `update_post_meta` (F15).

## 10. Upload validation (shared primitive)
**`Services\Media\UploadValidator`** — `validate( string $tmp_path, string $client_name ): ValidationResult`. [PROPOSAL, enforcing FROZEN Q9]
Checks, in order, each with a stable error code: file exists and is an uploaded or controlled temp file; size ≤ 15 MB; `wp_check_filetype_and_ext()` content type is JPEG, PNG or WebP; extension agrees with the detected type; not SVG (by content and by extension); `wp_getimagesize()` readable; width ≤ 6000 and height ≤ 6000; width × height ≤ 24 MP; filename passes `sanitize_file_name()`. The client MIME is never trusted. Enforcement is at the service layer; `Meta.php` (F7: `attachment` accepts any MIME) is **not** changed.

## 11. Services
Each entry: responsibility; inputs → outputs; dependencies; WordPress APIs; failures; security; caching; tests; forbidden.

### 11.1 `Sizes` / `Formats` / `Responsive` / `Loading`
See §6–§8. Caching: request-local memo only. Forbidden: any product or category logic.

### 11.2 `Gallery` — §9.

### 11.3 `Replacer`
**Responsibility:** replace the file behind an existing attachment, keep the ID, keep every reference.
**Inputs:** attachment ID, incoming temp file, actor. **Output:** `ReplaceResult` (success; new dimensions; retention artifact name; or a typed failure). **Dependencies:** `UploadValidator`, `RetentionStore`, `Formats`, `Support\Lock`, `Support\Logger`.
**Flow** [PROPOSAL, realising FROZEN Q7]:
1. Validate the incoming file (§10). Stop on failure; nothing is touched.
2. Resolve the attachment; confirm it is an image attachment.
3. Acquire `Support\Lock` keyed by attachment ID. A second concurrent replace is refused.
4. Stage the new file in a temp path inside the uploads directory.
5. Copy the current original to the retention directory (`RetentionStore::keep()`), producing a new uniquely named artifact. Repeated replacements never overwrite.
6. Swap the file with `update_attached_file()`; regenerate sub-sizes and modern formats through core and `Formats`.
7. On **any** failure after step 5: restore the file from the artifact, regenerate derivatives from the restored file, release the lock, return a typed failure. The attachment ID and all references were never changed.
8. Release the lock.
**Three things kept distinct:** the *canonical attachment* (the ID), the *current file* (what the attachment points to), the *historical artifact* (a file only).
**Cleanup:** `MediaAudit` removes artifacts older than 30 days (§17); `delete_attachment` removes that attachment's artifacts.
**Security:** capability checked by the caller (§16); retention path built only from the integer ID and a sanitised basename and verified to stay inside the retention directory with `realpath()`; the directory carries an empty index file and a deny rule. Whether the deny rule is honoured depends on the host and web server, so retained originals may be reachable by URL on some hosts; the random token only mitigates this. No host behaviour is assumed.
**Forbidden:** a new table, a new meta key, a persistent "previous file" reference, changing the attachment ID.

### 11.4 `RetentionStore`
`keep( int $id ): ?string`, `list( int $id ): array` (by filename prefix, newest first), `restore( int $id, string $name ): bool`, `purge_older_than( int $days, int $limit ): int`, `purge_for( int $id ): int`. Location: a plugin-owned subdirectory under `wp_upload_dir()['basedir']`. No database access. Restore itself keeps the current file as a new artifact first, so a restore is undoable inside the window.

### 11.5 `ReferenceGuard`
**Responsibility:** decide whether an attachment may be hard-deleted, and report who uses it.
**What counts as a reference** [PROPOSAL, from F8–F11, F14]: the featured image of any post; an ID in a post-meta key of type `attachment` or in the list of `attachments:N` keys; an ID in an `attachment`-typed **term** meta key; an `attachment`-typed option field (`default_image_id`).
**Reference source list:** one class, `Services\Media\ReferenceSources`, holds the list of keys (post meta, term meta, option, thumbnail). A **drift test** asserts that every attachment-typed key registered in `Data\Meta` appears in this list, so a key added in a later stage cannot be forgotten.
**Discovery:** featured image and single-ID meta by exact match; serialised gallery arrays by a bounded candidate lookup, then exact verification in PHP. This needs a meta query; the project already needs a narrow PHPCS suppression for slow meta queries elsewhere, and the same justification applies. Results are paged and capped.
**Deletion blocking:** hook the `pre_delete_attachment` filter; when references exist, return a non-null value (blocking the delete) and record why. **Fail closed:** if the scan itself errors, block and log. This hook is the only way the guard can stop a core delete, so I treat it as **[UNVERIFIED]** until a test confirms the filter blocks both single and bulk delete in the supported WordPress range.
**Admin display:** `ReferenceReport` lists owner type, owner ID, field name and an edit link per reference. The Media-library screen that shows it is Stage 12.
**Race:** an owner can add a reference between the check and the delete. The guard narrows this window with the same per-attachment lock the Replacer uses but cannot close it, because assignments do not take the lock. Document as a residual risk.
**Hard delete:** releases retention artifacts via `delete_attachment`. Referenced attachments are never auto-deleted.
**Forbidden:** weakening WordPress behaviour for unreferenced attachments.

### 11.6 `CodeMatcher`
Detects a product code in a filename, **suggestion only**: `suggest( string $filename ): MatchResult` returning zero, one or several candidate codes. Normalisation and validity come from `Product\ProductCode`, so the frozen code rules are not restated here; the repository lookup method name is **[UNVERIFIED]** (file not read). More than one candidate, or none, means **no automatic assignment**. Attaching an image to a product is an explicit user action carried out through `ProductService` (§11.10).

### 11.7 `Regenerator`
Rebuilds sub-sizes and modern formats for existing attachments.
- **Batch:** the client sends a cursor (the last processed attachment ID) and a batch size; the server processes the next batch in ascending ID order and returns the new cursor and counts. The **server keeps no state** (Q6, Q8); resume is "send the last cursor again".
- **Idempotent:** an attachment whose derivatives and siblings already exist at the current size definitions is skipped.
- **Isolation:** one attachment's failure is recorded and does not stop the batch.
- **Admin-runnable, no CLI:** a nonce-checked admin AJAX action. Time-budget guard per request.
- **Forbidden:** a persisted job table or status row.

### 11.8 `PosterExtractor`
Stage 7 supplies the capability; **Stage 8 owns reel semantics** and the binding to `_rj_poster_id` (F9).
- **Source:** the project forbids a hard dependency on `exec()` or external binaries, so the proposed path is **browser-side frame capture** in the admin uploader (draw a video frame to a canvas, send it as an image upload), then handled as an ordinary image attachment at the `reel poster` size through `UploadValidator`. A server-side extractor (for example ffmpeg) may be added only as an optional enhancement that is never required. Host availability is **[UNVERIFIED]**.
- **Failure:** a missing poster never blocks a reel; the Blueprint regression requires a reel to play without one.

### 11.9 `AltText`
`generate( AltContext $c ): string` is pure. `AltContext` carries strings the caller already holds (owner name in English and Hindi, category name, role such as "front view"); the service reads no repository.
- **Precedence:** a non-blank manual value in the native `_wp_attachment_image_alt` is **always preserved**. Whitespace-only counts as blank.
- **Apply:** `apply( int $attachment_id, AltContext $c ): bool` writes only when blank. Native alt storage, so no new key.
- **Final validation point:** at attach time in the upload flow, and as a finding in `MediaAudit`. Enforcing it at product *publish* would change Stage 6 and is **not proposed**.
- **Note:** alt text is per attachment, but one attachment may have several owners; the first attaching owner supplies the context.
- No AI dependency.

### 11.10 `Admin\Media\BulkUploader`
- **Request lifecycle:** one file per request (per-file isolation). The browser queues files, sends them sequentially or with small concurrency, shows per-file progress, and keeps its own record of finished files for the session. After an interruption it re-sends only the unfinished ones. This is **batch resume**; there is **no byte-range resume** and **no server resumability state**.
- **"Chunked" in the Blueprint** is read here as *chunked into per-file batches*, not byte chunks, to stay consistent with the frozen Q8. This reading is a [PROPOSAL]; if the owner meant byte-chunking, that needs architecture review.
- **Server action:** a nonce-checked admin AJAX action: capability check, `UploadValidator`, WordPress upload handling (`media_handle_upload()`), `Formats`, `AltText::apply()`, then a JSON result `{ status, attachment_id, error_code }`. A failed file returns an error and affects nothing else.
- **Attach to owner:** a separate explicit action that calls `ProductService` with the new gallery or featured value. The `ProductService` update signature is **[UNVERIFIED]** (file not read). Stage 7 never calls `update_post_meta` on a product (F15).
- **Upload MIME scoping:** the `upload_mimes` restriction is added just before the upload call and removed immediately after, so other media uploads are unaffected.
- **REST:** not built here (Q13 deferred).

### 11.11 `MediaModule` — §5.

## 12. Stage 5 and Stage 6 integration boundary
**Stage 6 (frozen).** Stage 7 *may* validate attachment IDs, normalise gallery arrays, and write through `ProductService`. It *must not* redefine: the gallery limit, the featured-image rule, visibility, product code rules, or product write permissions; and must not duplicate Product Domain logic.
**Stage 5 (frozen).** Stage 7 provides image handling for `_rj_image_id` and `_rj_icon_id` (F10). It must not touch category semantics, hierarchy, the resolver, the importer or the menu tree. The reference guard reads the term-meta keys by name and imports no `Category\*` class.

## 13. Capability and security model [FROZEN Q14, PROPOSAL checks]
| Operation | Upload capability | Business capability | Object check |
|---|---|---|---|
| Product image upload/attach/replace/reorder | `upload_files` | `rj_manage_catalogue` | `current_user_can( 'edit_post', $id )` where a product is named |
| Category image/icon | `upload_files` | `rj_manage_categories` | term-meta auth (F13) |
| Reel media / poster | `upload_files` | `rj_manage_reels` | `edit_post` on the reel |
| Showroom, collection, testimonial, hero | decided in Stages 8 and 9 | — | — |
Every state-changing action: nonce, capability, object check, validation, then work. A nonce alone is never authorisation. Controls covered: MIME and content, extension, SVG rejection, size, dimensions, pixel count, filename and path handling, retention paths, deletion guard, admin endpoints. No host-specific web-server behaviour is assumed.

## 14. Media audit (`Cron\MediaAudit`)
- **Checks (Blueprint §18, daily):** products without images; categories without thumbnails; reels without a resolvable source; attachments unreferenced for more than 90 days; direct edit links.
- **Also does:** retention clean-up of artifacts older than 30 days (§11.4).
- **Schedule:** a daily `wp_schedule_event`. WP-Cron reliability is host-dependent, so every check also runs **on demand** as a plain method; the Health screen (Stage 12) calls the method. Cron is not trusted for correctness.
- **Persistence:** none. Results are computed at run time and returned as a `MediaAuditReport`; the cron run logs only a one-line summary through `Logger`. No audit table. Caching is request-local only (Q18 deferred).
- **Query strategy:** paged, ID-only queries with hard limits; no unbounded `get_posts`. Reference resolution uses `ReferenceGuard`. The "unreferenced for more than 90 days" test uses the attachment's post date as the age base. A better age base is **[UNVERIFIED]**.
- **Reel source:** how a reel's source resolves depends on the reel keys, which are not confirmed (F9, D-1); until Stage 8 owns them the reel check is written against an interface and returns "not applicable" when the key is absent.
- **Isolation:** each check runs in its own guard; one failing check is reported and the others still run.

## 15. Performance and observability
Avoid N+1: any list view resolves attachments in one batch (prime the meta cache once). Bounded batches everywhere. No cache layer is added for its own sake. Health findings produced by Stage 7: modern-format unsupported, regeneration failures, bulk-upload failures (counted from the AJAX result, logged), poster-extraction failures, audit failures. All use `Logger`; none contains a path outside uploads or a secret.

## 16. Boundaries to later stages
| Future interface | Owner stage | Expected Stage 7 call |
|---|---|---|
| REST routes for upload/replace/delete | 11 (Q13 deferred) | the same service methods; no second implementation |
| Media library and product media screens, Health display | 12 | `ReferenceGuard::report`, `Formats::health`, `MediaAudit::run` |
| Rendering of media | 13 | `Responsive::sources`, `Loading::hint`, `Gallery::ordered` |
| Gutenberg / Elementor | 15 / 16 | media contracts only; no editor logic in Stage 7 |
| Import/export | 19 (Q15 deferred) | see §17 |
| Audit events | 11 (Q16 deferred) | none emitted by Stage 7 |

## 17. Import/export boundary (Stage 19)
Attachment IDs are **environment-local**. An export must not assume they survive a move between installations; media portability needs a later approved key. A missing attachment on import must fail safely and report, never silently rewrite an owner reference. Stage 7 builds no import or export.

## 18. Test architecture (no tests are created here)
| Group | Tests | Key invariants / edge cases | Expected failure behaviour |
|---|---|---|---|
| Unit | `Sizes`, `Loading`, `Gallery`, `AltText`, `CodeMatcher`, `UploadValidator` | five sizes exact; unknown size throws; duplicates dropped keeping first; blank vs whitespace alt; no-match and ambiguous codes | typed error, never a silent default |
| Format | `Formats` | WebP/AVIF detection; sibling files discovered; **original always present**; conversion failure leaves image usable | health finding, upload still succeeds |
| Responsive | `Responsive` | modern first, fallback present; empty for missing attachment; no hard-coded path | empty list |
| Integration | upload, metadata filter, size generation on a real attachment | five sizes generated; theme size added later does not remove them | upload still succeeds |
| Security | validator, handlers, retention paths | SVG rejected by content and extension; 15 MB, 6000, 24 MP boundaries (at-limit pass, +1 fail); wrong extension; traversal in name; missing nonce; missing capability; wrong object | 4xx-style typed errors, no file written |
| Admin / batch upload | `BulkUploader` | one bad file does not fail the batch; resend only unfinished; per-file result shape; `upload_mimes` filter removed after the call | per-file error entry |
| Replacement | `Replacer`, `RetentionStore` | ID unchanged; references unchanged; **repeat replace keeps two artifacts**; failure restores the original; concurrent replace refused; artifact name pattern | typed failure, original intact |
| Deletion guard | `ReferenceGuard` | blocks when referenced by each source kind (featured, product gallery, category image, option field); allows unreferenced; scan error blocks; hard delete removes artifacts; **drift test** against registered attachment keys | delete blocked |
| Regeneration | `Regenerator` | cursor resume; idempotent re-run; one failing attachment does not stop the batch; no server state written | failure recorded, batch continues |
| Poster | `PosterExtractor` | image accepted at poster size; absence never blocks a reel | no error to the reel |
| Alt text | `AltText` | manual alt preserved; blank filled; no new meta key | no overwrite |
| Audit | `MediaAudit` | each check isolated; paged; on-demand run equals cron run; retention purge honours 30 days | partial report |
| WordPress hook behaviour | `MediaModule::register` | hooks registered once; module id and `requires()`; boots without error with Elementor absent and a stock theme | module absent from registry |
| Regression | Stage 0–6 suites | product write guard unchanged; category contracts unchanged; Blueprint tests 16 and 17 (hero media, category images resolve) | any red = stop |
Counts are not stated because the suite size depends on implementation.

## 19. TDD order [PROPOSAL]
1. Contracts and value types (`ValidationResult`, `LoadingHint`, `GalleryResult`, `ReplaceResult`, `ReferenceReport`).
2. `UploadValidator` and `Sizes`.
3. `Formats` and `Responsive`, `Loading`.
4. `ReferenceSources` with its drift test, then `ReferenceGuard`.
5. `Gallery`.
6. `RetentionStore`, then `Replacer`.
7. `BulkUploader` and `CodeMatcher`.
8. `Regenerator`, `PosterExtractor`, `AltText`.
9. `MediaAudit`.
10. `MediaModule` and the one `Plugin::boot()` line.
11. Cross-stage regression, then the full gates in §22.
Each step starts with a failing test; no step edits a test to make it pass.

## 20. File map
| File | Class | State | Notes |
|---|---|---|---|
| `src/Services/Media/MediaModule.php` | `MediaModule` | NEW | |
| `src/Services/Media/Sizes.php`, `Formats.php`, `Responsive.php`, `Loading.php` | primitives | NEW | |
| `src/Services/Media/UploadValidator.php` | validator | NEW | supporting class derived from Q9 |
| `src/Services/Media/Gallery.php` | | NEW | |
| `src/Services/Media/Replacer.php`, `RetentionStore.php` | | NEW | `RetentionStore` is a supporting class derived from Q7 |
| `src/Services/Media/ReferenceGuard.php`, `ReferenceSources.php` | | NEW | `ReferenceSources` is a supporting class |
| `src/Services/Media/CodeMatcher.php`, `Regenerator.php`, `PosterExtractor.php`, `AltText.php` | | NEW | |
| `src/Services/Media/Value/*.php` | result and context value types | NEW | small immutable classes |
| `src/Admin/Media/BulkUploader.php` | | NEW | |
| `src/Cron/MediaAudit.php` | | NEW | |
| `src/Plugin.php` | | **MODIFY** | add one class to the `Plugin::boot()` array (F3); no other change |
| `tests/unit/Media/*`, `tests/integration/Media/*` | | NEW | |
| `documentation/runbooks/stage-7-gate.md` | | NEW | written at implementation time |
| `Product/*`, `Category/*`, `Data/*`, `Support/*`, `Capabilities.php`, `Meta.php`, `PostTypes.php`, `Options.php`, `rameshwari-core.php`, `build/*`, `composer.*`, theme, optional | | **NO CHANGE** | |
The four supporting classes (`UploadValidator`, `RetentionStore`, `ReferenceSources`, `Value/*`) are not in the Blueprint §18 list. They exist so no service becomes a catch-all; they add no new product behaviour and no persistence.

## 21. Architectural invariants (implementation form)
1. No class under `Services\Media` writes a custom table, option or new meta key.
2. Every persisted media reference is an attachment ID written by its owner.
3. Gallery order is array position only.
4. An original file is never deleted by format conversion.
5. A modern-format failure never makes an image unavailable.
6. A replacement never changes the attachment ID.
7. A referenced attachment is never hard-deleted.
8. No `exec()` or external binary is required.
9. No path is hard-coded; all URLs come from WordPress.
10. `upload_files` and the business capability are both required, both checked server-side.
11. `Plugin::boot()` gains exactly one line; Stage 0–6 behaviour is unchanged.
12. Deferred Q11, Q13, Q15, Q16, Q17, Q18 stay out of the code.

## 22. Forbidden shortcuts
A custom media table or entity; a relationship or join table; a global hash store; hard-coded file paths; a filesystem path as canonical identity; changing Stage 6 gallery rules; a new Stage 7 capability; byte-range resume; persisted processing state; answering a deferred question in code; a catch-all `MediaManager`; skipping a capability check; trusting the filename extension; accepting SVG; deleting a referenced attachment; changing an ID on replace; modern-format-only delivery; editing a test to pass.

## 23. Acceptance gates (future implementation phase)
A scope and file list matches §20 · B architecture compliance against this document and the freeze · C PHP lint · D PHPCS (project config; narrow, reasoned suppressions only) · E PHPStan (project level; no `mixed` crossing into services) · F targeted Stage 7 tests · G full PHPUnit · H regression of Stages 0–6 · I `git diff`, `git diff --check` and scope review · J final implementation audit. None of these has been run for Stage 7; there is no code.

## 24. Traceability matrix
| Blueprint requirement | Architecture § | Owner-lock Q | Component | Test area | Status |
|---|---|---|---|---|---|
| Five registered sizes | §12 | — | `Sizes` | Unit, Integration | Designed |
| WebP/AVIF, originals kept, health | §12 | — | `Formats` | Format | Designed |
| Responsive output | §12, §22 | — | `Responsive` | Responsive | Designed |
| Lazy/eager | §22 | — | `Loading` | Unit | Designed |
| Gallery = array order | §11 | Q4, Q5 | `Gallery` | Unit | Designed |
| Chunked, per-file, resumable bulk upload | §15 | Q8 | `BulkUploader` | Batch upload | Designed (reading in §11.10) |
| Code matcher | §13 | Q9 | `CodeMatcher` | Unit | Designed (lookup [UNVERIFIED]) |
| Replace keeps ID, 30-day old file | §16 | Q7 | `Replacer`, `RetentionStore` | Replacement | Designed |
| Delete guard | §17 | Q2, Q11 | `ReferenceGuard` | Deletion guard | Designed (hook [UNVERIFIED]) |
| Regenerate, batched, admin-runnable | §15 | Q6, Q8 | `Regenerator` | Regeneration | Designed |
| Reel poster capability | §12 | — | `PosterExtractor` | Poster | Designed (host [UNVERIFIED]) |
| Alt text, products never empty | §9 | Q6 | `AltText` | Alt text | Designed |
| Daily audit, 90-day report | §17 | Q11 | `MediaAudit` | Audit | Designed |
| Type/size/dimension limits, SVG | §13, §14 | Q9 | `UploadValidator` | Security | Designed |
| Capability pairing | §14 | Q14 | handlers | Security | Designed |
| Attachment-native identity | §6, §8 | Q1, Q3 | all | all | Designed |
| Module wiring | §5 | — | `MediaModule` | WP hook behaviour | Designed |
| Namespaces | §31 note | Q19 | all | — | Designed |
| Import/export boundary | §27 | Q15 deferred | — | — | Boundary only |

## 25. Final status
```
STAGE 7 IMPLEMENTATION BLUEPRINT STATUS: READY FOR IMPLEMENTATION
ARCHITECTURE:   FROZEN
OWNER LOCK:     11/11 LOCKED
DEFERRED:       Q11, Q13, Q15, Q16, Q17, Q18
CODE:           NOT IMPLEMENTED
```
**Before coding starts, confirm:** (1) the `ProductService` update signature and the `ProductCode`/repository lookup names; (2) that the `pre_delete_attachment` filter blocks single and bulk delete; (3) host support for WebP and AVIF; (4) the page-section mobile and desktop hero image key names (not located in `Meta.php` by my search); (5) the §26 open points. The crop-mode and reel-key items from the first draft are now resolved by CL-3 and D-1.

## 26. Clarifications CL-1 to CL-8 incorporated
Source: owner lock sheet, section "Additional Locked Clarifications". These add no new Q number and reopen no lock. Tags as in §0.

### 26.1 Slot type matrix [FROZEN: CL-1]
| Slot | Type | Output size used |
|---|---|---|
| Product primary (featured image) | image | `card`, `detail`, `thumb` as the renderer asks |
| Product gallery | image | `detail`, `thumb` |
| Category image, category icon | image | `card` / `thumb` |
| Collection cover | image | `card` or `hero`, renderer's choice |
| Showroom gallery | image | `card` / `detail` |
| Testimonial photo | image | `thumb` / `card` |
| Hero desktop image | image | `hero` |
| Hero mobile image | image | see 26.6 |
| Reel | video (`_rj_attachment_id`) + poster image (`_rj_poster_id`) | poster uses `reel poster` |
The output-size column is [PROPOSAL]; only the type column is locked. `UploadValidator` takes a **slot** argument and offers or accepts video only for the reel slot. The uploader shows no Video option for any other slot, and no video field is invented. Enforcement is at the service layer; `Meta.php` is not changed (F7).

### 26.2 Smart processing, crop, source quality [FROZEN: CL-2, CL-3, CL-4]
- Flow per image: validate (§10), then check source quality, then crop and resize to the registered size, then generate modern formats (§7). The administrator may upload any source within the Q9 maxima; an exact-size source is never required.
- **Smart** means deterministic and stored nowhere: a default crop position per size, optionally overridden **for that operation only** through the preview (26.3). No focal-point meta, option or table.
- **Source quality [FROZEN: CL-8, refining CL-4]:** when a source is materially too small, normal validation runs, a clear quality warning is shown, the administrator must explicitly confirm, and the image is never silently upscaled. No numeric minimum-source resolution exists, and an upload is never auto-rejected solely for its dimensions. [PROPOSAL for mechanism: `UploadValidator` returns a `source_too_small` warning when a source is smaller than a registered output on an axis. UNVERIFIED: core WordPress behaviour for cropped sizes; check at implementation.]
- **OP-1 — IMPLEMENTATION NOTE, NOT A NEW LOCK:** a crop adjusted in the preview applies to that upload/operation only; no persistent focal-point field exists; a later `Regenerator` run uses the registered/default crop. Keeping a manual crop permanently means replacing or re-uploading the source.

### 26.3 Crop preview [FROZEN: CL-5]
Client-side: the admin uploader draws the proposed crop for each relevant output on a canvas before confirmation. The preview uses the same dimensions as `Sizes` (one source of truth) and persists nothing. Where the browser cannot render it, the upload proceeds with the default crop and the guidance (26.4) still shows.

### 26.4 Admin guidance [FROZEN: CL-6]
`Services\Media\SlotGuidance` (a supporting class [PROPOSAL]) returns, for a slot: media type; recommended output dimensions and aspect ratio drawn from `Sizes`; processing behaviour in plain words; maximum file size. Images: 15 MB (Q9). It labels registered sizes **"recommended/generated output"** and shows separately that the **required source** is any JPEG, PNG or WebP within the Q9 maxima. Product media lists its registered outputs. Reel media shows video guidance and poster-image guidance as separate blocks. **No video numeric limit is invented**: the video maximum shown is the host's own upload ceiling from `wp_max_upload_size()`, labelled as such; no video dimension, bitrate or duration limit is stated.

### 26.5 Reel audio and playback [FROZEN: CL-7]
Stage 7 stores the uploaded reel video exactly as received: no transcoding, no audio stripping. Muted autoplay, the unmute control and the poster fallback are rendering behaviour owned by Stages 8 and 13. The Stage 7 obligation is only that the original audio track survives upload and that a poster exists or can be generated (§11.8). No new field.
**OP-2 — IMPLEMENTATION CLARIFICATION, NO NEW OWNER LOCK.** The uploaded reel video is `_rj_attachment_id` and must satisfy the existing `video` validator (`Meta.php:68`, type check `video/`). Stage 7 adds no video key, bitrate, duration, codec or capability contract and no extra MIME list. **OP-5 — IMPLEMENTATION UX RULE.** The admin may show the supported media type and the host/WordPress upload ceiling where available; no video size, bitrate, duration, codec or resolution limit is created.

### 26.6 Mobile hero [LATER-STAGE INTEGRATION ITEM, OP-4]
Recommended mobile hero source: **1080×1350, 4:5**, as source/art-direction guidance only. It is **not** registered and **not** a sixth size; the five registered sizes are unchanged. Ownership of the hero mobile and desktop image fields belongs to the Hero/Page Sections stage (Stage 9). Their key names were not located in `Meta.php` by my search, so this blueprint neither names nor modifies them, and makes no binding rendering choice. Classification: LATER-STAGE INTEGRATION ITEM (OP-4), not a Stage 7 lock.

### 26.7 Other updates
- **Reference sources (§11.5):** `ReferenceSources` also lists `_rj_attachment_id` (reel video) and `_rj_poster_id`. The drift test covers any attachment-, `attachments:N`- or `video`-typed key in `Data\Meta`.
- **Audit (§14):** the reel check can now resolve a reel's source from `_rj_source_type`, `_rj_attachment_id` and `_rj_video_id`. The "interface returns not applicable" fallback is no longer needed for `upload` reels.
- **Dependencies (§5):** later modules requiring Stage 7 are Stages 8 and 9. Stage 8 → Stage 6 + Stage 7.

### 26.8 Traceability additions
| Clarification | Component | Test area |
|---|---|---|
| CL-1 | `UploadValidator` slot argument; uploader slot config | Unit, Security |
| CL-2 / CL-4 | `UploadValidator` quality check; processing flow | Unit, Integration |
| CL-3 | `Sizes` crop modes | Unit, Integration (exact output dimensions for all five) |
| CL-5 | uploader preview | Admin |
| CL-6 | `SlotGuidance` | Unit (text distinguishes recommended output from required source; no video numeric limit) |
| CL-7 | upload path leaves video untouched | Integration (audio track preserved) |
| Mobile hero | `Sizes` stays at five | Unit (size list is exactly five; 1080×1350 absent) |
