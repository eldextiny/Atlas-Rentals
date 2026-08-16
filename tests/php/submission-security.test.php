<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/submission-identifier.php';
require_once __DIR__ . '/../../api/rate-limit.php';

function securityExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function securityClock(int $timestamp): Closure
{
    return static fn(): DateTimeImmutable => (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atlas-rentals-security-' . bin2hex(random_bytes(6));
$identifiers = $root . DIRECTORY_SEPARATOR . 'identifiers';
$limits = $root . DIRECTORY_SEPARATOR . 'limits';
mkdir($identifiers, 0700, true);
mkdir($limits, 0700, true);
$identifier = '000003e80123456789abcdef0123456789abcdef';
$identifierPath = $identifiers . DIRECTORY_SEPARATOR . hash('sha256', $identifier) . '.json';
$tests = [];

$tests['identifier is stored hashed and remains stable while active'] = function () use ($identifier, $identifiers, $identifierPath): void {
    atlasRentalsAssertSubmissionIdentifier($identifier, $identifiers, securityClock(1000), 300, 900);
    $first = (string)file_get_contents($identifierPath);
    atlasRentalsAssertSubmissionIdentifier($identifier, $identifiers, securityClock(1200), 300, 900);
    securityExpect($first === (string)file_get_contents($identifierPath), 'active retry extended or changed state');
    securityExpect(!str_contains($first, $identifier), 'raw identifier was persisted');
};

$tests['expired identifier fails closed before cleanup and leaves tombstone'] = function () use ($identifier, $identifiers, $identifierPath): void {
    try { atlasRentalsAssertSubmissionIdentifier($identifier, $identifiers, securityClock(1300), 300, 900); }
    catch (SubmissionIdentifierExpiredException) {
        $state = json_decode((string)file_get_contents($identifierPath), true, 32, JSON_THROW_ON_ERROR);
        securityExpect($state['status'] === 'expired' && $state['expiredAt'] === 1300, 'expired tombstone missing');
        return;
    }
    throw new RuntimeException('expired identifier was accepted');
};

$tests['expired identifier cannot be silently reused while tombstone remains'] = function () use ($identifier, $identifiers): void {
    foreach ([1400, 2000] as $time) {
        try { atlasRentalsAssertSubmissionIdentifier($identifier, $identifiers, securityClock($time), 300, 900); }
        catch (SubmissionIdentifierExpiredException) { continue; }
        throw new RuntimeException('expired identifier was reused');
    }
};

$tests['expired identifier remains self-expiring after bounded tombstone cleanup'] = function () use ($identifier, $identifiers, $identifierPath): void {
    $other = '00000899fedcba9876543210fedcba9876543210';
    atlasRentalsAssertSubmissionIdentifier($other, $identifiers, securityClock(2201), 300, 900);
    securityExpect(!is_file($identifierPath), 'eligible old tombstone was not cleaned');
    try { atlasRentalsAssertSubmissionIdentifier($identifier, $identifiers, securityClock(2201), 300, 900); }
    catch (SubmissionIdentifierExpiredException) {
        securityExpect(is_file($identifierPath), 'expired identifier did not recreate a tombstone');
        return;
    }
    throw new RuntimeException('expired identifier became active after cleanup');
};

$tests['rate limiter is atomic per private address hash and resets by window'] = function () use ($limits): void {
    securityExpect(atlasRentalsEnforceRateLimit('203.0.113.4', $limits, 2, 60, securityClock(1000)) === 0, 'first request blocked');
    securityExpect(atlasRentalsEnforceRateLimit('203.0.113.4', $limits, 2, 60, securityClock(1001)) === 0, 'second request blocked');
    securityExpect(atlasRentalsEnforceRateLimit('203.0.113.4', $limits, 2, 60, securityClock(1002)) === 58, 'limit not enforced');
    securityExpect(atlasRentalsEnforceRateLimit('203.0.113.4', $limits, 2, 60, securityClock(1060)) === 0, 'window did not reset');
    $files = glob($limits . DIRECTORY_SEPARATOR . '*.json') ?: [];
    securityExpect(count($files) === 1 && !str_contains((string)file_get_contents($files[0]), '203.0.113.4'), 'raw address was persisted');
};

$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "PASS {$name}\n"; }
    catch (Throwable $error) { $failed++; fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n"); }
}
foreach ([$identifiers, $limits] as $directory) {
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
    @rmdir($directory);
}
@rmdir($root);
echo sprintf("%d passed, %d failed\n", count($tests) - $failed, $failed);
exit($failed === 0 ? 0 : 1);
