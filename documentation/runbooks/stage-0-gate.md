# Stage 0 gate

Stage 1 may begin only when every box is ticked. Source: Implementation Master Plan, Stage 0.

## Repository
- [ ] GitHub repository created, default branch `main`
- [ ] Initial commit on `main` holds this skeleton and the 24 specification documents in `documentation/specifications/`
- [ ] `develop` created from `main` and pushed

## Tooling (run from `build/`)
- [ ] `composer install` succeeds; `composer.lock` committed so the pipeline resolves the same versions
- [ ] `composer lint` exits 0
- [ ] `composer analyse` exits 0
- [ ] `composer test` passes locally against a WordPress test install

## Pipeline
- [ ] All four jobs green on `develop`: phpcs · phpstan · PHPUnit (8.2 / 6.5) · PHPUnit (8.4 / latest)
- [ ] Pull request from `develop` to `main` merged; all four jobs green on `main`

## Ban rules are live (prove each one fails, then revert without committing)
- [ ] A file in `tests/` containing `eval( '1;' );` fails both phpcs and phpstan
- [ ] A file containing `shell_exec( 'ls' );` fails phpstan
- [ ] A file containing `require $file;` fails phpstan

## Staging (Technical Blueprint §15.1)
- [ ] The live control panel shows PHP 8.2+ and WordPress 6.5+
- [ ] Search engines blocked; outbound mail disabled; debug output as §15.1 defines
- [ ] Separate database and URL from production

## Backup (Technical Blueprint §15.4)
- [ ] Schedule set: database daily, kept 30 days; files weekly in full plus uploads daily, kept 30 days
- [ ] At least one copy stored off-site
- [ ] Backup taken; time, size and location recorded
- [ ] Restored into staging **from the off-site copy**
- [ ] After restore: site loads, admin login works, post / option / user counts match, database charset is `utf8mb4`
- [ ] Restore steps written into `documentation/runbooks/`

## Gate result
- [ ] Staging restores from backup
- [ ] phpcs and phpstan both clean on the empty tree
- [ ] The harness test passes across the whole matrix
