# Stage 7 Media Pipeline — Owner Lock Sheet

Status: **ALL 11 REQUIRED OWNER DECISIONS LOCKED.** The six deferrable proposals remain deferred.
Base: `main` @ `cbb600b`. Branch: `stage-7-media-pipeline-architecture`.
Source of the proposals: `documentation/runbooks/stage-7-media-pipeline-architecture.md` §31, reproduced verbatim below. That document is unchanged.

Path note: the Stage 6 document in the repository is `documentation/runbooks/stage-6-product-domain-architecture.md`, not `stage-6-product-domain.md`.

## Counting
- **Q5** = authoritative existing contract (array order, Blueprint §18). Not an owner decision.
- **Q19** = resolved existing contract (namespaces, Blueprint §18). Not an owner decision.
- **17 open proposals** originally.
- **11 owner decisions required before implementation, now all LOCKED:** Q1, Q2, Q3, Q4, Q6, Q7, Q8, Q9, Q10, Q12, Q14.
- **6 deferrable proposals remain deferred:** Q11, Q13, Q15, Q16, Q17, Q18. They are not blockers.
- Unresolved questions beyond these: **0**.

Every record separates **A. existing repository or Blueprint contract**, **B. Stage 7 proposal**, and **C. owner decision still required**.

## Owner decision records

### Q1 — Attachment ownership

Status: LOCKED

**A. Source-backed facts:**
- `Support/Autoloader.php` and `Plugin.php`: no Media code or owner exists yet.
- Blueprint §18: 13 media classes own sizes, formats, gallery, replace, guard, regenerate, audit.
- Stage 6 runbook §22: Product holds attachment IDs only; Stage 7 owns upload, processing, replacement and the delete guard.

**B. Current Stage 7 proposal** (verbatim from architecture §31):
> Core owns the record; Stage 7 owns lifecycle operations; owners own references

**Technical effect:** Decides who may write attachments and who enforces the guard. Every later Media class depends on it.

**Alternatives (only those the repository or Blueprint supports):**
- None beyond the proposal is supported: WordPress core already owns the attachment record, and Stage 6 §22 assigns lifecycle to Stage 7.

**RECOMMENDED DEFAULT (superseded by the lock below):** the proposal in B.

**C. FINAL OWNER CHOICE:** LOCKED — WordPress core owns the attachment record, the file, upload handling and the native attachment lifecycle. Stage 7 owns the media-pipeline behaviour defined for Stage 7: registered media sizes, format handling, responsive and loading behaviour, gallery behaviour, replacement, the reference guard, regeneration, poster extraction, the audit and alt-text behaviour. Stage 7 does not become the owner of the native attachment entity, and no second ownership layer is created.

**OWNER NOTES:** Now authoritative for Stage 7 implementation. The A (source-backed facts) and B (original proposal) sections above are kept unchanged as history and are not rewritten by this lock.

**LOCK STATUS:** LOCKED

### Q2 — Cardinality

Status: LOCKED

**A. Source-backed facts:**
- `Meta.php:53`: `_rj_gallery` on product, `attachments:12`; featured image is the post thumbnail (Stage 6 §22).
- `Meta.php:92`: showroom gallery `attachments:0`.
- `Meta.php:132–133`: category image and icon, one attachment each.
- No code makes an attachment exclusive to one owner.

**B. Current Stage 7 proposal** (verbatim from architecture §31):
> Product has 1 featured + ≤ 12 gallery; an attachment may be shared; the guard counts every owner

**Technical effect:** Controls how `ReferenceGuard` counts references and whether replacing a file can affect several owners at once.

**Alternatives (only those the repository or Blueprint supports):**
- Exclusive attachments per owner: no mechanism for it exists in the repository, so it would need new state.

**RECOMMENDED DEFAULT (superseded by the lock below):** the proposal in B.

**C. FINAL OWNER CHOICE:** LOCKED — Owner-to-media relationships remain owner-side metadata references containing attachment IDs. No separate relationship or join table is introduced by Stage 7. A single attachment may be referenced by multiple owners.

**OWNER NOTES:** Now authoritative for Stage 7 implementation. The A (source-backed facts) and B (original proposal) sections above are kept unchanged as history and are not rewritten by this lock.

**LOCK STATUS:** LOCKED

### Q3 — Native attachment vs custom persistence

Status: LOCKED

**A. Source-backed facts:**
- Blueprint §18: "Every reference is an attachment ID; every URL is generated."
- All registered media keys in `Meta.php` (53, 68–69, 76, 92, 97, 132–133) already hold attachment IDs.
- Blueprint and Master Plan add no media table.

**B. Current Stage 7 proposal** (verbatim from architecture §31):
> Native (§8); no schema

**Technical effect:** Whether Stage 7 needs schema or a migration. Native attachments need neither.

**Alternatives (only those the repository or Blueprint supports):**
- Custom persistence (architecture §8, option B): would require a table or post type and migration of existing references.

**RECOMMENDED DEFAULT (superseded by the lock below):** the proposal in B.

**C. FINAL OWNER CHOICE:** LOCKED — WordPress native Attachment ID is the canonical media identity and source of truth. Stage 7 creates no custom media entity and no custom media table. No custom persistence layer replaces the native attachment model.

**OWNER NOTES:** Now authoritative for Stage 7 implementation. The A (source-backed facts) and B (original proposal) sections above are kept unchanged as history and are not rewritten by this lock.

**LOCK STATUS:** LOCKED

### Q4 — Primary image model

Status: LOCKED

**A. Source-backed facts:**
- Stage 6 runbook §22: featured image (post thumbnail) plus `_rj_gallery` (≤ 12).
- Blueprint §18: gallery order is array order, no sort field.
- `Meta.php:53`: gallery validated as attachment posts only.

**B. Current Stage 7 proposal** (verbatim from architecture §31):
> Post thumbnail is canonical; gallery excludes it

**Technical effect:** Defines what the gallery contains, how the renderer sequences images, and whether the service strips the primary from the gallery.

**Alternatives (only those the repository or Blueprint supports):**
- Treat the first gallery item as primary: supported by the array-order rule but contradicts the Stage 6 featured-image statement, so it would need a Stage 6 amendment.

**RECOMMENDED DEFAULT (superseded by the lock below):** the proposal in B.

**C. FINAL OWNER CHOICE:** LOCKED — The WordPress featured image (`thumbnail`) is the primary product image. `_rj_gallery` stores additional ordered images. Rendering order is the primary image first, then `_rj_gallery` array order.

**OWNER NOTES:** Now authoritative for Stage 7 implementation. The A (source-backed facts) and B (original proposal) sections above are kept unchanged as history and are not rewritten by this lock.

**LOCK STATUS:** LOCKED

### Q6 — Metadata model

Status: LOCKED

**A. Source-backed facts:**
- `Meta.php` registers every attachment-bearing key; none is a per-attachment key.
- Blueprint §18 names no media metadata keys.
- Stage 4 meta contract is protected.

**B. Current Stage 7 proposal** (verbatim from architecture §31):
> No new media meta keys

**Technical effect:** Whether Stage 7 touches `Meta.php`. Any new key is a Stage 4 contract addition with tests.

**Alternatives (only those the repository or Blueprint supports):**
- New attachment-level meta keys: possible through the Stage 4 registry only, with an owner-approved key list.

**RECOMMENDED DEFAULT (superseded by the lock below):** the proposal in B.

**C. FINAL OWNER CHOICE:** LOCKED — Media metadata stays minimal. Attachment ID is canonical. MIME type, dimensions and file size are derived from WordPress attachment and file data. Ordering comes from the owner array position. Derived metadata is not duplicated unnecessarily. Product image alt text must not remain blank at final validation; the Blueprint §18 alt-text generation rule applies. Stage 7 introduces no new canonical media meta-key model.

**OWNER NOTES:** Now authoritative for Stage 7 implementation. The A (source-backed facts) and B (original proposal) sections above are kept unchanged as history and are not rewritten by this lock.

**LOCK STATUS:** LOCKED

### Q7 — Retention of replaced originals (30 days)

Status: LOCKED

**A. Source-backed facts:**
- Blueprint §18 (Replacer): "Swaps the file behind an attachment, keeping the ID… Old file retained 30 days."
- The Blueprint does not say where the old file is kept.
- `rameshwari-core.php`/`Plugin.php`: no retention storage exists.

**B. Current Stage 7 proposal** (verbatim from architecture §31):
> **PROPOSAL — OWNER APPROVAL REQUIRED.** Filesystem retention artifact keyed by attachment ID plus timestamp, outside the reference model (§16)

**Security caveat (kept):** whether a deny rule on the retention directory is honoured depends on the host and web server; the random token only mitigates URL guessing. No host-specific behaviour is claimed.

**Technical effect:** Controls `Replacer`, the restore path, the daily `MediaAudit` cleanup and `delete_attachment` handling. Retention artifacts are files under the uploads location, not canonical media references and not database state.

**Alternatives (only those the repository or Blueprint supports):**
- Record the old file in attachment meta (adds a key) or in an option (adds stored state). Neither is in the Blueprint.

**RECOMMENDED DEFAULT (superseded by the lock below):** the proposal in B.

**C. FINAL OWNER CHOICE:** LOCKED — Replacement retention uses filesystem retention artifacts under `wp_upload_dir()`. Retained filenames contain the attachment ID, a timestamp and a random token, and repeated replacements never overwrite each other. Retention artifacts are not canonical media references, are not stored in a new database table or a new canonical media meta field, are retained for 30 days, and are removed when the attachment is hard-deleted. The host and web-server access-control caveat stays; a deny rule is not claimed to be universally effective.

**OWNER NOTES:** Now authoritative for Stage 7 implementation. The A (source-backed facts) and B (original proposal) sections above are kept unchanged as history and are not rewritten by this lock.

**LOCK STATUS:** LOCKED

### Q8 — Processing and resume

Status: LOCKED

**A. Source-backed facts:**
- Master Plan Stage 7: "A 60-image upload over a slow connection completes or resumes."
- Blueprint §18 (BulkUploader): chunked, per-file progress, resumable; a sixty-image shoot must not fail as one transaction.
- No processing-status field exists in `Meta.php`.

**B. Current Stage 7 proposal** (verbatim from architecture §31):
> Inline per file; **batch resume**, client-tracked; no persisted status; no byte-range resume claim (§15)

**Distinction (kept):** (1) per-file processing, (2) client-driven batch resume, (3) byte-range upload resume. The proposal includes (1) and (2) and does **not** claim (3). No processing-status persistence unless a later decision approves it.

**Technical effect:** Shapes `Admin\Media\BulkUploader` and `Regenerator`. Batch resume is client-tracked; byte-range resume is a different mechanism and is not claimed.

**Alternatives (only those the repository or Blueprint supports):**
- Server-side stored processing status: needs a new key or table, so it is a Stage 4 or Stage 3 contract change.

**RECOMMENDED DEFAULT (superseded by the lock below):** the proposal in B.

**C. FINAL OWNER CHOICE:** LOCKED — Stage 7 uses per-file processing and client-driven batch resume. Batch resume and byte-range resume remain separate concepts. Stage 7 does not claim byte-range upload or processing resume and introduces no persistent server-side resumability state.

**OWNER NOTES:** Now authoritative for Stage 7 implementation. The A (source-backed facts) and B (original proposal) sections above are kept unchanged as history and are not rewritten by this lock.

**LOCK STATUS:** LOCKED

### Q9 — Accepted types and limits

Status: LOCKED

**A. Source-backed facts:**
- Master Plan Stage 7 and Blueprint §18 give five output sizes and WebP/AVIF; **no input type or numeric limits are specified anywhere**.
- `Meta.php:378–388`: type `attachment` accepts any MIME; types `image` and `video` exist.
- Master Plan Stage 8 requires reel MIME verified by file content.

**B. Current Stage 7 proposal** (verbatim from architecture §31):
> **PROPOSAL — OWNER APPROVAL REQUIRED.** JPEG, PNG, WebP; SVG rejected; max 15 MB, max 6000 × 6000 px, max 24 MP; type from file content, extension must agree, client MIME never trusted. These numbers are **not** from the Blueprint. Enforcement at the service layer; `Meta.php` (type `attachment` accepts any MIME) stays untouched unless the owner approves a Stage 4 contract change

**All numeric limits above are Stage 7 proposals, not Blueprint facts.**

**Technical effect:** Controls the upload validator. The numbers below are Stage 7 proposals, not Blueprint facts.

**Alternatives (only those the repository or Blueprint supports):**
- Different limits supplied by the owner, or SVG allowed with sanitisation (no sanitiser exists in the repository).
- Tightening `Meta.php` to type `image`: a Stage 4 contract change.

**RECOMMENDED DEFAULT (superseded by the lock below):** the proposal in B.

**C. FINAL OWNER CHOICE:** LOCKED — Accepted image input: JPEG, PNG, WebP. Rejected: SVG. Limits: maximum file size 15 MB, maximum dimensions 6000 × 6000 pixels, maximum 24 MP. These numeric limits are Stage 7 owner decisions, not Blueprint facts. File content and type detection is authoritative, and the filename extension must agree with the detected content type.

**OWNER NOTES:** Now authoritative for Stage 7 implementation. The A (source-backed facts) and B (original proposal) sections above are kept unchanged as history and are not rewritten by this lock.

**LOCK STATUS:** LOCKED

### Q10 — Duplicate media

Status: LOCKED

**A. Source-backed facts:**
- Blueprint §18 describes no duplicate detection.
- `Meta.php` stores no content hash.

**B. Current Stage 7 proposal** (verbatim from architecture §31):
> **Allowed.** No global content-hash index, no hash meta or table; the same file may be referenced by several owners. Reconsider only by a future explicit decision

No global hash store; no hash-warning behaviour.

**Technical effect:** Decides whether any hash state is created. The proposal creates none.

**Alternatives (only those the repository or Blueprint supports):**
- Reject or warn on duplicates by content hash: needs a hash store (a new key or table).

**RECOMMENDED DEFAULT (superseded by the lock below):** the proposal in B.

**C. FINAL OWNER CHOICE:** LOCKED — Duplicate media is allowed. No global hash index or store is introduced. No persistent duplicate-warning state is implemented.

**OWNER NOTES:** Now authoritative for Stage 7 implementation. The A (source-backed facts) and B (original proposal) sections above are kept unchanged as history and are not rewritten by this lock.

**LOCK STATUS:** LOCKED

### Q12 — Public vs private media

Status: LOCKED

**A. Source-backed facts:**
- Blueprint §18: URLs are generated from attachment IDs; no protected-media mechanism is described.
- Uploads are served from the WordPress uploads directory.

**B. Current Stage 7 proposal** (verbatim from architecture §31):
> All public; protected media not designed

**Technical effect:** Decides whether any access control is designed for originals.

**Alternatives (only those the repository or Blueprint supports):**
- Protected originals: no design exists in the Blueprint or repository.

**RECOMMENDED DEFAULT (superseded by the lock below):** the proposal in B.

**C. FINAL OWNER CHOICE:** LOCKED — Stage 7 uses a public catalog-media model. Product, category and reel presentation media are normal WordPress attachment media served through generated WordPress URLs. Stage 7 introduces no private-media storage contract; any future private-media requirement is a separate architectural decision.

**OWNER NOTES:** Now authoritative for Stage 7 implementation. The A (source-backed facts) and B (original proposal) sections above are kept unchanged as history and are not rewritten by this lock.

**LOCK STATUS:** LOCKED

### Q14 — Capabilities

Status: LOCKED

**A. Source-backed facts:**
- `Data/Capabilities.php` (read in full): eleven platform capabilities including `rj_manage_catalogue`, `rj_manage_categories`, `rj_manage_reels`, `rj_manage_showrooms`; role `rj_catalogue_manager` holds `read`, `upload_files` and the first three.
- `Meta.php:226`: category term meta requires `rj_manage_categories`.
- `PostTypes.php:47, 65`: product requires `rj_manage_catalogue`; reel requires `rj_manage_reels`.

**B. Current Stage 7 proposal** (verbatim from architecture §31):
> **PROPOSAL — OWNER APPROVAL REQUIRED.** No new capability. Product media: `upload_files` + `rj_manage_catalogue`. Category image and icon: `upload_files` + `rj_manage_categories` (matches `Meta.php:226`). Reel media and poster: `upload_files` + `rj_manage_reels` (matches `PostTypes.php:65`). `rj_manage_showrooms` also exists, but showroom, collection, testimonial and hero media pairings are decided with Stages 8 and 9. **Upload permission** (`upload_files`, a WordPress core capability) is separate from the **business-operation permission** (the `rj_manage_*` capability)

**Upload permission:** `upload_files` (WordPress core). **Business-operation permission:** the `rj_manage_*` capability. No new Stage 7 capability. Showroom, collection, testimonial and hero pairings stay with the stages named in architecture §5.

**Technical effect:** Controls every media write path. Later stages decide showroom, collection, testimonial and hero pairings.

**Alternatives (only those the repository or Blueprint supports):**
- A different pairing chosen from the eleven existing capabilities. A new capability would change the capability map applied at activation.

**RECOMMENDED DEFAULT (superseded by the lock below):** the proposal in B.

**C. FINAL OWNER CHOICE:** LOCKED — No new Stage 7 capability. Upload permission: `upload_files`. Business-operation permissions: product media `rj_manage_catalogue`; category image and icon `rj_manage_categories`; reel media and poster `rj_manage_reels`. Showroom, collection, testimonial and hero capability pairings remain owned by their respective later stages.

**OWNER NOTES:** Now authoritative for Stage 7 implementation. The A (source-backed facts) and B (original proposal) sections above are kept unchanged as history and are not rewritten by this lock.

**LOCK STATUS:** LOCKED

## Decision Traceability Matrix

| Question | Architecture section | Blueprint section | Repository evidence | Decision status | Implementation impact |
|---|---|---|---|---|---|
| Q1 | §5, §6 | §18 | `Plugin.php`, `Autoloader.php` | LOCKED | Ownership of all Media writes |
| Q2 | §7 | §9, §18 | `Meta.php:53, 92, 132–133` | LOCKED | `ReferenceGuard` counting |
| Q3 | §8 | §18 | `Meta.php` attachment keys | LOCKED | No schema if native |
| Q4 | §10 | §18 | Stage 6 §22; `Meta.php:53` | LOCKED | `Gallery`, renderer sequence |
| Q6 | §9 | §9 | `Meta.php` | LOCKED | Whether Stage 4 meta changes |
| Q7 | §16, §17 | §18 | none (mechanism unspecified) | LOCKED | `Replacer`, cleanup, restore |
| Q8 | §15, §18 | §18 | no status key in `Meta.php` | LOCKED | `BulkUploader`, `Regenerator` |
| Q9 | §13 | §18 (sizes only) | `Meta.php:378–388` | LOCKED | Upload validator |
| Q10 | §13 | not specified | no hash stored | LOCKED | Whether hash state exists |
| Q12 | §25 | §18 | uploads directory | LOCKED | Download policy |
| Q14 | §14 | not specified | `Capabilities.php`, `Meta.php:226`, `PostTypes.php:47, 65` | LOCKED | All Media write permissions |

## History note
The first draft of this sheet placed four lock texts under the wrong question numbers (the Q1, Q3, Q6 and Q8 texts were cross-numbered). The sheet is now normalised to the authoritative architecture question numbers: Q1 attachment ownership, Q2 relationship model, Q3 native attachment vs custom persistence, Q4 primary image, Q6 metadata model, Q7 replacement retention, Q8 processing and resume, Q9 validation, Q10 duplicate media, Q12 public vs private media, Q14 capabilities. The obsolete duplicated text is not kept under any other number. Architecture §31 is unchanged.

## Owner Lock Summary
| Group | Questions | Count | State |
|---|---|---|---|
| Authoritative existing contract | Q5 (ordering, Blueprint §18) | 1 | not an owner decision |
| Resolved existing contract | Q19 (namespaces, Blueprint §18) | 1 | not an owner decision |
| Required owner decisions | Q1, Q2, Q3, Q4, Q6, Q7, Q8, Q9, Q10, Q12, Q14 | 11 | **LOCKED 11/11** |
| Deferred proposals | Q11, Q13, Q15, Q16, Q17, Q18 | 6 | remain deferred, not blockers |

17 open proposals originally; 11 required and now locked; 6 deferred; unresolved questions beyond these = 0. Implementation-blocking Stage 7 decisions are complete.

## Owner Lock Procedure (completed for the 11 required decisions)
The 11 required decisions have explicit owner choices recorded above. A later step produces the final architecture freeze. Changing any locked decision requires a new explicit owner decision.


## Additional Locked Clarifications — Media UX and Slot Policy
BASE OWNER LOCKS: **11/11 — UNCHANGED** (Q1, Q2, Q3, Q4, Q6, Q7, Q8, Q9, Q10, Q12, Q14).
ADDITIONAL LOCKED CLARIFICATIONS: **CL-1 through CL-8 — LOCKED** (8 clarifications; CL-8 is in the section "Additional Locked Clarifications — Source Quality").
DEFERRED: **Q11, Q13, Q15, Q16, Q17, Q18** (unchanged). Q5 remains the authoritative existing contract (Blueprint §18). Q19 remains resolved (Blueprint §18).
These clarifications use CL numbers so the Q1–Q19 numbering is untouched. No new Q number exists.

### CL-1 — Media slot type policy
**LOCKED.** Image only: hero; product primary; product gallery; category image; category icon; collection cover; showroom gallery; testimonial photo; page-section hero desktop image; page-section hero mobile image. Reel: video plus a poster image. Where video is not in an object's existing field and schema contract, the admin uploader offers no Video option, and no new video field is created for image-only objects.

### CL-2 — Smart image processing
**LOCKED.** The administrator need not upload a file matching the final output dimensions. Pipeline: validate, smart crop, resize, generate the required output. The five registered output contracts are preserved; no extra public size is added to support this.

### CL-3 — Crop policy
**LOCKED.** thumb 300×300 hard crop; card 600×600 hard crop; detail 1200×1500 soft/subject-preserving crop; hero 1920×1200 soft/subject-preserving crop; reel poster 720×1280 hard crop. A soft crop still produces the exact registered dimensions. No persistent focal-point model.

### CL-4 — Source quality
**LOCKED.** A source materially too small for an acceptable result is not blindly enlarged. The system surfaces a validation warning. No numeric minimum-source resolution is invented; none exists in the Blueprint. Behaviour is fixed by CL-8.

### CL-5 — Crop preview
**LOCKED.** Where a crop or resize is relevant, the admin upload workflow shows a preview of the resulting output before final confirmation, where the implementation context permits. No persistent focal-point metadata field.

### CL-6 — Admin media guidance
**LOCKED.** Every upload slot shows, before upload: media type; recommended output or source dimension; aspect ratio where applicable; processing behaviour; applicable maximum file size. For registered image outputs it distinguishes "recommended/generated output" from "required source upload size", and never says an exact-size source is mandatory unless the architecture requires it. Product media shows the relevant registered outputs. Reel media shows video guidance separately from poster-image guidance. No unsupported numeric video limits are invented.

### CL-7 — Reel audio and playback
**LOCKED.** Uploaded reel video keeps its original audio track. Autoplay is allowed and starts muted; the visitor can unmute, and the original audio then plays. If browser or platform policy blocks autoplay, playback falls back gracefully to the poster / play state. This is not autoplay with sound, and creates no new reel-video metadata field.

### Resolved corrections
- **D-1 — RESOLVED, from the existing reel schema:** `_rj_attachment_id` = uploaded reel video attachment (validator `video`, `Meta.php:68`); `_rj_video_id` = provider video ID (`alnum`, `Meta.php:67`); `_rj_poster_id` = poster attachment (`attachment`, `Meta.php:69`); `_rj_source_type` = `enum:upload|instagram|youtube|url` (`Meta.php:65`). No other video-attachment key is created. The frozen architecture row citing `Meta.php:68–69` was correct; an earlier blueprint claim that no uploaded-video key existed was wrong (see the blueprint).
- **D-2 — RESOLVED, dependency correction only:** Stage 8 consumes Stage 7 media capabilities in addition to its Stage 6 product-domain dependencies. The Stage 8 dependency is **Stage 6 + Stage 7**. No domain-contract change.

### Mobile hero recommendation
Recommended mobile hero source/composition: **1080×1350, 4:5**. This is recommended art direction, **not** a new registered Stage 7 size. The registered sizes stay exactly the five above.

### Cross-impact audit
| Clarification | Existing lock affected? | Conflict? | Resolution |
|---|---|---|---|
| CL-1 | Q9 (accepted types), Q12 | No. Q9 covers image input; CL-1 states which slots are image-only. Reel video is already in the existing reel schema. | Enforced at the service layer; `Meta.php` (`attachment` accepts any MIME) is untouched. |
| CL-2 | Q9 limits | No. The 15 MB / 6000 × 6000 / 24 MP maxima still apply; this adds a processing flow. | Uses the five frozen output contracts; adds no size. |
| CL-3 | Frozen sizes, Q6 | No, because soft crop still produces the frozen registered output dimensions. | Policy governs crop position only; no stored focal point. |
| CL-4 | Q9 | No. Q9 sets maxima only; CL-4 adds a warning for too-small sources, refined by CL-8. | Warning with confirmation; no numeric minimum invented. |
| CL-5 | Q6, Q8 | No. Preview is transient, so nothing is persisted. | Client-side preview; a crop adjusted in preview applies to that upload only. |
| CL-6 | Q9, Q14 | No. Guidance is display only and changes no limit or capability. | Single guidance source derived from the registered sizes and Q9. |
| CL-7 | Q1, Q2, Q3 | No, because audio playback behaviour does not change attachment identity or cardinality. | Playback is a rendering concern (Stages 8, 13); Stage 7 stores the original file unchanged. |
| 1080×1350 mobile hero | Frozen five sizes | No conflict because it is guidance and source art direction, not a new registered output size. | Not registered; see blueprint §26. |
| D-1 | Q5 / reel schema | No. | Resolved from the existing reel schema. |
| D-2 | Stage 6, Stage 7 locks | No. Dependency correction only; no domain-contract change. | Stage 8 depends on 6 and 7. |
No existing Stage 0–6 or Stage 7 owner lock needs to be reopened.

| CL-8 | Q9 (limits), CL-4 | No. Q9 sets maxima only; CL-8 defines behaviour for a too-small source and adds no numeric contract. | Warn, require confirmation, never silently upscale, never auto-reject on dimensions alone. |

## Additional Locked Clarifications — Source Quality

### CL-8 — Source Quality Confirmation Policy
**LOCKED.** When a source image is materially too small for the requested output:
1. normal validation runs;
2. a clear quality warning is shown;
3. the administrator must explicitly confirm to continue;
4. the image is **not** silently upscaled;
5. **no** new numeric minimum-source-resolution contract is introduced;
6. the upload is **not** automatically hard-rejected solely because of source dimensions.

Reason: the project has several output sizes, and a fixed numeric minimum could reject legitimate source compositions while still not guaranteeing visual quality. CL-8 refines CL-4 and leaves Q9's maxima unchanged. No Q number was added.

### Open-point classification (no new locks)
| Point | Classification |
|---|---|
| OP-1 crop preview vs later regeneration | IMPLEMENTATION NOTE, NOT A NEW LOCK. A crop adjusted in the preview applies to that upload/operation only. No persistent focal-point field exists. Later regeneration uses the registered/default crop. Keeping a manual crop means replacing or re-uploading the source. |
| OP-2 uploaded reel video validation | IMPLEMENTATION CLARIFICATION, NO NEW OWNER LOCK. The uploaded video is `_rj_attachment_id` (existing `video` validator, `Meta.php:68`); `_rj_video_id` is the provider ID; `_rj_poster_id` is the poster. No new video key, bitrate, duration, codec or capability contract. |
| OP-3 small-source behaviour | LOCKED as CL-8. |
| OP-4 mobile hero | LATER-STAGE INTEGRATION ITEM, NOT A NEW STAGE 7 LOCK. 1080×1350 (4:5) stays recommended art direction; the five registered sizes are unchanged. Hero page-section ownership belongs to the later Hero/Page Sections stage. |
| OP-5 video guidance | IMPLEMENTATION UX RULE, NOT A NEW OWNER LOCK. The admin may show the supported media type and the host/WordPress upload ceiling where available. No bitrate, duration, codec, resolution or file-size contract is created. |

### Counting
Base owner locks **11/11**. Additional media clarifications **CL-1 through CL-8 = 8 locked**. Deferred: **Q11, Q13, Q15, Q16, Q17, Q18**. Q5 authoritative (Blueprint §18). Q19 resolved (Blueprint §18). No new Q numbers. D-1 and D-2 remain resolved.
