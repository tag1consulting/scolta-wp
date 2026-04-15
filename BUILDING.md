# Building a Distribution ZIP

This document explains how to build the `scolta-wp` plugin ZIP file for
WordPress.org submission or manual distribution.

## Prerequisites

- PHP 8.1+
- Composer
- `zip` (standard on macOS and Linux)

## Steps

### 1. Install production dependencies

```bash
cd packages/scolta-wp
composer install --no-dev --optimize-autoloader
```

This populates `vendor/` with only the runtime dependencies (primarily
`tag1/scolta-php` and its assets). Development tools (PHPUnit, etc.) are
excluded.

### 2. Build the ZIP

```bash
# From the repo root or from packages/scolta-wp
VERSION=$(grep "define('SCOLTA_VERSION'" scolta.php | grep -oP "'\K[^']+")
ZIP_NAME="scolta-wp-${VERSION}.zip"

zip -r "${ZIP_NAME}" . \
  --exclude "*.git*" \
  --exclude "tests/*" \
  --exclude "*.phpunit*" \
  --exclude "composer.json" \
  --exclude "composer.lock" \
  --exclude "phpcs.xml" \
  --exclude "phpunit.xml" \
  --exclude "BUILDING.md" \
  --exclude "*.DS_Store" \
  --exclude "bin/*"
```

This creates `scolta-wp-0.2.x.zip` in the current directory.

### 3. Verify the ZIP

```bash
unzip -l "${ZIP_NAME}" | head -40
```

Check that:
- `vendor/` is included (runtime dependencies)
- `tests/` is excluded
- `scolta.php`, `admin/`, `includes/`, `cli/` are all present
- No `bin/` directory (downloaded binaries are gitignored and should not ship)

### 4. Test the ZIP

Install the ZIP on a staging WordPress site and confirm:
1. `wp scolta build` completes without errors
2. The search shortcode renders correctly
3. `wp scolta check-setup` reports no failures

## What ships in the ZIP

| Path | Purpose |
|------|---------|
| `scolta.php` | Plugin entry point |
| `admin/` | Admin settings UI |
| `cli/` | WP-CLI commands |
| `includes/` | Shortcode, REST API, observer classes |
| `vendor/` | Composer runtime dependencies (scolta-php + assets) |

## What is excluded

| Path | Reason |
|------|--------|
| `tests/` | Development only |
| `bin/` | Downloaded Pagefind binaries — platform-specific, not redistributable |
| `*.phpunit*`, `phpcs.xml` | Development tooling |
| `composer.json`, `composer.lock` | Not needed at runtime |
| `BUILDING.md` | Meta documentation |
