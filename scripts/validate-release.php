<?php
/**
 * Validate scolta is ready for release.
 *
 * WordPress has THREE places where the version must match:
 * 1. Plugin header comment (Version: X.Y.Z) — what WordPress itself reads
 * 2. SCOLTA_VERSION constant in scolta.php
 * 3. readme.txt "Stable Tag" field — what WordPress.org reads
 *
 * There used to be a fourth, a "version" key in composer.json, and this
 * script asserted all four matched. That key is gone: declaring a version in
 * a package published from version control overrides the version Composer
 * derives from the branch or tag, which is what the extra.branch-alias
 * beside it exists to describe. WordPress reads the plugin header and
 * WordPress.org reads the Stable Tag, so nothing needed it declared.
 */

$pluginFile = file_get_contents( __DIR__ . '/../scolta.php' );

// 1. Plugin header.
preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $pluginFile, $m );
$headerVersion = trim( $m[1] ?? 'MISSING' );

// 2. SCOLTA_VERSION constant.
preg_match( "/define\(\s*'SCOLTA_VERSION'\s*,\s*'([^']+)'/", $pluginFile, $m );
$constantVersion = $m[1] ?? 'MISSING';

// 3. readme.txt Stable Tag.
$readmeTxt = file_get_contents( __DIR__ . '/../readme.txt' );
preg_match( '/^Stable Tag:\s*(.+)$/mi', $readmeTxt, $m );
$stableTag = trim( $m[1] ?? 'MISSING' );

echo "Plugin header:    {$headerVersion}\n";
echo "SCOLTA_VERSION:   {$constantVersion}\n";
echo "readme.txt:       {$stableTag}\n";

$fail = false;

$allMatch = ( $headerVersion === $constantVersion && $constantVersion === $stableTag );
if ( ! $allMatch ) {
	echo "FAIL: Versions don't match across the three locations\n";
	$fail = true;
}

if ( $headerVersion === 'MISSING' || $constantVersion === 'MISSING' || $stableTag === 'MISSING' ) {
	echo "FAIL: One or more version locations are missing\n";
	$fail = true;
}

if ( str_ends_with( $headerVersion, '-dev' ) ) {
	echo "FAIL: Version ends in -dev\n";
	$fail = true;
}

// A hardcoded "version" must not come back. It is the defect this script was
// rewritten around, and it is a one-line edit to reintroduce.
$composer = json_decode( file_get_contents( __DIR__ . '/../composer.json' ), true );
if ( array_key_exists( 'version', $composer ) ) {
	echo "FAIL: composer.json declares a \"version\" key.\n";
	echo "      Remove it. Composer derives the version from the branch or tag;\n";
	echo "      extra.branch-alias describes the mapping. The plugin header is\n";
	echo "      the source of the plugin version.\n";
	$fail = true;
}

if ( ! $fail ) {
	echo "PASS: All three locations match: {$headerVersion} (composer.json declares no version).\n";
} else {
	exit( 1 );
}
