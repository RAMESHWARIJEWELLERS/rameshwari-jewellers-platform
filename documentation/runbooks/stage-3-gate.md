# Stage 3 gate — Schema and migrations

Stage 4 may begin only when every box below is checked. Nothing here has been run yet: this package was written without PHP.

## Commands (local, or GitHub Actions on `stage-3-schema-migrations`)

```
cd build
composer install
composer lint
composer analyse
composer test
```

- [ ] `composer lint` — 0 errors, 0 warnings.
- [ ] `composer analyse` — PHPStan level 6, 0 errors.
- [ ] `composer test` on **PHP 8.2 + WordPress 6.5** — **152 tests, 0 failures, 0 risky.**
- [ ] `composer test` on **current PHP + current WordPress** — **152 tests, 0 failures, 0 risky.**

152 = 113 (Stage 2 gate) + 1 Stage 2 regression test added here (`LockTest::test_default_mode_normalises_to_bool`) + 38 Stage 3 integration tests: Schema 10, Migration 11, Capabilities 8, Lifecycle 7, Ledger 2.

## Master Plan Stage 3 gate, mapped to tests

| Gate line | Test |
|---|---|
| Clean install creates eight tables with every specified index | `SchemaTest::test_fresh_install_creates_all_tables`, `test_keys_match_specification` |
| Re-activation is idempotent | `LifecycleTest::test_reactivation_idempotent`, `MigrationTest::test_rerun_is_idempotent` |
| Devanagari round-trip through each text column is byte-identical | `SchemaTest::test_devanagari_round_trip_every_text_column` (Devanagari plus a four-byte emoji) |
| Failure mid-migration leaves a consistent state | `MigrationTest::test_failure_leaves_consistent_state` |
| Router runs on admin_init or CLI only, behind a lock | `MigrationTest` — see Entry points below |
| Capability matrix for all five actor types | `CapabilitiesTest` — administrator, catalogue manager, enquiry agent, customer, guest |

## Entry points

Migration runs only through three guarded methods on `Upgrader`. Each checks where it is actually running rather than trusting an argument. The migration logic itself is private.

| Entry point | Check | Accepted by | Refused by |
|---|---|---|---|
| `admin_init()` | `doing_action( 'admin_init' )`, `is_admin()`, not `wp_doing_ajax()`, not `wp_doing_cron()` | every test through `DatabaseTestCase::install()`; `LifecycleTest::test_upgrade_reasserts_capabilities` through the real `Upgrader::on_admin_init` callback | `test_direct_invocation_refused`, `test_ajax_does_not_migrate`, `test_cron_does_not_migrate` |
| `activation()` | `doing_action( 'activate_' . plugin_basename( RJ_FILE ) )` | `LifecycleTest`, through `do_action` on the real activation hook | `test_direct_invocation_refused` (calls `Activator::activate()` directly) |
| `cli()` | `WP_CLI` defined and true | `test_cli_accepted_under_wp_cli` (separate process) | `test_cli_refused_without_wp_cli`, `test_direct_invocation_refused` |

`Installer::install()` takes an entry-point name, but the name only selects which check applies. Naming `admin_init` from a front-end request fails that check. The lock: `test_locked_run_is_skipped`.

This is a provenance boundary. It is not a defence against malicious PHP running in the same process, which could call `dbDelta` directly.

## Files — 24 total: 18 created, 6 modified

**Created (18)**
1. `plugin/rameshwari-core/src/Data/Schema.php`
2. `plugin/rameshwari-core/src/Data/Capabilities.php`
3. `plugin/rameshwari-core/src/Data/Migrations/Migration.php`
4. `plugin/rameshwari-core/src/Data/Migrations/Migration_001.php`
5. `plugin/rameshwari-core/src/Installer.php`
6. `plugin/rameshwari-core/src/Activator.php`
7. `plugin/rameshwari-core/src/Deactivator.php`
8. `plugin/rameshwari-core/src/Upgrader.php`
9. `plugin/rameshwari-core/src/Uninstaller.php`
10. `plugin/rameshwari-core/uninstall.php`
11. `tests/integration/DatabaseTestCase.php` (abstract base, not collected as a test)
12. `tests/integration/SchemaTest.php`
13. `tests/integration/MigrationTest.php`
14. `tests/integration/CapabilitiesTest.php`
15. `tests/integration/LifecycleTest.php`
16. `tests/integration/LedgerTest.php`
17. `tests/fixtures/Migrations/RecordingMigrations.php`
18. `documentation/runbooks/stage-3-gate.md` (this file)

**Modified (6)**
1. `plugin/rameshwari-core/rameshwari-core.php` — `RJ_DB_VERSION` 0 → 1; registers the Activator and Deactivator hooks and the `admin_init` callback. `rj_activate()` is unchanged and still runs first.
2. `build/stubs/wordpress-tests.php` — declares `_create_temporary_tables()` and `_drop_temporary_tables()`, which `DatabaseTestCase` unhooks.
3. `plugin/rameshwari-core/src/Support/Lock.php` — Stage 2 fix exposed by the first Stage 3 PHPUnit run: the default mode is cast to bool, because `wp_using_ext_object_cache()` can return null and the property is typed `bool`.
4. `tests/unit/LockTest.php` — adds `test_default_mode_normalises_to_bool` as the regression test for that fix.
5. `tests/bootstrap.php` — in a PHPUnit isolated child process only, detected by `display_errors` being `stderr` as PHPUnit's `TestCaseMethod.tpl` sets it. There: sets `WP_TESTS_SKIP_INSTALL=1`, so the WordPress test bootstrap does not re-run `install.php` in a further PHP process whose deprecation notices land on the child's stderr; defines `WP_DEBUG_DISPLAY` false, so `wp_debug_mode()` does not print notices to stdout ahead of the serialized result; and sends the error log to a temporary file. PHPUnit 9's `AbstractPhpProcess::processChildResult()` reports child stderr, or stdout that fails to unserialize, as the test's error. `error_reporting` and PHPUnit's error handler are unchanged.
6. `tests/integration/PluginTest.php` — `test_stage_1_registers_nothing_else` only. It used to assert that `rj_db_version` did not exist at all, which a leaked option from any earlier test broke. It now records the value before booting and asserts that booting leaves it unchanged. It also asserts that the only new hooks are Stage 3's approved ones: `rj_activate` and `Activator::activate` on activation, `Deactivator::deactivate` on deactivation, and `Upgrader::on_admin_init` on `admin_init`. The module, post type and taxonomy assertions are unchanged.

## Scope

- **Tables:** exactly eight, from Development Blueprint §5 — `rj_leads`, `rj_activity_log`, `rj_notification_queue`, `rj_wishlist`, `rj_recent_views`, `rj_login_log`, `rj_product_index`, `rj_import_ledger`. Runtime prefix, installation charset, no foreign keys, no other table.
- **Options written:** `rj_db_version` (autoloaded) and `rj_install_state` (not autoloaded: `installed_at`, `migrations`). Both are named in Development Blueprint §10. The migration lock row `rj_lock_migrations` is transient runtime state.
- **Roles:** `rj_catalogue_manager`, `rj_enquiry_agent`, `rj_customer`. **Capabilities:** the eleven of Platform Architecture §1.2, repaired on activation and on every admin upgrade check.
- **Absent:** post types, taxonomies, meta, REST routes, admin screens, Elementor, Gutenberg, auth, chatbot, notifications, import/export runtime.

## Nullability

Taken from §5 wherever §5 states it:

- **`rj_leads`, `rj_activity_log`, `rj_notification_queue`** — from each table's Null column.
- **`rj_wishlist`, `rj_recent_views`** — every column NOT NULL, as §5.4 states.
- **`rj_login_log.user_id`** — nullable (§5.5).
- **`rj_product_index.metal_id`, `purity_id`** — nullable (§5.6).
- **`rj_import_ledger.before`** — null for a creation; **`rolled_back_at`** — null until reversed (§5.7).
- **Primary keys** — NOT NULL.

§5 does not state nullability for these columns. They were decided here:

| Table | NOT NULL | NULL |
|---|---|---|
| `rj_login_log` | `created_at`, `method`, `result` | `ip_hash` |
| `rj_product_index` | `code` (unique key), `visibility`, `updated_at` | `name_hi`, `name_en`, `weight`, `primary_term_id` |
| `rj_import_ledger` | `token`, `created_at`, `user_id`, `import_type`, `object_type`, `object_id`, `operation` | — |

No default was invented beyond those §5 gives: `status 'new'`, `channel 'whatsapp'`, `spam_score 0`, `attempts 0`.

## Other source decisions — confirm or correct

1. **`Installer.php` is in the brief but not in Master Plan Stage 3's file list.** Kept as the routine all three entry points share.
2. **`uninstall.php` and `Migration.php` are in neither list.** `uninstall.php` is in Development Blueprint §2.1's tree and is how WordPress runs an uninstall. `Migration.php` is a small interface that lets the router call `up()` with a checked type.
3. **Activation seeding is deferred.** Development Blueprint §2.2 says the Activator seeds the five root categories and default settings, schedules cron and flushes rewrites. Taxonomies, options, cron and rewrites don't exist until Stage 4 or later.
4. **Index names are not given by §5**, only their columns. Names are descriptive (`status_created`, `token_id` and so on).
5. **`before` is a MySQL reserved word.** It is backtick-quoted in the schema.
6. **Catalogue staff get the core `upload_files` capability**, from §1.2's boundary "and media". Post-type capabilities arrive with the post types in Stage 4.
7. **No uninstall opt-in exists yet.** `uninstall.php` passes `false`, so it removes nothing. The opted-in path is implemented and tested, but has no switch until a later stage adds one.
8. **No WP-CLI command is shipped.** `Upgrader::cli()` is the guarded CLI entry point; the command itself belongs to `rameshwari-tools`.

## Known risks on the first run

- **`dbDelta` re-run drift.** Types are lowercase with explicit integer widths so `dbDelta` finds nothing to change. If `test_rerun_is_idempotent` fails, compare `SHOW CREATE TABLE` before and after.
- **DDL commits the test transaction.** `DatabaseTestCase::clean()` deletes options, roles and capabilities before dropping the tables, so the drop's implicit commit keeps the cleanup and the closing rollback cannot restore `rj_db_version` for `PluginTest` to find.
- **The separate-process test.** `test_cli_accepted_under_wp_cli` re-runs the bootstrap in a child process. If the test library misbehaves there, that one test is where it will show.
- **`set_current_screen()`** is how the tests make `is_admin()` true. It is reset to `front` after each use.

## Recovery

Stage 3 has no data rollback. Take a backup before release and verify the restore before running the migration in production. Rolling the code back leaves the eight tables in place, and they do no harm.
