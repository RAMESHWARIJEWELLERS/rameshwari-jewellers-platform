# Stage 6 — Product Domain: Architecture and Contract Lock

Status: OWNER-LOCKED and design-complete. All 11 owner decisions, the 4 derived decisions and Option A (the publication marker, §35) are final. No implementation, no migration, no commit.
Evidence base: `main` at b9098415e971 (Stages 0–5).
Prerequisite outside the design: the branch `stage-6-product-domain` is not yet on GitHub and must be pushed before implementation.

## 0. Owner decision record
| # | Decision | Locked value |
|---|---|---|
| Q1 | Product Code format | Existing sanitiser only: uppercase, `A–Z 0–9 -`, maximum 64. No new format rule. **Immutable after the product is published.** |
| Q2 | Product Code uniqueness | Unique across **all** products: draft, published, archived and trashed. |
| Q3 | Canonical naming | `_rj_name_en` and `_rj_name_hi` are the canonical name fields. `post_title` is the WordPress presentation title and stays synchronised with the English name. |
| Q4 | Metal cardinality | At most one `rj_metal` term at all times. Exactly one is required to publish. |
| Q5 | Purity cardinality | At most one `rj_purity` term at all times. Exactly one is required to publish. |
| Q6 | Required to publish | Product Code, English name, Hindi name, exactly one valid Metal, exactly one valid Purity, at least one valid editorial (non-root, non-resolver) category. Weight is optional. |
| Q7 | `rj_product_index` | Stage 6 neither owns nor creates it. No migration. |
| Q8 | `post_status` and `_rj_visibility` | Independent axes. Neither replaces the other. |
| Q9 | Archived | `_rj_visibility = archived` is a visibility state only. No new WordPress post status. |
| Q10 | Hallmark | No field or metadata in Stage 6. Deferred to a future explicit schema decision. |
| Q11 | Duplicate Product Code over REST | HTTP 409, error code `rj_conflict`. No custom product routes; those stay in Stage 11. |

### Derived decisions (confirmed by the owner)
| # | Decision | Locked value |
|---|---|---|
| D1 | Product Code immutability | After a product has been published its Product Code is permanently immutable, including after it returns to draft or is trashed. Any change attempt is rejected as HTTP 409 `rj_conflict`. Durable source: the publication marker `_rj_first_published_at`, read only through `has_ever_been_published()` (§35). |
| D2 | Product Code freeing | A code becomes reusable only after its product is hard-deleted. Trash does not free it. Hard delete is administrator-only, so only an administrator hard delete releases a code. |
| D3 | Public visibility | `post_status` and `_rj_visibility` stay independent axes. A product is publicly visible only when `post_status` is `publish` **and** `_rj_visibility` is `public`. `hidden` and `archived` prevent public visibility even while published. |
| D4 | Metal and purity cardinality | At most one `rj_metal` term and at most one `rj_purity` term at all times. Drafts may hold zero of either. Exactly one of each is required at publication. |

### Owner decision on the D1 blocker
| # | Decision | Locked value |
|---|---|---|
| A | Publication marker (Option A) | Exactly one new private, system-owned `rj_product` meta key, `_rj_first_published_at`, durably records that a product has been published at least once. D1 is unchanged. This is a documented Stage 4 contract amendment (§35); `Data/Meta.php` is the only Stage 4 contract file permitted to change. |

## 1. Stage 6 objective
Make `rj_product` a governed domain: deterministic identity (Product Code), a repository, domain rules, and one server-side path for category and facet assignment that consumes the Stage 5 Category Engine. Stage 6 establishes contracts and the domain layer. It builds no admin screens, media pipeline, renderer, import or table.

## 2. Repository evidence inspected
| Source | Finding |
|---|---|
| `Data/PostTypes.php` | `rj_product` exists: public, `has_archive` `jewellery`, rewrite `product`, `show_in_rest` with `rest_base` `products`, `capability_type` `rj_product/rj_products`, `map_meta_cap` true. Edit and publish need `rj_manage_catalogue`; every delete needs `manage_options`. Supports title, editor, thumbnail, excerpt, revisions, custom-fields, page-attributes. |
| `Data/Meta.php` | 14 `rj_product` keys already registered (§7). `filter_weight` strips weight from product REST responses when `rj_display.show_weight_publicly` is off, unless the reader can edit the post. |
| `Data/Taxonomies.php` | `rj_category` (hierarchical, `rest_base` `rj-categories`, rewrite `c`; assign_terms needs `rj_manage_catalogue`) attaches to `rj_product`. Flat taxonomies `rj_metal`, `rj_purity`, `rj_occasion`, `rj_audience`, `rj_collection_tax`, `rj_tag` also attach to `rj_product`. |
| `Data/Capabilities.php` | 11 platform capabilities; `rj_catalogue_manager` holds `rj_manage_catalogue`, `rj_manage_categories`, `rj_manage_reels`. |
| `src/Category/*` (Stage 5) | `AssignmentGuard`, `CategoryRepository`, `ResolverEngine`, `CategoryRules` (hooks the guard to `rest_pre_insert_rj_product`), `MenuTree`, picker. |
| `Support/*` (Stage 2) | `Sanitizer`, `Cache`, `Lock`, `Str` available. |

## 3. Contracts that must not change
Stage 4: six CPTs, seven taxonomies, registered meta, option groups, secret protection, rewrites, lifecycle. Stage 5: 306 terms, five roots, full-path identity, duplicate names, Latin slugs, Model B, `_rj_resolver`, 22 mappings, HTTP 409 `rj_conflict`, `rj-categories`, `/c/`, MenuTree and picker. Stage 2 and 3 primitives and schema. The single amendment is the publication marker (§35).

## 4. Product domain model
- **Canonical entity:** the existing CPT `rj_product`. No new CPT.
- **Domain state (mutable):** names, weight and unit, visibility, featured flag, category, metal, purity and other facet assignments, gallery references. Product Code is mutable only until first publication (§5).
- **System-owned:** `_rj_view_count`, `_rj_enquiry_count` and the publication marker `_rj_first_published_at`. Private, not in REST, never written by product editing. The marker is written only by the service: at the first transition to publication for a new product, or once as a legacy backfill for a product already published before the amendment (§35).
- **Presentation:** `post_title` is derived from `_rj_name_en` (§6). `_rj_whatsapp_message` and `_rj_making_note` are stored but not interpreted; WhatsApp is Stage 10.

## 5. Product identity contract
| Rule | Decision |
|---|---|
| Identity key | Product Code (`_rj_code`). The post ID is a runtime identifier, never portable. |
| Normalisation and format | The existing `Meta::sanitize('code')`: uppercase, keep `A–Z`, `0–9`, `-`, cut to 64. Nothing else is added. |
| Case sensitivity | Insensitive. Stored uppercase; lookups compare the normalised form. |
| Uniqueness (Q2, D2) | Across all products in every status, trash included. Trash does not free a code. A code is freed only when its product is hard-deleted, which is administrator-only, so only an administrator hard delete releases a code. |
| Collision behaviour | Reject with HTTP 409 `rj_conflict` over REST (a typed domain error elsewhere). Never auto-suffix, never auto-repair. |
| Immutability (D1) | Once the product has been published, its Product Code is permanently immutable, including after it is moved back to draft or trashed. Any change attempt is rejected as HTTP 409 `rj_conflict`. Before first publication the code may change, and the uniqueness rule still applies. The durable fact is the publication marker `_rj_first_published_at`, read through `has_ever_been_published()` (§35). |
| Regeneration | None. |
| Import behaviour | A future import matches on the normalised code only (§23). |

## 6. Product fields
| Field | Representation | Rule |
|---|---|---|
| Product Code | `_rj_code` | §5 |
| English name | `_rj_name_en` | Canonical; required to publish; ≤ 191 |
| Hindi name | `_rj_name_hi` | Canonical; required to publish; ≤ 191 |
| Title | `post_title` | Presentation only. The service writes `post_title` from `_rj_name_en` on every create and update. A different title submitted through the editor or REST is overwritten, never trusted. |
| Metal | `rj_metal` term | At most one at all times; exactly one required to publish |
| Purity | `rj_purity` term | At most one at all times; exactly one required to publish |
| Categories | `rj_category` terms | At least one editorial leaf or group to publish (§9) |
| Approximate weight | `_rj_weight` (0–99999, 3 decimals) and `_rj_weight_unit` (`g`/`tola`/`carat`, default `g`) | Optional. Public exposure follows `rj_display.show_weight_publicly` |
| Description | `post_content` | Optional |
| Stone details | `_rj_stone_details` (500) | Optional |
| Hallmark | **None.** Deferred to a future explicit schema decision (Q10). |
| Publication marker | `_rj_first_published_at` | System-owned, private, written once (§35). It is the real first-publication time for new products; a legacy backfill value is not a historical publication time. Not an editable field. |

No new business attribute is introduced. Under D4 a product holds at most one metal and at most one purity at all times; drafts may hold zero of either, and exactly one of each is required at publication.

## 7. Product metadata (all exist except the one approved publication marker)
| Key | Rule | REST | Notes |
|---|---|---|---|
| `_rj_code` | code | yes | identity; unique; immutable after first publication |
| `_rj_name_hi`, `_rj_name_en` | text 191 | yes | canonical names |
| `_rj_weight`, `_rj_weight_unit` | decimal / enum | yes, filtered | C-11 |
| `_rj_gallery` | attachments, max 12 | yes | Media relationship (§22) |
| `_rj_stone_details` | textarea 500 | yes | |
| `_rj_making_note`, `_rj_whatsapp_message` | textarea | **no** | private |
| `_rj_visibility` | `public`/`hidden`/`archived`, default `public` | yes | independent of `post_status` |
| `_rj_featured` | bool, default false | yes | |
| `_rj_primary_term` | term in `rj_category` | yes | must be an assigned editorial category |
| `_rj_view_count`, `_rj_enquiry_count` | int ≥ 0 | **no** | system-owned |
| `_rj_first_published_at` | datetime (existing rule), default empty | **no** | system-owned; no client can create, change, clear or delete it. Real first-publication time for new products; legacy backfill time for products already published before the amendment (§35). |

Authorization is `edit_post` on the object (Stage 4). Direct meta writes bypass domain rules, so all writes go through the repository and service.

## 8. Registration design
One additive registration: the publication marker, in `Data/Meta.php` (the approved Stage 4 exception, §35). `PostTypes`, `Taxonomies` and every other Stage 4 file stay untouched. Stage 6 adds a **domain module** registered through the existing `ModuleRegistry`, depending on `post_types`, `taxonomies`, `meta` and `category`. Its WordPress adapter is `ProductGuard`, which intercepts every direct write path (§36).

## 9. Category and facet assignment rules
| Target | Rule |
|---|---|
| Editorial leaf or group category | Allowed. |
| Root term (the five roots) | Rejected, 409 `rj_conflict`. |
| Resolver term (the 22 navigation terms) | Rejected, 409 `rj_conflict`. |
| Term that does not exist | Rejected. |
| Primary term | Must be one of the product's assigned editorial categories. |
| Metal | At most one `rj_metal` term at all times; a second is rejected. Exactly one is required to publish (§16). |
| Purity | At most one `rj_purity` term at all times; a second is rejected. Exactly one is required to publish (§16). |
| Occasion, audience, tag, collection link | Not constrained by Stage 6 beyond existing capability checks. |

Server-side validation is authoritative on every write path: REST, classic admin save, direct term assignment, bulk, import. UI hiding is not security.

## 10. Integration with the Stage 5 Category Engine
Stage 6 calls `AssignmentGuard`; it does not copy its rules. It also closes the Stage 5b audit's non-blocking limits: `_rj_primary_term` is sanitised only for existence in Stage 4, so the product service applies the editorial rule before persisting, and non-REST write paths reach the same guard. `CategoryRepository` remains the only reader of category data.

## 11. Repository contract (`ProductRepository`)
Same conventions as the Stage 5 repository; persistence only.
| Method | Behaviour |
|---|---|
| `create(ProductData): int` | Insert the post and registered meta; returns the ID or a typed error. |
| `update(int, ProductData): void` | Update only supplied allow-listed fields. |
| `find(int)` / `find_by_code(string)` | Read model or null. `find_by_code` normalises, and searches every status including trash. |
| `code_exists(string, ?int $except)` | Uniqueness test across every status including trash. |
| `trash(int)` | Moves the post to trash. |
| `set_visibility(int, string)` | Writes `_rj_visibility` (`public`, `hidden`, `archived`). |
| `assigned_categories(int)` / `set_categories(int, array)` | Persistence only; validation happens before the call. |
No hard-delete method, no category rules, no REST formatting, no HTML.

## 12. Domain service and value objects
- `ProductCode`: normalise and validate.
- `ProductData`: explicit allow-list input (no mass assignment).
- `ProductService`: validate → uniqueness → immutability → name sync → category and facet rules → publication-required check → repository. Returns a typed result (success, validation errors, conflict).
- Reuse `AssignmentGuard`; no new services for Media, WhatsApp or Lead.

## 13. REST boundary
The native collection `/wp/v2/products` exists from Stage 4. Stage 6 defines behaviour only:
- Reads are unchanged. Weight stays filtered.
- Writes are authorised by `edit_post` / `rj_manage_catalogue`, then the service rules apply.
- Duplicate Product Code, attempted change of a published code, and prohibited category assignment all return HTTP 409 `rj_conflict`.
- A missing required-to-publish field returns a validation error (§16).
- **No custom routes.** `GET /rj/v1/products`, `/by-code/{code}` and `/search` belong to Stage 11.

## 14. Admin boundary
Stage 12 owns the product list, forms, bulk actions and search. The Stage 5 picker already covers category choice. Stage 6 adds no screens; it supplies the service they will call.

## 15. Security boundary
Capabilities: `rj_manage_catalogue` to edit, `manage_options` to delete. Protected fields: `_rj_view_count`, `_rj_enquiry_count`. Risks and answers: mass assignment → allow-list; arbitrary taxonomy assignment → guard on all paths; Product Code tampering → uniqueness plus immutability after publication; title spoofing → `post_title` overwritten from the English name; XSS → names are text-sanitised and the description uses WordPress's `post_content` filtering; numeric fields → range-checked by the sanitiser; weight leakage → C-11 applies to any new read model; publication marker tampering → refused at the key's authorisation callback, the meta filters and the input allow-list (§35).

## 16. Validation rules
| Rule | Applies |
|---|---|
| Code normalises and is unique | every create and update |
| Code unchanged once `has_ever_been_published()` is true (D1) | every update |
| Names ≤ 191 characters | every write |
| Weight 0–99999, 3 decimals; unit in enum | every write |
| Visibility in enum | every write |
| Gallery ≤ 12 attachments | every write |
| At most one metal, one purity | every write |
| Categories obey §9 | every write |
| Required to publish: Product Code, English name, Hindi name, one valid metal, one valid purity, at least one valid editorial category | transition to `publish` |
Weight is never required.

## 17. Error contract
| Condition | Result |
|---|---|
| Prohibited category, duplicate Product Code, change of a published code | HTTP 409, `rj_conflict` |
| Missing required-to-publish field | Validation error; the product stays unpublished; nothing partially saved |
| Invalid value | Sanitiser result plus a validation error |
| Not found | Null from the repository; 404 at the REST boundary |
| Client attempt to create, change, clear or delete the publication marker | REST or admin: HTTP 403 from the key's authorisation callback. PHP meta call: refused, nothing changes. Distinct from the 409 for a Product Code change. |

## 18. Lifecycle
Two independent axes, neither replacing the other:
- **`post_status`:** WordPress states (draft, publish, trash). No new status is introduced.
- **`_rj_visibility`:** `public`, `hidden`, `archived`. "Archived" is a visibility value only.

Under D3 a product appears publicly only when `post_status` is `publish` **and** `_rj_visibility` is `public`. `hidden` and `archived` prevent public visibility even while the post is published. Trashing uses `post_status`; archiving uses `_rj_visibility`. Under D2, hard delete remains administrator-only by the capability map, and only a hard delete releases the Product Code; trash does not. The first transition to `publish` sets the publication marker (a product already published before the amendment receives a legacy backfill marker instead), and no later transition clears it (§35).

## 19. Migration and schema impact
No table and no migration. The marker is one post-meta key created at first publication (or as a legacy backfill). The `rj_product_index` table already exists from the Stage 3 schema (`Schema.php`, with a unique key on `code`). Under Q7 Stage 6 neither owns, creates, populates nor reads it; its population belongs to a later stage.

## 20. Backward compatibility
Touch points: `rest_pre_insert_rj_product` hook (Stage 5), `Meta::filter_weight` (Stage 4), the `_rj_primary_term` sanitiser (Stage 4), `rj_product` capability mapping, `/products` response shape, rewrite `product`, archive `jewellery`, and `post_title` now being derived. Every Stage 0–5 test must pass unchanged, with one consequence of the amendment: the expected count of registered `rj_product` meta keys in `MetaTest` (currently 14) rises to 15 (§35).

## 21. Cache implications
No cache in Stage 6. Reads are single-post lookups. A cache contract is added only when Stage 11 discovery needs it, using Support\Cache with a version bump on product writes.

## 22. Future Media relationship
Product holds attachment IDs only: featured image (`thumbnail`) and `_rj_gallery` (≤ 12). Stage 7 owns upload, processing, replacement and the delete guard.

## 23. Future Import/Export compatibility
Portable identity is the normalised Product Code, plus category paths (never term IDs) and facet slugs. Stage 6 provides `find_by_code` and `ProductData` as the only write surface an importer may use. Hallmark is not part of the import contract until a future schema decision.

## 24. Test strategy
Integration tests against real WordPress (the repository's pattern) and unit tests for `ProductCode` and `ProductData`. Test names are fixed at implementation time; no counts are claimed here.

## 25. Unit-test matrix (planned)
`ProductCode` normalisation, empty and over-long codes, case folding; `ProductData` allow-list rejects unknown keys; weight bounds; enum fallbacks.

## 26. Integration-test matrix (planned)
Registration unchanged; create, find, find-by-code, update, trash, set visibility; duplicate code rejected in every status including trash; case-variant duplicate rejected; code change rejected after publication, including after return to draft; code change allowed before first publication; `post_title` overwritten from the English name; a second metal and a second purity rejected; publication refused for each missing required field; publication allowed with no weight; leaf category accepted; root, resolver and missing terms rejected with 409; primary-term editorial rule; classic and direct-write paths reach the guard; `post_status` and `_rj_visibility` set independently; archived is not a post status; REST write authorisation per role; weight hidden when the switch is off; sanitisation of every field; no hallmark meta registered; theme independence; trash does not free a Product Code and a hard delete does (D2); a published code stays immutable after return to draft and after trash, each rejected with 409 `rj_conflict` (D1); a published product with `hidden` or `archived` visibility is not publicly visible, and only publish plus `public` is (D3); a draft may hold zero metal and zero purity, a second of either is rejected, and publication needs exactly one of each (D4). Publication-marker tests: §35.

## 27. Regression matrix (planned)
All 22 mappings resolve; 306 terms and five roots intact; MenuTree and picker tests; Stage 4 registry, meta, options and rewrite tests; Stage 5b guard tests; schema unchanged (no new table).

## 28. Requirements traceability
| Requirement | Source | Response | Future files | Planned tests |
|---|---|---|---|---|
| Product Code format = existing sanitiser | Owner lock Q1; `Meta::sanitize('code')` | §5 | `ProductCode` | normalisation tests |
| Code immutable after publication | Owner lock Q1 | §5, §16 | `ProductService` | immutability tests |
| Code unique in every status | Owner lock Q2; Development Blueprint §24 list | §5, §11 | `ProductService`, `ProductRepository` | duplicate and trash tests |
| Names canonical, title synchronised | Owner lock Q3 | §6 | `ProductService` | title-overwrite test |
| One metal, one purity | Owner lock Q4, Q5; Navigation Facet Decision (facts in facets) | §6, §9 | `ProductService` | cardinality tests |
| Required-to-publish set | Owner lock Q6 | §16 | `ProductService` | publication tests |
| No table or migration | Owner lock Q7 | §19 | none | schema-unchanged test |
| Independent status and visibility | Owner lock Q8, Q9 | §18 | `ProductRepository` | independence tests |
| No hallmark | Owner lock Q10 | §6 | none | no-hallmark-meta test |
| 409 `rj_conflict` for duplicates | Owner lock Q11; Stage 5 lock | §13, §17 | `ProductService` | REST conflict tests |
| Prohibited assignment returns 409 | Stage 5 lock | §9, §10 | `ProductService` | root, resolver, missing-term tests |
| Weight hidden unless `show_weight_publicly` | C-11 | §7 | none (exists) | weight REST tests |
| Hard delete admin-only | Stage 4 capability map | §11, §18 | none | capability test |
| D1 Permanent code immutability, 409 `rj_conflict` (durable source: §35) | Owner derived lock D1 | §5, §13, §17 | `ProductService` | immutability after draft and after trash |
| D2 Only hard delete frees a code | Owner derived lock D2 | §5, §11, §18 | `ProductRepository` | trash-keeps-code and hard-delete-frees-code tests |
| D3 Public only when publish and `public` | Owner derived lock D3 | §18 | `ProductRepository` | hidden and archived visibility tests |
| D4 At most one, exactly one to publish | Owner derived lock D4 | §6, §9, §16 | `ProductService` | draft-zero, second-term and publication tests |
| Option A: private system-owned publication marker | Owner decision on the D1 blocker | §7, §35 | `Data/Meta.php`, `ProductService`, `ProductGuard` | the marker tests in §35 |

## 29. Files expected to change during implementation
New: `src/Product/{ProductCode,ProductData,ProductRepository,ProductService,ProductGuard,ProductModule}.php`; tests under `tests/unit/` and `tests/integration/`. Modified: `Plugin.php` (one module entry); `Data/Meta.php` (one additive key and its refusing authorisation callback, the approved Stage 4 exception); `tests/integration/MetaTest.php` (the expected `rj_product` key count only). No migration and no schema file.

## 30. Files forbidden to change
`Data/{PostTypes,Taxonomies,Options,Rewrites,Capabilities,Schema}.php`, and `Data/Meta.php` except for the single approved addition in §35, `Data/Migrations/*`, `src/Category/*`, `data/categories.json`, Support classes. A needed change is a separate, justified contract change.

## 31. Acceptance criteria
Every planned test passes; lint, PHPCS and PHPStan are clean; no Stage 0–5 test is changed; no new CPT, taxonomy, meta key, option, table or migration; all write paths enforce §9; every Q1–Q11 decision and every derived decision D1–D4 is covered by a test; no custom REST route; no media, WhatsApp, admin, renderer, import or hallmark code; the publication marker is the only new meta key, is private, cannot be written, cleared or deleted by any client, and survives every lifecycle transition; every rule in §36 is enforced on every listed write path.

## 32. Non-goals
Media pipeline, reels, showrooms, testimonials, hero, WhatsApp and leads, activity log, admin screens, renderer, theme, Gutenberg, Elementor, accounts, notifications, import/export, chatbot, WooCommerce coupling, pricing, stock, hallmark, `rj_product_index`.

## 33. Open questions
None. Everything in §0, including Option A, is locked.

## 34. Final architecture decision
Product is the existing `rj_product` CPT; every field and metadata key already exists, apart from the one approved publication marker. Stage 6 adds only a domain layer in `src/Product/` that enforces Product Code uniqueness and post-publication immutability, keeps `post_title` in step with the English name, requires exactly one metal and one purity, and sends every category assignment through the Stage 5 guard. Status and visibility remain independent axes, and a product is public only when both allow it. A published Product Code is permanent until an administrator hard-deletes the product. There is no migration and no hallmark. Option A adds one private, system-owned marker, `_rj_first_published_at`, as a documented Stage 4 amendment, and D1 is enforceable after publish → draft → trash. The design is complete and ready for implementation.

## 35. Stage 4 Contract Amendment — Publication History Marker (Gap 1: RESOLVED)
**Decision.** Owner Option A: exactly one new private, system-owned `rj_product` meta key records that a product has been published at least once. D1 stays exactly as locked.

**Why the amendment is required.** D1 makes a Product Code permanently immutable once a product has been published, including after it returns to draft or is trashed. That needs a fact that outlives every status change.

**Why existing data cannot satisfy D1.** The repository holds no such fact: none of the 14 registered product keys records publication; `rj_activity_log` is written by Stage 11 and purged by retention; `rj_product_index` has no such column and belongs to a later stage; `rj_import_ledger` covers imports only; `post_date_gmt` can be set on drafts and scheduled posts and edited by editors; revisions can be pruned or disabled. The current status alone fails the "draft and trash" cases.

**Exactly one meta key is added.**
| Property | Value |
|---|---|
| Key | `_rj_first_published_at` (follows the existing `_rj_*_at` convention, e.g. `_rj_expires_at`) |
| Object | `rj_product` post meta |
| Type and sanitiser | The existing `datetime` rule (`Y-m-d H:i:s`, site timezone). No new sanitiser. |
| Single, default | single, empty string |
| REST | Not shown (`show_in_rest` false); never public API data |
| Authorisation | The key's authorisation callback refuses everyone, like the derived category caches. No client can write it. |
| Written by | `ProductService` only, once |
| Value | The actual time of the first transition to `publish` for products first published after this amendment. For a legacy product (see Marker semantics) it is the time of the first service observation, not a historical publication time. |

**Approved Stage 4 exception.** `Data/Meta.php` is the only Stage 4 contract file that changes: one additive row for the key and its refusing authorisation callback. Every other Stage 4 file stays protected, and no Stage 4 contract changes otherwise. One consequence, not a contract change: `MetaTest` asserts how many `rj_product` meta keys are registered (currently 14), so that single expected number becomes 15. No assertion is removed or weakened. Stage 5 is untouched.

**Predicate.** Callers never read the key. `ProductService::has_ever_been_published(int $product_id): bool` is true when the marker is set **or** the post's current status is `publish`. The second clause closes the moment between the status write and the marker write, and covers any product that is published but has no marker yet.

**When the marker is written.** `ProductGuard` listens to `transition_post_status` for `rj_product`. On a move to `publish` from any other status, it calls `ProductService::record_first_publication()`, which adds the marker only if absent (add-if-absent, never an update). Nothing clears it: `ProductService`, `ProductGuard`, admin editing, bulk operations and the future importer have no code path that deletes or resets it. A published product that lacks a marker is a legacy case (see Marker semantics).

**Marker semantics.**
- **New products:** the value is the real timestamp of the first transition to `publish`, recorded by the transition hook.
- **Legacy products:** a product that is currently published but has no marker was published before this amendment. No historical publication time exists and no migration is allowed, so on first service observation `ProductService` establishes a **legacy backfill marker** whose value is the time of that observation. It is not the historical first-publication time and must never be presented as one.
- **No distinguishing field.** The stored value carries no flag separating the two cases, and none is added. Consumers treat the marker only as the boolean fact exposed by `has_ever_been_published()`; the timestamp must not be reported as a publication date unless a future schema decision allows it.
- **D1 is unaffected.** `has_ever_been_published()` stays the sole authority for D1, and a legacy backfill marker locks the code exactly as a real one does.

**Client protection.** (1) The authorisation callback refuses any REST or admin meta write. (2) The key is absent from REST output and schema. (3) `ProductGuard` short-circuits `add_post_metadata`, `update_post_metadata` and `delete_post_metadata` (including delete-by-key) for this key unless the call is inside the service's own system-write scope. (4) `ProductData` has no marker field, so the importer cannot supply one. (5) A hard delete removes the marker with the rest of the product through core's own deletion, which these filters do not intercept; the guard also permits it inside a `before_delete_post` scope.

**Behaviour per path.**
| Path | Result |
|---|---|
| REST publication | Pre-write checks pass, status is written, the transition hook adds the marker |
| Classic admin publication | Same transition hook |
| Direct `wp_update_post` or `wp_insert_post` with status `publish` | Same transition hook; creation straight to publish also fires it |
| Scheduled post reaching `publish` | Marker is added at that transition; the code stays editable while the status is `future` |
| Repeated publish | Marker already present; nothing changes |
| Publish → draft, publish → trash, trash → restore → publish | Marker stays; the code stays locked; a change returns 409 `rj_conflict` |
| Hard delete | The product, its code and the marker are all removed; the Product Code is released |
Only the `publish` status counts as published.

**Accepted limitation.** No migration is permitted, so a product that was published and then returned to draft or trash before this amendment ships has no marker and is treated as never published. Every product currently published is covered by the predicate's second clause and receives a legacy backfill marker on its next service read.

**Planned tests.**
1. First publication creates the marker.
2. Repeated publication does not replace the original marker.
3. Publish → draft preserves the marker.
4. Publish → trash preserves the marker.
5. A published product cannot change its Product Code.
6. A published → draft product still cannot change its Product Code.
7. A trashed product still cannot change its Product Code.
8. Client attempts to write, clear or delete the marker are rejected: REST returns 403; `update_post_meta`, `add_post_meta`, `delete_post_meta` and delete-by-key leave it unchanged.
9. The future importer cannot write the marker: `ProductData` rejects the field.
10. A hard delete removes the whole product, marker included, and releases its Product Code.
11. D1 holds after every allowed lifecycle transition, including trash → restore → publish.
Also: the classic-admin, `wp_update_post` and scheduled-publication paths each set the marker; the key is registered private with a refusing authorisation callback and is absent from REST responses; `has_ever_been_published()` is true for a published product with no marker; `MetaTest` expects 15 `rj_product` keys; no table or migration was added; Stage 5 files are unchanged.

## 36. Gap 2 — enforcement on every write path: RESOLVED
Two layers. **Layer 1** is the domain boundary: `ProductService` and `ProductData`. Programmatic callers, bulk tools and the future importer may write products **only** through it; they never call `wp_insert_post`, `update_post_meta` or `wp_set_object_terms` on a product directly. **Layer 2** is `ProductGuard`, a WordPress adapter in `src/Product/` that catches writes made outside Layer 1. It contains no rules of its own; each hook calls the same `ProductService` validators, and category rules call the existing `AssignmentGuard`. Nothing in `src/Category/` or Stage 4 changes, and `CategoryRules` stays the REST category hook; `ProductGuard` does not duplicate it.

| Rule | REST (pre-write veto) | Direct meta write (pre-write veto) | Classic admin and `wp_update_post` (post-save check) | Direct term assignment | Future importer |
|---|---|---|---|---|---|
| Code uniqueness | `rest_pre_insert_rj_product` reads request meta; 409 | `update_post_metadata` / `add_post_metadata` short-circuit on `_rj_code` | `save_post_rj_product` | n/a | `ProductService` |
| Code immutability (D1) | same hook | same filters | same hook | n/a | `ProductService` |
| Publication marker (system-owned) | REST meta parameter refused by the key's authorisation callback | `add_`, `update_` and `delete_post_metadata` short-circuits refuse every write outside the service's system-write scope | n/a | n/a | `ProductService` only; `ProductData` has no marker field |
| Title sync | not needed; the service writes it | n/a | `save_post_rj_product`, after meta is saved, with a re-entrancy guard | n/a | `ProductService` |
| Publication-required fields | same REST hook, when the request sets `publish` | n/a | `save_post_rj_product` at late priority: an invalid publish is reverted to `draft` with an admin notice | n/a | `ProductService` |
| Metal and purity cardinality | same REST hook | n/a | post-save check | `set_object_terms`: a second term is reverted | `ProductService` |
| Category rules, primary term | already vetoed by Stage 5 `CategoryRules` and `AssignmentGuard` | `update_post_metadata` on `_rj_primary_term` calls `AssignmentGuard` | post-save check calls `AssignmentGuard` | `set_object_terms`: a root or resolver term is reverted | `ProductService` |

**Honest limits.**
- WordPress offers no veto before a term relationship is written. `set_object_terms` fires afterwards, so term enforcement is detect-and-revert: before the call returns, the previous terms are restored and the violation is reported. No violating state remains once the request ends.
- Classic admin saves meta after the post row is written, so publication checks run after save and revert the status. REST gets a true pre-write veto because the request holds the whole payload.
- Raw `$wpdb` writes bypass every hook and are out of scope; a repository scan test forbids them in product code.

**Concurrency.** Postmeta has no unique constraint. Every code claim runs inside the Stage 2 `Support\Lock`, keyed by the normalised code, in both layers, so two requests cannot both claim the same code.

**Re-entrancy.** The `save_post_rj_product` handler writes the title and may revert the status. It removes itself while writing and restores itself afterwards, so it cannot recurse.

**Importer contract.** The future importer's only write surface is `ProductService` with `ProductData`. A direct WordPress write by an importer is a defect.

**Planned tests for this section.** Direct `update_post_meta` with a duplicate code is rejected; direct `wp_set_object_terms` with a root or resolver term is reverted and the previous terms stay; a second metal or purity is reverted; a classic save of a product missing a required field stays draft with a notice; REST publish with a missing field is refused before any write; the title is overwritten after a meta-only save; the save handler does not recurse; two concurrent claims of one code leave exactly one owner; a scan finds no direct product writes outside `src/Product/`.
