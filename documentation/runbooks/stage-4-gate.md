# Stage 4 gate — Core registries

## LOCAL verification

Verified locally; this does not represent GitHub CI output.

- `composer --working-dir=build lint` — PASS.
- `composer --working-dir=build analyse` — PASS, 0 errors.
- `composer --working-dir=build test` — PASS, 200 tests, 0 errors, 0 failures.
- GitHub CI — pending; no GitHub CI output is recorded here.

## Commands

```
cd build
composer install
composer lint
composer analyse
composer test
```

- [ ] `composer lint` — 0 errors, 0 warnings.
- [ ] `composer analyse` — PHPStan level 6, 0 errors.
- [ ] `composer test` on PHP 8.2 + WordPress 6.5 — **200 tests, 0 failures, 0 risky.**
- [ ] `composer test` on current PHP + current WordPress — **200 tests, 0 failures, 0 risky.**
- [ ] Master Plan gate: six post types, seven taxonomies, ten groups present; settings save and reflect immediately; theme-independence test 1 of 5 passes.

199 = Stage 3 tests plus the current Stage 4 integration suite; focused registry and meta suites pass independently.

## Files — 18 total: 11 created, 7 modified

**Created (11)**
1. `plugin/rameshwari-core/src/Data/PostTypes.php`
2. `plugin/rameshwari-core/src/Data/Taxonomies.php`
3. `plugin/rameshwari-core/src/Data/Meta.php`
4. `plugin/rameshwari-core/src/Data/Options.php`
5. `plugin/rameshwari-core/src/Data/Rewrites.php`
6. `tests/integration/RegistriesTest.php`
7. `tests/integration/MetaTest.php`
8. `tests/integration/OptionsTest.php`
9. `tests/integration/RewritesTest.php`
10. `tests/integration/ThemeIndependenceTest.php`
11. `documentation/runbooks/stage-4-gate.md` (this file)

**Modified (7)**
1. `plugin/rameshwari-core/src/Plugin.php` — `boot()` adds the five registry modules; the registry orders them.
2. `plugin/rameshwari-core/src/Activator.php` — calls `Rewrites::flush()` after the install.
3. `plugin/rameshwari-core/src/Deactivator.php` — calls `Rewrites::remove()`: unregisters post types, taxonomies and rules, then flushes. Data untouched.
4. `tests/integration/PluginTest.php` — `test_stage_1_registers_nothing_else` renamed `test_registers_only_approved_entities_and_hooks`; it now asserts the only rj_ post types and taxonomies are the six and seven, instead of none. Every other assertion is unchanged.
5. `tests/integration/DatabaseTestCase.php` — `tear_down()` re-registers post types and taxonomies, because a deactivation test unregisters them for the rest of the process.
6. `build/phpcs.xml` — Stage 4 lint configuration.
7. `build/stubs/wordpress-tests.php` — WordPress test stubs for static analysis.

## Counts

- **Post types:** 6. **Taxonomies:** 7. **Option groups:** 10 (8 schema groups + `rj_db_version` + `rj_install_state`, which the Upgrader owns).
- **Meta registrations:** 129 runtime registrations — post 56 (product 14, reel 9, collection 4, showroom 11, testimonial 6, page section 12), term 63 (rj_category 16, four flat taxonomies × 11, rj_tag 3), user 10. `rj_page_section` has 11 final section keys; `_rj_order` is reused and registered separately, so its runtime count is 12. The 12 new permanent keys since Session 4 are 11 page-section keys plus `_rj_resolver`.
- **Modules:** `post_types`, `taxonomies` (needs post_types), `options`, `meta` (needs post_types, taxonomies, options), `rewrites` (needs post_types). No cycle.

## Decisions A and B — applied

- **Decision A (Development Blueprint §7.1):** `rj_category` `rest_base` is `rj-categories`, so native REST is `/wp/v2/rj-categories`. The public rewrite stays `c`. `RegistriesTest::test_category_rest_route_is_rj_categories` checks that the route exists, serves only `rj_category`, that core's `/wp/v2/categories` still serves core categories, and that term links use `/c/`.
- **Decision B (Development Blueprint §10.2):** `Options::schemas()` is the locked contract for the eight writable groups. Every earlier inferred value is gone. `OptionsTest::test_defaults_match_locked_contract` pins all ten groups' defaults to §10.2 exactly. `rj_contact.emails` is a list of `{label, address}` records.

## Micro-correction v2

- `rj_whatsapp.active_number` and `fallback_number` are normalised on save to Platform Architecture §4.7's form — 91 prefix, digits only — and anything that cannot be normalised is rejected. `''` stays valid.
- `rj_contact.primary_showroom` must be 0 or an existing `rj_showroom` post.
- `rj_seo.open_graph.default_image_id` must be 0 or an existing attachment.

**Open inconsistency, not changed:** `rj_contact.phones[].number` still validates as `+`-prefixed E.164, while §4.7 says numbers are stored with the 91 prefix and digits only. Aligning it needs a decision.

## Known conflict with Stage 3 — not changed here

§10.2 locks `rj_install_state` as `{install_timestamp, completed_migration_ids, last_rebuild_times, seeded}` and `rj_db_version` default 1. Stage 3's `Upgrader` and `Installer` write `rj_install_state` as `{migrations, installed_at}` and treat a missing `rj_db_version` as 0 ("not yet migrated"). Stage 4 was told not to change Stage 3, so both groups stay migration-owned and are not guarded or writable here; `Options::defaults()` returns their §10.2 values. Aligning Stage 3 needs its own decision.

## Decisions taken — confirm or correct

- **Capabilities.** Each post type keeps its approved `capability_type` and `map_meta_cap`, and its primitive capabilities are mapped onto the existing eleven (Stage 3 `CapabilitiesTest` forbids new rj_ capabilities). Delete is `manage_options` for products and showrooms, where §6.1 says administrator only.
- **`rj_category`:** `meta_box_cb` false and `show_in_quick_edit` false (§7.1: core UI disabled). `show_ui` stays true so terms are manageable until Stage 5's tree manager. `sort` true and `show_admin_column` true come from your brief. `update_count_callback` is WordPress's default, because no document specifies one.
- **Flat-taxonomy term meta:** `rj_metal`, `rj_purity`, `rj_occasion`, and `rj_audience` carry the common 11 keys; `rj_tag` carries only `_rj_name_hi`, `_rj_name_en`, and `_rj_seo_title`. `rj_collection_tax` has no registered term meta.
- **Resolver:** `_rj_resolver` is registered only on `rj_category`, default empty, public/readable, REST read-only, and capability checked. Stage 4 seeds no mappings; Stage 5 owns term import and mapping data. No resolver engine or import/health logic is included.
- **Arrays** use WordPress's native `array` meta type (stored serialized), not JSON strings as §9.1's wording suggests; that is what gives the REST schema.
- **User meta** is registered with `show_in_rest` false. Customer REST arrives with `/rj/v1/me`.
- **Engine rules are deferred:** WhatsApp token sets, provider host allow-lists, primary term assigned to the product, start before end. Each value is sanitised on its own here.
- **Weight (C-11):** removed from public `wp/v2/products` responses while `show_weight_publicly` is off, unless the reader can edit the product.
- **Rewrites:** `/p/{code}/` and `/account/{screen}/` for the eight screens in Development Blueprint §2.1. Rules and query variables only; handling belongs to later stages. Flushed on activation, deactivation and `Rewrites::flush()` (the Tools action), never on a request.
- **`add_option()` bypasses the write guard.** Only `update_option()` is filtered.

## Scope check

- [x] No custom `/rj/v1/*` route (`RegistriesTest::test_native_rest_routes` passed locally).
- [x] No table, migration, cron job, admin screen, block, widget or Stage 5 category logic in the Stage 4 package.
- [x] No hard-coded business data in the Stage 4 registries.
