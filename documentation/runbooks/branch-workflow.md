# Branch workflow

Locked in Technical Blueprint §15.4: `develop` receives all work; `develop` merges to `main` at release time; release tags go on `main` only.

## Daily work

1. Work on `develop`, or on a short-lived branch cut from `develop` and merged back by pull request.
2. Every commit names a stage from the Implementation Master Plan, for example `Stage 1: module registry rejects cycles`.
3. Every push runs the pipeline: phpcs, phpstan, and PHPUnit at PHP 8.2 + WordPress 6.5 and PHP 8.4 + WordPress latest.

## Releasing

1. The pipeline is green on `develop`.
2. Open a pull request from `develop` to `main`. Merge only when the pipeline is green on it.
3. Tag the merge commit on `main` with the version, for example `v0.1.0`, and write its changelog entry.
4. Deploy the tagged commit, following the release procedure in Technical Blueprint §15.2.

## Never

- Commit directly to `main`.
- Tag anything on `develop`.
- Commit secrets, `wp-config.php` or `.env` files. `.gitignore` excludes them; this rule covers anything else.

## Optional repository settings (not required by the specification)

- Protect `main`: require a pull request and passing checks before merging.
- Protect `develop`: require passing checks.
