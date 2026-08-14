# MAINTAINING — scolta-wp

The WordPress plugin over scolta-php. Publishes to Packagist and to wordpress.org.

Everything true of more than one Scolta repo lives in
[scolta-core/MAINTAINING.md](https://github.com/tag1consulting/scolta-core/blob/main/MAINTAINING.md):
the version rules, the release order, the fleet checks, the rules every repo shares. How the bundle is
copied and checked is in
[scolta-core/ASSETS.md](https://github.com/tag1consulting/scolta-core/blob/main/ASSETS.md). Build steps
are in `BUILDING.md` here.

**What it is.** A WordPress plugin, glue only. It depends on `scolta-php` and never on `scolta-core`
directly.

**Where the version lives.** In three places that must match: the plugin header `Version:` in
`scolta.php` (the source), the `SCOLTA_VERSION` constant, and `readme.txt` `Stable Tag`. Everything in
CI that needs the version reads it through `scripts/plugin-version.sh`. **Never add a `version` key to
`composer.json`**: `version-consistency` hard-fails if one appears, and there used to be one, making a
fourth location.

**Where it publishes.** Packagist as `tag1/scolta-wp`, and wordpress.org over SVN as the slug
`scolta-ai-search`. Confirm both: `composer show` resolves it, and the wp.org plugin page shows it.

**CI checks.** phpunit (`test`, `coverage`), `assets-in-sync`, `Static analysis (PHPStan)`,
`docs-check`, `version-consistency`, `version-sync`, `lock-guard`,
`Distribution archive build + validate`, `WordPress.org Plugin Check (built zip)` (run against the
unpacked dist zip with `--slug=scolta-ai-search`, where warnings are fatal on purpose),
`antipatterns`, and `Version coherence`. `upstream-preview` is informational and not a merge gate.

`release.yml` adds two gates that never run on a pull request, so they first fire at the tag:
`check-wp-version` (`Tested up to:` must be current) and `check-readme-changelog` (`readme.txt` needs the
entry for this version).

**On release day.** Don't build the zip locally: CI builds it, and `scripts/validate-dist.sh` must pass.
wp.org rejects a non-numeric version, so never publish from a `-dev` or `-rc` commit. SVN steps: update
`trunk` from the reviewed dist zip, `svn cp trunk tags/X.Y.Z`, then bump `Stable Tag`.

**Watch out for.**

- The dist zip unpacks to `wp-content/plugins/scolta/` (`build-dist.sh` hardcodes `PKG="scolta"`), but
  wp.org installs the plugin as `scolta-ai-search/`. That is why Plugin Check is passed the slug
  explicitly: without it the check infers the slug from the directory name and flags every i18n call.
- This package carries the bundle at `assets/js/`, `assets/css/` and `assets/wasm/`. Re-vendor with
  `composer copy-assets`, never by editing a copy here. When `assets-in-sync` is red because the matching
  scolta-php PR hasn't merged, do not run `composer copy-assets` to green it.
- `assets/css/amazee-admin.css` and `assets/js/amazee-admin.js` are this plugin's own, not vendored.
- **Do not commit a `.sha256` sidecar.** There used to be an `assets/js/scolta.js.sha256`; nothing
  generated it and nothing read it, so it drifted for two revisions. scolta-php owns that record, and
  `assets-in-sync` compares the asset bytes rather than a claim about them.
