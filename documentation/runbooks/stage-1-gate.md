# Stage 1 gate — plugin bootstrap

Stage 2 may begin only when every line below is checked.

## Pipeline (GitHub Actions, on the develop merge and on main)

- [ ] `composer lint` — 0 errors, 0 warnings.
- [ ] `composer analyse` — PHPStan level 6, 0 errors.
- [ ] `composer test` on **PHP 8.2 + WordPress 6.5** — **34 tests, 0 failures, 0 risky.**
- [ ] `composer test` on **current PHP + current WordPress** — **34 tests, 0 failures, 0 risky.**

The 34 are: 32 Stage 1 tests across 6 files (unit 25, integration 7) plus the 2 Stage 0 harness tests in `tests/integration/HarnessTest.php`. A run reporting any other number fails the gate, even if nothing is red — a missing test is a failure the count catches.

These are not the 20 regression tests in Development Blueprint §24; those are written in the stages that build the features they cover.

## Manual checks on staging

- [ ] Activate on PHP 8.2 / WP 6.5 or newer: activates, no notice, no PHP warning in the log.
- [ ] Run `wp option list --search="rj_*"` before and after activation: identical (Stage 1 writes nothing).
- [ ] Deactivate and reactivate: no error, no leftover state.
- [ ] Below the floor (a local PHP 8.1 container is enough): activation stops with the "needs PHP 8.2" message and the plugin stays inactive.
- [ ] Switch to a stock theme with Elementor inactive: plugin still active, no error. (Nothing visible — Stage 1 renders nothing.)

## Scope check

- [ ] `git diff --stat develop...stage-1` touches only `plugin/rameshwari-core/`, `tests/`, `build/phpcs.xml`, `build/phpstan.neon`, `build/stubs/` (static-analysis stubs only), `build/phpunit.xml.dist` and this file.
- [ ] No `register_post_type`, `register_taxonomy`, `register_meta`, `register_rest_route`, `add_menu_page`, `dbDelta` or `$wpdb` anywhere in the diff.
