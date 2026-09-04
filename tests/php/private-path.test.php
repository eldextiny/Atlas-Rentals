<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/private-path.php';

function pathCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atlas-private-path-' . bin2hex(random_bytes(6));
$production = $root . DIRECTORY_SEPARATOR . 'production';
$staging = $root . DIRECTORY_SEPARATOR . 'staging';
foreach ([$production, $staging] as $application) {
    mkdir($application . DIRECTORY_SEPARATOR . 'public_html', 0700, true);
    mkdir($application . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'atlas-rentals' . DIRECTORY_SEPARATOR . 'delivery-state', 0700, true);
    file_put_contents($application . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'atlas-rentals-db.php', '<?php return [];');
}
$tests = [];

$tests['each application derives only its own sibling private root'] = function () use ($production, $staging): void {
    $productionPath = atlasRentalsPrivatePath('ATLAS_TEST_UNSET', 'atlas-rentals-db.php', 'file', $production . DIRECTORY_SEPARATOR . 'public_html');
    $stagingPath = atlasRentalsPrivatePath('ATLAS_TEST_UNSET', 'atlas-rentals-db.php', 'file', $staging . DIRECTORY_SEPARATOR . 'public_html');
    pathCheck($productionPath === realpath($production . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'atlas-rentals-db.php'), 'production did not resolve its own private loader');
    pathCheck($stagingPath === realpath($staging . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'atlas-rentals-db.php'), 'staging did not resolve its own private loader');
    pathCheck($productionPath !== $stagingPath, 'applications shared a private loader');
};

$tests['environment override wins without permitting public storage'] = function () use ($production, $staging): void {
    $override = $staging . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'atlas-rentals-db.php';
    putenv('ATLAS_TEST_PRIVATE_PATH=' . $override);
    pathCheck(atlasRentalsPrivatePath('ATLAS_TEST_PRIVATE_PATH', 'atlas-rentals-db.php', 'file', $production . DIRECTORY_SEPARATOR . 'public_html') === realpath($override), 'environment override did not win');
    $unsafe = $production . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'config.php';
    file_put_contents($unsafe, '<?php return [];'); putenv('ATLAS_TEST_PRIVATE_PATH=' . $unsafe);
    try { atlasRentalsPrivatePath('ATLAS_TEST_PRIVATE_PATH', 'atlas-rentals-db.php', 'file', $production . DIRECTORY_SEPARATOR . 'public_html'); }
    catch (RuntimeException) { putenv('ATLAS_TEST_PRIVATE_PATH'); return; }
    putenv('ATLAS_TEST_PRIVATE_PATH'); throw new RuntimeException('public_html override was accepted');
};

$tests['missing roots files and directories fail closed without cross-application fallback'] = function () use ($root, $production, $staging): void {
    $missingApplication = $root . DIRECTORY_SEPARATOR . 'missing-private'; mkdir($missingApplication . DIRECTORY_SEPARATOR . 'public_html', 0700, true);
    foreach ([
        static fn() => atlasRentalsPrivatePath('ATLAS_TEST_UNSET', 'atlas-rentals-db.php', 'file', $missingApplication . DIRECTORY_SEPARATOR . 'public_html'),
        static fn() => atlasRentalsPrivatePath('ATLAS_TEST_UNSET', 'missing.php', 'file', $staging . DIRECTORY_SEPARATOR . 'public_html'),
        static fn() => atlasRentalsPrivatePath('ATLAS_TEST_UNSET', 'atlas-rentals/missing-state', 'directory', $production . DIRECTORY_SEPARATOR . 'public_html'),
        static fn() => atlasRentalsPrivatePath('ATLAS_TEST_UNSET', 'atlas-rentals-db.php', 'file', $root),
    ] as $operation) {
        try { $operation(); } catch (RuntimeException) { continue; }
        throw new RuntimeException('missing or unsafe application path did not fail closed');
    }
};

$failed = 0;
foreach ($tests as $name => $test) { try { $test(); echo "PASS {$name}\n"; } catch (Throwable $error) { $failed++; fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n"); } }
putenv('ATLAS_TEST_PRIVATE_PATH');
$remove = function (string $directory) use (&$remove): void { foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) { is_dir($path) ? $remove($path) : @unlink($path); } @rmdir($directory); };
$remove($root);
echo sprintf("%d passed, %d failed\n", count($tests) - $failed, $failed);
exit($failed === 0 ? 0 : 1);
