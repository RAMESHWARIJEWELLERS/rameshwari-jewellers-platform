# Stage 7 — Media Pipeline: Architecture

Status: **FROZEN FOR IMPLEMENTATION.** The 11 implementation-blocking owner decisions are locked (§31). Sections that describe a decision as a proposal are historical context; the owner lock sheet governs. Reconciled against Stage 0–6 repository source on `main` @ `cbb600b` (§4, §34). No code, schema, migration, test or Stage 0–6 behaviour is changed by this document.
Base: `main` @ `cbb600b`. Stage 7 depends on Stage 6 only (Master Plan dependency graph: 7 ← 6).

## 1. Purpose
Define who owns media, how images relate to products, categories, showrooms, reels and hero sections, and how upload, processing, replacement and deletion behave, so Stage 7 can be implemented without inventing contracts.

## 2. Scope
The image pipeline named in Blueprint §18 and Master Plan Stage 7: five registered sizes, WebP/AVIF with originals kept, responsive output, loading policy, gallery ordering, chunked bulk upload, code-based assignment, replace, reference guard, regeneration, reel poster extraction, daily media audit, alt text.

## 3. Non-goals
Reel source resolution and providers (Stage 8), page-section/hero domain (Stage 9), WhatsApp and leads (10), REST route families and Activity Log storage (11), admin screens (12), renderer, theme, Gutenberg, Elementor (13–16), import/export (19). Stage 7 adds no CPT, taxonomy, table or migration.

## 4. Repository contracts consulted
Source-backed rows cite repository files at `main` @ `cbb600b`. Document rows cite the project's approved documents.

| Contract | Origin | What Stage 7 relies on |
|---|---|---|
| One PSR-4 prefix `Rameshwari\Core\` → `src/` | `rameshwari-core.php:143` (`Autoloader::register`); `Support/Autoloader.php` | A class `Rameshwari\Core\Services\Media\Sizes` loads from `src/Services/Media/Sizes.php` with no autoloader change. Segments must be letters, digits or underscores. |
| Module registration is a hard-coded list | `Plugin.php` `boot()` (8 modules today, ending `Product\ProductModule`) | A Stage 7 module is added to that list, which is a one-line edit to a Stage 1 file. The registry orders modules by `requires()`. |
| Existing namespaces | Source tree listing | `Data`, `Support`, `Category`, `Product`, plus `Activator`, `Installer`, `Upgrader` at root. **No `Services\`, `Admin\` or `Cron\` namespace exists yet.** |
| Attachment validators in Meta | `Data/Meta.php:378–388, 564–580` | Type `attachment` checks only that the ID is an `attachment` post (any MIME). Type `image` (`image/`) and `video` (`video/`) exist. `attachments:N` filters a list to attachment posts and caps it at N. |
| Product gallery | `Meta.php:53` (`_rj_gallery`, `attachments:12`); `Product/ProductRepository.php:30` maps `gallery` to `_rj_gallery` | Max 12, attachment posts only |
| Showroom gallery | `Meta.php:92` (`attachments:0`) | Max value as written is 0; whether 0 means unlimited was not verified |
| Category image and icon | `Meta.php:132–133` (`attachment`); `Category/CategoryImporter.php:30` maps `image` to `_rj_image_id` | Attachment IDs |
| Reel video and poster | `Meta.php:68–69` (`video`, `attachment`) | Video MIME enforced; poster is any attachment |
| Other attachment keys found | `Meta.php:76` (`_rj_cover_id`), `Meta.php:97` (`_rj_photo_id`), `Options.php:198` (`default_image_id`) | Owning post types of the first two were not confirmed by my search |
| Capabilities (file read in full) | `Data/Capabilities.php` | Eleven platform capabilities (including `rj_manage_showrooms`); role `rj_catalogue_manager` holds `read`, `upload_files`, `rj_manage_catalogue`, `rj_manage_categories`, `rj_manage_reels`; administrator receives all eleven; `rj_enquiry_agent` and `rj_customer` do not hold `upload_files` |
| Category term-meta auth | `Meta.php:226` | Category term meta needs `rj_manage_categories`; other term meta uses `rj_manage_catalogue` (`235, 240`) |
| Post-type capabilities | `Data/PostTypes.php:47, 65, 83, 117` | Product → `rj_manage_catalogue`; reel → `rj_manage_reels`; two further types → `rj_manage_catalogue` (which types was not confirmed) |
| Media modules, namespaces, behaviour | Blueprint §18 | 13 classes under `Services\Media\*`, `Admin\Media\BulkUploader`, `Cron\MediaAudit` |
| Stage 7 deliverables, gate, host unknown | Master Plan Stage 7 row; "Genuine unknowns" | Five sizes, WebP/AVIF, originals kept, replace keeps ID, guard, audit; WebP/AVIF host support checked by inspection |
| Product owns IDs only | Stage 6 runbook §22; `ProductRepository.php:30` | Stage 7 owns upload, processing, replacement, delete guard |
| Hero and category image tests | Blueprint §24 tests 16–17 | Hero media and category images resolve |

Read in full: `Support/Autoloader.php`, `Plugin.php`, `Data/Capabilities.php`, `Product/ProductModule.php`. Checked by targeted search only: `Data/Meta.php`, `Data/PostTypes.php`, `Data/Options.php`, `Product/ProductRepository.php`, `Category/CategoryImporter.php`, `rameshwari-core.php`. Not inspected beyond the file listing: `Product/ProductData.php`, `Data/Taxonomies.php`.

## 5. Domain ownership
| Area | Owner |
|---|---|
| Attachment records, files, upload handling, size generation primitives | WordPress core |
| Registered sizes, format policy, loading policy, gallery operations, replacement, reference guard, regeneration, poster extraction, media audit, alt text | **Stage 7** |
| Which attachments a product references (`_rj_gallery`, thumbnail) and their count limit | Product Domain (Stage 6), unchanged |
| Category image/icon references | Category Engine (Stage 5), unchanged |
| Reel video attachment and poster reference | Stage 8 reel aggregate; Stage 7 supplies poster generation |
| Hero image references | Stage 9 page sections |
| Audit event storage, REST routes, admin screens, rendering, import/export | Stages 11, 11, 12, 13–16, 19 |
| Namespaces for Stage 7 code (authoritative, Blueprint §18) | `Rameshwari\Core\Services\Media\*`, `Rameshwari\Core\Admin\Media\*`, `Rameshwari\Core\Cron\*` |
| Module wiring | A Stage 7 `Module` (`id()`, `requires()`, `register(Container)`, as `ProductModule` does) added to the `Plugin::boot()` list |

## 6. Media entity model
A "media item" is a WordPress attachment. Stage 7 introduces **no new entity**.
- **Identity:** attachment ID.
- **Original:** the uploaded file, always retained.
- **Derived variants:** the five registered sizes, plus WebP and AVIF where the host supports them.
- **Role:** not stored on the attachment. A role (featured, gallery, category image, icon, reel poster, hero) is defined by which owner references it and in which field.
- **Order:** array position in the owner's list.

## 7. Product ↔ media relationship
- Product → media: one featured image plus up to 12 gallery images (existing contract).
- Media → product: an attachment may be referenced by several owners. The reference guard counts references across all owners.
- Ownership of the relationship: the owner's metadata field. No join table.
- Persistence: metadata, as already registered in Stage 4. Locked by Q1–Q3 (owner lock sheet).

## 8. Attachment model
| | A. WordPress attachment native | B. Custom persistence |
|---|---|---|
| Storage | `attachment` posts, `wp_get_attachment_metadata`, uploads directory | New table or CPT for media records |
| Matches existing contracts | Yes: every Stage 4/6 reference is already an attachment ID | No: existing references would need migration |
| Core tooling (media library, size generation, REST `/wp/v2/media`) | Works | Duplicated or bypassed |
| Hosting portability | Standard | Adds schema to migrate |
| Cost | Needs a place for any state WordPress does not hold (§9, §15) | Highest |

Decision: **A** (native attachment). Locked by Q3 and Q1 in the owner lock sheet.

## 9. Media metadata
Classification only. **No meta keys are created by this document.**

| Field | Class | Source |
|---|---|---|
| Attachment ID | required, immutable | WordPress |
| Owner and field | required, derived | owner's reference |
| Sort order | required, mutable | array position |
| MIME, dimensions, byte size | derived, immutable per file | WordPress attachment metadata |
| Alt text | required for product images, mutable | WordPress alt field; generated by `AltText` when blank (Blueprint §18) |
| Caption | optional, mutable | WordPress |
| Source, focal point | not in approved contracts | not defined; do not add |
| Processing status, validation status | needed only if processing is asynchronous or resumable | no approved storage; Q8 |

## 10. Primary image
Canonical source of truth: the **post thumbnail** of the owner (Stage 6 §22). `_rj_gallery` holds the additional images. Display sequence is primary first, then gallery order. The service never stores the primary a second time in the gallery. Q4.

## 11. Gallery ordering
- Order is the `_rj_gallery` array order. No sort field (Blueprint §18).
- Insert, remove and reorder are whole-array writes through `Gallery`, validated for existence and the 12 limit.
- Duplicates within an array are removed, first occurrence kept.
- Missing attachments are skipped at read, never reordered.
- Fallback when the array is empty: the primary only; if neither exists, the audit reports it and the renderer shows no image slot (no placeholder invented).

## 12. Asset and derivative architecture
| Layer | Stage 7 scope | Later |
|---|---|---|
| Original | always kept | |
| Registered sizes | thumb 300², card 600², detail 1200×1500, hero 1920×1200, reel poster 720×1280 | |
| WebP | on upload where the host supports it | |
| AVIF | where available | |
| Normalised or "optimized" original | not in the approved contract | do not build |
| Fallback | JPEG with a Health warning when WebP/AVIF is not possible | |

Output is source sets from the registered sizes; modern format with a fallback, never modern-only (Blueprint §18).

## 13. Validation (upload)
Only values the approved documents give are stated as requirements. Everything else is either locked by the owner lock sheet (Q7–Q10, Q12, Q14) or a design detail of this architecture that the lock sheet does not restate.

| Rule | Basis |
|---|---|
| Image classes accepted: JPEG, PNG, WebP | **LOCKED** (Q9, owner lock sheet) |
| MIME checked by file content, never by extension or client header | Master Plan Stage 8 states this rule for reels; proposed to apply to images too |
| Extension must agree with detected MIME | LOCKED (Q9) |
| Size and dimension limits | not in any approved document; numbers proposed in Q9 |
| Filename normalised to a safe form | design detail, not restated in the lock sheet |
| Duplicate detection | none (LOCKED, Q10) |
| SVG | rejected (LOCKED, Q9) |
| Alt text never empty on product images | Blueprint §18 |

## 14. Security
| Threat | Control (design detail unless a lock is cited) |
|---|---|
| Unauthorised upload | Capability required on every write path; mapping in Q14 |
| Capability bypass via REST | Writes go through `Gallery`/`Replacer`; Stage 6 meta guards already refuse invalid `_rj_gallery` writes |
| Metadata tampering | Stage 4 sanitiser and Stage 6 validation on `_rj_gallery`; existence checked server-side |
| MIME spoofing, dangerous types | Content-based type check, allow-list, rejection of executables and SVG |
| Path traversal, arbitrary file write | Only WordPress upload APIs write files; no path accepted from a request; no stored paths (Blueprint §18) |
| Media enumeration | Native `/wp/v2/media` is readable for attachments of published posts; documented as a known exposure, narrowed only if the owner requires |
| Unauthorised attachment access | No protected media in the approved contracts; Q12 |

## 15. Lifecycle
States: `uploaded → validated → accepted → processing → ready`, with `failed`, `replaced`, `deleted`. These are **conceptual** states of one operation. None is persisted by this proposal.
- **accepted** and **ready** are observable from the attachment and its generated files; **failed** is observable as a missing derivative, repaired by `Regenerator`.
- Each uploaded file is processed independently and inline.
- **Batch resume (proposed):** the bulk uploader sends files in batches, the browser keeps the list of finished files, and an interrupted run re-sends only the unfinished ones. No single sixty-image shoot is one transaction.
- **Not claimed:** byte-range (partial-file) upload resume. Nothing in the approved documents or repository source requires it; a file interrupted mid-transfer is simply sent again.
- No processing-status meta key is proposed. If the owner later wants server-side retry, that is a new decision and a new key.

## 16. Replacement
- Replace original: swap the file behind the attachment, keep the ID, regenerate variants (Blueprint §18). Every reference updates at once.
- Old file retained 30 days (Blueprint §18). **LOCKED (Q7, owner lock sheet):** a *filesystem retention artifact*, kept outside the canonical reference model.
  - **Location:** a plugin-owned subdirectory under the WordPress uploads base from `wp_upload_dir()`. No hard-coded path.
  - **Name:** `{attachment-id}-{unix-timestamp}-{random-token}-{sanitized-basename}`. The ID is an integer, the basename passes `sanitize_file_name`, and the token stops URL guessing.
  - **Several replacements in 30 days:** each swap writes one artifact with its own timestamp, so none overwrites another. A same-second collision is resolved by the unique-name helper.
  - **Restore discovery:** list the directory entries beginning `{attachment-id}-`, newest timestamp first. No database lookup.
  - **Restore:** the reverse swap. The current file becomes a new artifact first, so a restore is itself undoable within the window.
  - **Cleanup:** the daily `MediaAudit` removes artifacts older than 30 days, by filename timestamp (file modification time as fallback), in bounded batches.
  - **Not a reference:** artifacts are never counted by `ReferenceGuard` and never appear in any owner field.
  - **No new DB table, no new meta key.**
  - **Hard delete:** deleting an attachment (`delete_attachment`) also deletes its artifacts, so no orphan files remain.
  - **Rollback:** restore within 30 days uses the artifact; after that, recovery is from backup.
  - **Security:** paths are built only from the integer ID and a sanitised name and checked to stay inside the retention directory. The directory gets an empty index file and a deny rule; whether a deny rule is honoured depends on the host and web server, so on some hosts retained originals could be reachable by URL; the random token only mitigates this. No host-specific behaviour is claimed.
- Replace primary: same operation; the owner's thumbnail ID does not change.
- Reorder and detach: gallery array rewrite; the attachment is untouched.
- Delete: only through the reference guard (§17).

## 17. Deletion and orphans
| Event | Behaviour |
|---|---|
| Delete a referenced attachment | Blocked; the guard lists referencing owners (Blueprint §18) |
| Product trashed | References remain; trash is reversible |
| Product hard-deleted | References disappear with the product; the attachment becomes unreferenced, not deleted |
| Attachment deleted when unreferenced | Allowed |
| Unreferenced for 90 days | Reported by `MediaAudit`, never auto-deleted (Blueprint §18) |
| Detach from a gallery | Never deletes the attachment |
| Rollback | Stage 7 has no data rollback ledger; recovery is replacement from the 30-day copy or restore from backup |

## 18. Failure and recovery
| Failure | Behaviour |
|---|---|
| WebP/AVIF generation fails | Fallback to JPEG, Health warning (Master Plan unit test) |
| One size fails | The variant is omitted; source sets list only existing files; `Regenerator` repairs it |
| Broken or missing original | Reported by audit; the owner reference is kept, rendering skips it |
| Missing attachment in a gallery | Skipped at read, reported by audit |
| Interrupted bulk upload | Batch resume: finished files are not repeated (Master Plan test). Not byte-range resume (§15, Q8) |
| Retry | Idempotent; `Regenerator` is batched and resumable |

## 19. Performance and cache
- No N+1: gallery reads batch-prime attachment and metadata caches for all IDs of a listing.
- Request-local memoisation of resolved sizes per attachment.
- `Support\Cache` with a version bump on gallery writes if a persistent cache is justified by measurement (Stage 6 §21 already defers caching).
- Bulk listings read summary data only; originals are never emitted in cards.
- Admin and REST payloads carry IDs and a size map, not full metadata, unless asked.
- Hero and first row eager and prioritised, below-fold lazy, dimensions declared (Blueprint §18). Targets: UNSPECIFIED beyond the Master Plan gates.

## 20. REST / API boundary
No routes are added in Stage 7. Read model: attachment IDs on owner resources (already exposed by Stage 4 meta registration); URLs are generated from IDs. Future write model: owner endpoints (Stage 11) call `Gallery`, `Replacer` and `ReferenceGuard`; conflicts such as deleting a referenced attachment map to HTTP 409 with code `rj_conflict`, consistent with existing conflict semantics. Gutenberg and Elementor read the same resolved output.

## 21. Admin UX boundary
Backend behaviour only: select, reorder, set primary, replace, remove, validation errors with the offending rule named, and processing results per file. Screens are Stage 12.

## 22. Renderer boundary
Media supplies resolved attachment IDs, size maps and `Responsive` source sets. The Shared Renderer outputs markup from them. The renderer holds no media state and performs no lookups of its own.

## 23. Gutenberg boundary
Blocks consume the Stage 7 read model through the Shared Renderer. No block attribute stores a URL or path.

## 24. Elementor boundary
The `rj-product-gallery` dynamic tag (Blueprint §13) reads `_rj_gallery`. Elementor's own gallery widgets receive attachment IDs. Elementor stores no media truth. Pro is not required.

## 25. Download behaviour
Public image URLs are generated from attachment IDs and served from the WordPress uploads directory. The original is retained and publicly reachable like any uploaded file unless Q12 decides otherwise. No protected-media mechanism is defined in the approved documents.

## 26. Observability
Event names for future logging (storage is Stage 11): media uploaded, replaced, reordered, primary assigned, removed, deleted, validation failed, processing failed. Stage 7 may emit them through a hook with no listener until Stage 11. Q16.

## 27. Import / export compatibility
Future only. Attachment IDs are environment-specific and not portable. Stage 19 will need a portable media key (filename, content hash, or both). Stage 7 constrains nothing and stores nothing new for it. Missing media on import must degrade to "no image" with a report, never a failed import. Q15.

## 28. Rollback implications
No Stage 7 ledger. Replacement keeps the old file 30 days. Deletion is blocked while referenced. Variant loss is repaired by regeneration.

## 29. Architecture invariants
1. Theme, Elementor and Gutenberg are never the media source of truth.
2. The Product Domain stays the product owner; the Category Engine stays the category owner.
3. Every reference is an attachment ID; no stored path or URL (Blueprint §18).
4. Gallery order is array order; no sort field.
5. Sizes are registered by the plugin, not the theme.
6. Originals are always kept.
7. One authoritative owner for media lifecycle: Stage 7 services.
8. No new CPT, taxonomy, table, migration or media meta key. The only new persistent storage proposed is the Q7 retention directory (files); it is not a media reference and is not database state.
9. No renderer-specific media storage.
10. No duplicate media state: processing status is not persisted; if a later decision adds it, it gets exactly one place.
11. Stage 6 gallery limit (12) and existence checks are not relaxed.
12. No behaviour from a later stage is built early.

## 30. Forbidden shortcuts
Storing a URL or filesystem path; adding a sort-order field; writing media state from the theme or a widget; deleting an attachment without the guard; trusting client MIME or extension; building import matching, REST routes, admin screens or audit storage inside Stage 7; adding media meta keys before they are locked.

## 31. OWNER DECISIONS
**Status: the 11 implementation-blocking owner decisions are LOCKED.** The authoritative source for each locked decision is the Stage 7 owner lock sheet, `documentation/runbooks/stage-7-media-pipeline-owner-lock.md`. This architecture document incorporates those decisions by reference and does not restate their full text. Where this document's earlier proposal wording differs from the lock sheet, the lock sheet governs. Question titles and numbering below are unchanged.

### Locked (11/11)
| Q | Question | State | Authoritative source |
|---|---|---|---|
| Q1 | Attachment ownership | **LOCKED** | `documentation/runbooks/stage-7-media-pipeline-owner-lock.md` |
| Q2 | Cardinality | **LOCKED** | `documentation/runbooks/stage-7-media-pipeline-owner-lock.md` |
| Q3 | Native attachment vs custom persistence | **LOCKED** | `documentation/runbooks/stage-7-media-pipeline-owner-lock.md` |
| Q4 | Primary image model | **LOCKED** | `documentation/runbooks/stage-7-media-pipeline-owner-lock.md` |
| Q6 | Metadata model | **LOCKED** | `documentation/runbooks/stage-7-media-pipeline-owner-lock.md` |
| Q7 | Retention of replaced originals (30 days) | **LOCKED** | `documentation/runbooks/stage-7-media-pipeline-owner-lock.md` |
| Q8 | Processing and resume | **LOCKED** | `documentation/runbooks/stage-7-media-pipeline-owner-lock.md` |
| Q9 | Accepted types and limits | **LOCKED** | `documentation/runbooks/stage-7-media-pipeline-owner-lock.md` |
| Q10 | Duplicate media | **LOCKED** | `documentation/runbooks/stage-7-media-pipeline-owner-lock.md` |
| Q12 | Public vs private media | **LOCKED** | `documentation/runbooks/stage-7-media-pipeline-owner-lock.md` |
| Q14 | Capabilities | **LOCKED** | `documentation/runbooks/stage-7-media-pipeline-owner-lock.md` |

### Authoritative existing contracts (not owner decisions)
| Q | Item | State |
|---|---|---|
| Q5 | Gallery and image ordering | **AUTHORITATIVE EXISTING CONTRACT — BLUEPRINT** (§18: array order, no sort field) |
| Q19 | Stage 7 namespaces | **RESOLVED — BLUEPRINT §18** (`Services\Media\*`, `Admin\Media\*`, `Cron\*`) |

### Deferred (6)
| Q | Question | State |
|---|---|---|
| Q11 | Deletion and orphans | **DEFERRED** |
| Q13 | REST mutation boundary | **DEFERRED** |
| Q15 | Import/export identity | **DEFERRED** |
| Q16 | Audit events | **DEFERRED** |
| Q17 | Rollback | **DEFERRED** |
| Q18 | Caching | **DEFERRED** |

Q11, Q13, Q15, Q16, Q17 and Q18 are deferred and are **not implementation-blocking** for the currently frozen scope. They stay outside the locked implementation contract unless separately resolved later. They are not answered here.

### Count
17 open proposals originally; 11 implementation-blocking owner decisions, **11 now LOCKED**; 6 deferred; Q5 authoritative; Q19 resolved; unresolved questions beyond these = 0. Total 11 + 6 + 2 = 19, matching Q1–Q19.

### Historical note
Earlier drafts of this section listed all questions as proposals with a "Lock before implementation?" column. That wording is superseded by the lock sheet. The original proposal text is preserved in the lock sheet's sections A and B.

## 32. Implementation-phase boundary
The 11 implementation-blocking owner decisions are now locked (§31; `documentation/runbooks/stage-7-media-pipeline-owner-lock.md`). The Stage 7 architecture boundary is therefore **cleared**, and this architecture is **frozen for the locked implementation scope**.

Implementation may proceed only according to, in this order of authority:
1. the frozen Stage 7 architecture (this document);
2. the Stage 7 owner lock sheet (`documentation/runbooks/stage-7-media-pipeline-owner-lock.md`);
3. the Development Blueprint (§18 and related sections);
4. existing Stage 0–6 contracts.

Stage 7 adds classes under `src/Services/Media/`, `src/Admin/Media/` and `src/Cron/` (Q19), registers the five sizes, and adds one module to the `Plugin::boot()` list. It does not change `ProductService`, `Meta.php` contracts or Stage 5 code except through documented hooks. The only new persistent storage is the Q7 filesystem retention directory, which is files, not database state.

**Conflicts.** Any conflict found during implementation triggers an architecture review. It is not resolved by an undocumented code-level override.

**Deferred scope.** Q11, Q13, Q15, Q16, Q17 and Q18 remain outside the locked implementation contract unless separately resolved later. Implementation does not answer them silently.

### Final status
```
STAGE 7 ARCHITECTURE STATUS: FROZEN FOR IMPLEMENTATION
LOCKED DECISIONS:            11/11
DEFERRED:                    Q11, Q13, Q15, Q16, Q17, Q18
Q5:                          AUTHORITATIVE — BLUEPRINT
Q19:                         RESOLVED — BLUEPRINT §18
```

## 33. Future-stage dependencies
Stage 8 (reel video and posters), Stage 9 (hero images), Stage 11 (REST and audit storage), Stage 12 (screens), Stages 13–16 (rendering), Stage 19 (portable identity).

## 34. Traceability
**Source-backed** (existing contract or approved document):

| Rule | Origin |
|---|---|
| Five sizes, plugin-registered | Master Plan Stage 7; Blueprint §18 |
| Originals kept; WebP/AVIF with JPEG fallback and Health warning | Master Plan; Blueprint §18 |
| Gallery order = array order, no sort field | Blueprint §18 |
| References are attachment IDs only | Blueprint §18; Stage 6 §22; `Meta.php:53, 68–69, 132–133` |
| Product gallery ≤ 12; attachment-post validation only | `Meta.php:53, 378–388, 580`; Stage 6 §9 |
| Replace keeps the attachment ID; old file retained 30 days | Blueprint §18 |
| Reference guard blocks deletion | Master Plan; Blueprint §18 |
| Unreferenced attachments reported after 90 days | Blueprint §18 |
| Reel poster generation | Blueprint §18; regression 4 |
| Namespaces `Services\Media\*`, `Admin\Media\*`, `Cron\*` | Blueprint §18; autoload via `rameshwari-core.php:143` |
| Module wiring through the `Plugin::boot()` list | `Plugin.php`; `Product/ProductModule.php` |
| Existing capabilities, role and `upload_files` | `Data/Capabilities.php` |
| Native attachment storage | Architectural consequence of the rows above |

**Locked by the owner lock sheet (Q7, Q8, Q9, Q10, Q12, Q14) or deferred (Q16, Q18):**

| Rule | Question |
|---|---|
| Filesystem retention artifact for replaced originals | Q7 |
| Inline per-file processing; client-driven batch resume; no byte-range claim | Q8 |
| JPEG, PNG, WebP; SVG rejected; 15 MB; 6000 × 6000 px; 24 MP; content-based type check | Q9 |
| Duplicate media allowed; no content-hash index | Q10 |
| Capability pairings for product, category and reel media | Q14 |
| Request-local caching only until measured | Q18 (deferred) |
| Audit events through a hook | Q16 (deferred) |
| All media public | Q12 |

**Source inspection record.** Read in full: `Support/Autoloader.php`, `Plugin.php`, `Data/Capabilities.php`, `Product/ProductModule.php`. Targeted search only: `Data/Meta.php`, `Data/PostTypes.php`, `Data/Options.php`, `Product/ProductRepository.php`, `Category/CategoryImporter.php`, `rameshwari-core.php`. Not inspected beyond the file listing: `Product/ProductData.php`, `Data/Taxonomies.php`. The Blueprint and the Stage 6 runbook were read from the project's copies. This document changes no behaviour in code.
