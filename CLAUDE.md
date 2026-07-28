# Claude Rules for scolta-wp

## Versioning (CRITICAL — read scolta-core/VERSIONING.md)

Major versions are synchronized across all Scolta packages; minor and patch versions are released independently per package. Adapters pin scolta-php via `composer.lock` within their `^1.x` constraint. This is a platform adapter — it depends on scolta-php, never on scolta-core directly.

### Rules

- **NEVER** reimplement scoring, HTML cleaning, or prompt logic. These belong in scolta-core via scolta-php.
- **NEVER** change `composer.json` to depend on `tag1/scolta-core`. Depend on `tag1/scolta-php`.
- Dependency constraint MUST be a caret constraint: `"tag1/scolta-php": "^X.Y"` (or `@dev` for development).
- Vendor paths for scolta-php assets MUST use `vendor/tag1/scolta-php/`, NOT `vendor/tag1/scolta/`.
- API key MUST NOT be stored in the database from the admin form. Only env/constant/legacy migration.

### Version management and -dev workflow

The version appears in THREE places for WordPress: the plugin header comment in `scolta.php`, the `SCOLTA_VERSION` constant, and `readme.txt` `Stable Tag`. **All three must match.** The plugin header is the source; everything in CI that needs the version reads it through `scripts/plugin-version.sh`. See scolta-core/VERSIONING.md for the full workflow.

- If current version has `-dev`, **do not change it** — multiple commits accumulate on one dev version.
- If current version is a bare release and you're making the first change after it, bump to next target with `-dev` in all three locations.
- **WARNING:** Never commit a bare version bump without tagging it as a release.

**NEVER add a `version` field to `composer.json`.** CI fails if one appears. There used to be one, making a fourth location. A declared version overrides the version Composer derives from the branch or tag, which is what the `extra.branch-alias` beside it exists to describe. Packagist ignores it, but the drupal.org Composer facade does not, and that is how the sibling Drupal adapter broke a client build: the package announced a fixed version string regardless of branch, so a consuming site could `composer update` but never `composer install` from the resulting lock. WordPress reads the plugin header and WordPress.org reads the Stable Tag, so nothing here needs it declared either.

### Local cross-package development

To test against un-released scolta-php locally, run `composer config minimum-stability dev && composer require tag1/scolta-php:@dev` (the path repo then supplies the dev build). **Do not commit the result** — the release lock must stay Packagist-stable. The CI lock guard enforces this.

### WordPress conventions

- Use WordPress coding standards (snake_case methods, PHPDoc on all public methods).
- REST API endpoints use `register_rest_route()` with validation callbacks.
- Settings use a single serialized option (`scolta_settings`).
- All user-facing strings must use `__()` or `_e()` for i18n.

## Vendored browser assets — DO NOT EDIT DIRECTLY

Four files are copies of canonical sources in `scolta-php/assets/`:

| committed here | canonical in scolta-php |
|---|---|
| `assets/js/scolta.js` | `assets/js/scolta.js` |
| `assets/css/scolta.css` | `assets/css/scolta.css` |
| `assets/wasm/scolta_core.js` | `assets/wasm/scolta_core.js` |
| `assets/wasm/scolta_core_bg.wasm` | `assets/wasm/scolta_core_bg.wasm` |

`assets/css/amazee-admin.css` and `assets/js/amazee-admin.js` are this plugin's own. They are not vendored and nothing above applies to them.

**Never edit the vendored four in this repo.** All changes go to scolta-php first, then the copies are re-vendored here. The duplication is a requirement, not a smell: the plugin zip must contain these files, and Composer does not run a dependency's scripts, so nothing copies anything when a site installs the plugin. **The committed file is the shipped file.**

### Re-vendoring after a scolta-php change

**Assets are NOT refreshed as a side effect of `composer install` or `composer update`.** They used to be, via `post-install-cmd` / `post-update-cmd`, and that is precisely what made the CI parity check vacuous: the hook rewrote the tracked file from `vendor/` moments before the check compared the two, so the check could never fail on a stale committed copy. A fixer and a checker in the same pipeline is the bug class, and the fixer always wins. Re-vendoring a bundle is a deliberate act that lands in the CHANGELOG, so it is a command a human runs and CI notices when someone forgot.

1. Bump `tag1/scolta-php` in `composer.json` / `composer.lock` as needed.
2. Run `composer copy-assets`. It overwrites all four committed files from `vendor/tag1/scolta-php/assets/` and fails loudly if a source is missing.
3. Commit the result, with a CHANGELOG entry describing what changed in the bundle.
4. The `assets-in-sync` CI job byte-compares each committed file against the vendored canonical and fails if any differs.

On a coordinated change, `assets-in-sync` goes red until the matching scolta-php pull request merges, because it resolves scolta-php from `dev-main`. That is correct signal, not a problem to work around: an adapter must not merge ahead of its upstream. **Do not run `composer copy-assets` to make it green** — that overwrites the new bundle with the old one.

**Do not commit a `.sha256` sidecar.** There used to be an `assets/js/scolta.js.sha256`; nothing generated it and nothing read it, so it drifted for two revisions. scolta-php owns the canonical record in its own `assets/ASSETS.sha256`. `assets-in-sync` compares the asset bytes, which is the artifact rather than a claim about it.

## Testing

- Run: `./vendor/bin/phpunit`
- Tests use a WP function stub bootstrap (tests/bootstrap.php), not a full WordPress install.
- The bootstrap creates `/tmp/wordpress/wp-admin/includes/upgrade.php` for dbDelta.

## Documentation Rules

Documentation follows code. When a PR changes behavior, the same PR must update the relevant docs.

- **CHANGELOG.md**: Every PR that changes code (not docs-only) MUST add an entry under `## [Unreleased]`. CI enforces this.
- **README.md**: Update if the change affects installation, WP-CLI commands, REST API endpoints, shortcode, or settings.
- **Admin settings page**: Settings descriptions in `Scolta_Admin` MUST match the behavior of the setting.
- **PHPDoc**: All public methods MUST have complete PHPDoc per WordPress coding standards.
