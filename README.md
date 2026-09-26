# Rameshwari Jewellers platform

Catalogue and WhatsApp-enquiry platform for Rameshwari Jewellers, Jaipur. The specification set in `documentation/specifications/` is the source of truth.

## Locked constraints

- PHP 8.2 minimum (`RJ_MIN_PHP`) and WordPress 6.5 minimum (`RJ_MIN_WP`)
- Elementor Free is the baseline: 15 Elementor-Free widgets, 0 required dynamic tags (the 11 tags are an optional Pro layer)
- `rameshwari-core` owns all business data and logic; the theme and every builder are presentation only
- `develop` receives all work; `main` holds releases; release tags go on `main` only

## Layout

| Path | Contents |
|---|---|
| `plugin/rameshwari-core/` | Required plugin. Empty until Stage 1. |
| `optional/` | `rameshwari-blocks`, `rameshwari-elementor`, `rameshwari-tools`. Empty until their stages. |
| `theme/rameshwari/` | Presentation theme. Empty until its stage. |
| `documentation/` | `specifications/`, `decisions/`, `runbooks/`, and `data/`, the one folder here that ships. |
| `tests/` | Test suites. Stage 0 holds only the harness test. |
| `build/` | Development tooling. Never deployed. |

## Local checks

```bash
cd build
composer install
composer lint      # WordPress coding standards
composer analyse   # PHPStan with the eval / shell / variable-include bans
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 6.5
composer test      # PHPUnit against a real WordPress
```

The same checks run in GitHub's hosted pipeline on every push and pull request to `develop` and `main`.

## Version pins to watch

- **PHP 8.2 security support ends 31 Dec 2026.** Revisit `RJ_MIN_PHP` then.
- **PHPUnit 9.6 and PHPUnit Polyfills 1.x** are what the WordPress 6.5 test library supports. If a newer WordPress release drops them, the `latest` pipeline job fails and says so.
- **"Current" PHP in the pipeline is 8.4.** Move it to 8.5 once PHPUnit 9 and the latest WordPress are confirmed clean on it.
