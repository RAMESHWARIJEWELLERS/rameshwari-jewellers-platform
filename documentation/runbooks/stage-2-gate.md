# Stage 2 gate — Support primitives

Stage 3 may begin only when every line below is checked.

## Pipeline (GitHub Actions, on the stage-2-support-primitives branch and on main)

- [ ] `composer lint` — 0 errors, 0 warnings.
- [ ] `composer analyse` — PHPStan level 6, 0 errors.
- [ ] `composer test` on **PHP 8.2 + WordPress 6.5** — **113 tests, 0 failures, 0 risky.**
- [ ] `composer test` on **current PHP + current WordPress** — **113 tests, 0 failures, 0 risky.**

The 113 are the 34 of the Stage 1 gate plus 79 Stage 2 unit tests: Cache 14, Lock 9, RateLimiter 8, Template 8, Str 9, Hash 8, Sanitizer 10, Escaper 5, Assets 8. A run reporting any other number fails the gate.

Cache, Lock and RateLimiter tests run in both modes — persistent object cache and transient/option fallback. That property is what keeps InfinityFree viable.

`StrTest::test_all_category_terms_slug` covers all **306** Category Master terms, keyed by full path, with repeated visible names kept.

## Files — 28, all new

- `plugin/rameshwari-core/src/Support/` — 9: Assets, Template, Cache, Lock, RateLimiter, Sanitizer, Escaper, Str, Hash.
- `tests/unit/` — 9: one test file per class above.
- `tests/fixtures/` — 9: `category-names.php` (306 terms), `templates/child/card.php`, `templates/parent/card.php`, `templates/parent/banner.php`, `templates/plugin/card.php`, `templates/plugin/banner.php`, `templates/plugin/footer.php`, `assets/app.js`, `assets/app.css`.
- `documentation/runbooks/stage-2-gate.md` — this file.

## Scope check

- [ ] `git diff --stat main...stage-2-support-primitives` shows exactly the 28 files above, all added.
- [ ] No Stage 1 file is modified.
- [ ] No `register_post_type`, `register_taxonomy`, `register_meta`, `register_rest_route`, `add_menu_page`, `dbDelta`, `$wpdb` or `wp_cache_flush` anywhere in the diff.
- [ ] No Support class is wired into `Plugin::boot()`; nothing uses them until a later stage.
