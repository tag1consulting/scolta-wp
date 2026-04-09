# Claude Rules for scolta-wp

## Versioning (CRITICAL — read scolta-core/VERSIONING.md)

Major versions are synchronized across all Scolta packages. This is a platform adapter — it depends on scolta-php, never on scolta-core directly.

### Rules

- **NEVER** reimplement scoring, HTML cleaning, or prompt logic. These belong in scolta-core via scolta-php.
- **NEVER** change `composer.json` to depend on `tag1/scolta-core`. Depend on `tag1/scolta-php`.
- Dependency constraint MUST be a caret constraint: `"tag1/scolta-php": "^X.Y"` (or `@dev` for development).
- Vendor paths for scolta-php assets MUST use `vendor/tag1/scolta-php/`, NOT `vendor/tag1/scolta/`.
- API key MUST NOT be stored in the database from the admin form. Only env/constant/legacy migration.

### Version management and -dev workflow

The version appears in THREE places for WordPress: `composer.json`, the plugin header comment in `scolta.php`, and the `SCOLTA_VERSION` constant. **All three must match.** See scolta-core/VERSIONING.md for the full workflow. In Composer, `-dev` prevents accidental production installs without an explicit `@dev` flag.

- If current version has `-dev`, **do not change it** — multiple commits accumulate on one dev version.
- If current version is a bare release and you're making the first change after it, bump to next target with `-dev` in all three locations.
- **WARNING:** Never commit a bare version bump without tagging it as a release.

### WordPress conventions

- Use WordPress coding standards (snake_case methods, PHPDoc on all public methods).
- REST API endpoints use `register_rest_route()` with validation callbacks.
- Settings use a single serialized option (`scolta_settings`).
- All user-facing strings must use `__()` or `_e()` for i18n.

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
