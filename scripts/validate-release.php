<?php
/**
 * Validate scolta is ready for release.
 *
 * WordPress has FOUR places where the version must match:
 * 1. composer.json "version" field
 * 2. Plugin header comment (Version: X.Y.Z)
 * 3. SCOLTA_VERSION constant in scolta.php
 * 4. readme.txt "Stable Tag" field
 */

// 1. composer.json
$composer = json_decode( file_get_contents( __DIR__ . '/../composer.json' ), true );
$composerVersion = $composer['version'] ?? 'MISSING';

// 2. Plugin header
$pluginFile = file_get_contents( __DIR__ . '/../scolta.php' );
preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $pluginFile, $m );
$headerVersion = trim( $m[1] ?? 'MISSING' );

// 3. SCOLTA_VERSION constant
preg_match( "/define\(\s*'SCOLTA_VERSION'\s*,\s*'([^']+)'/", $pluginFile, $m );
$constantVersion = $m[1] ?? 'MISSING';

// 4. readme.txt Stable Tag
$readmeTxt = file_get_contents( __DIR__ . '/../readme.txt' );
preg_match( '/^Stable Tag:\s*(.+)$/mi', $readmeTxt, $m );
$stableTag = trim( $m[1] ?? 'MISSING' );

echo "composer.json:    {$composerVersion}\n";
echo "Plugin header:    {$headerVersion}\n";
echo "SCOLTA_VERSION:   {$constantVersion}\n";
echo "readme.txt:       {$stableTag}\n";

$fail = false;

$allMatch = ( $composerVersion === $headerVersion
	&& $headerVersion === $constantVersion
	&& $constantVersion === $stableTag );
if ( ! $allMatch ) {
	echo "FAIL: Versions don't match across the four locations\n";
	$fail = true;
}

if ( $composerVersion === 'MISSING' || $headerVersion === 'MISSING'
	|| $constantVersion === 'MISSING' || $stableTag === 'MISSING' ) {
	echo "FAIL: One or more version locations are missing\n";
	$fail = true;
}

if ( str_ends_with( $composerVersion, '-dev' ) ) {
	echo "FAIL: Version ends in -dev\n";
	$fail = true;
}

if ( ! $fail ) {
	echo "PASS: All four locations match: {$composerVersion}\n";
} else {
	exit( 1 );
}
