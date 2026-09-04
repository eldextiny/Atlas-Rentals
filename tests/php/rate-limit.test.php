<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/rate-limit.php';

function limitCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function limitClock(int $timestamp): Closure
{
    return static fn(): DateTimeImmutable => (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atlas-rate-limit-' . bin2hex(random_bytes(6));
$submission = $root . DIRECTORY_SEPARATOR . 'submission';
$review = $root . DIRECTORY_SEPARATOR . 'review';
mkdir($submission, 0700, true); mkdir($review, 0700, true);
$tests = [];

$tests['threshold retry-after and window reset are exact'] = function () use ($submission): void {
    limitCheck(atlasRentalsEnforceRateLimit('submission', '203.0.113.4', $submission, 2, 60, limitClock(1000)) === 0, 'first request blocked');
    limitCheck(atlasRentalsEnforceRateLimit('submission', '203.0.113.4', $submission, 2, 60, limitClock(1001)) === 0, 'second request blocked');
    limitCheck(atlasRentalsEnforceRateLimit('submission', '203.0.113.4', $submission, 2, 60, limitClock(1002)) === 58, 'retry-after incorrect');
    limitCheck(atlasRentalsEnforceRateLimit('submission', '203.0.113.4', $submission, 2, 60, limitClock(1060)) === 0, 'window did not reset');
};

$tests['submission review and unknown-address buckets are independent and hashed'] = function () use ($submission, $review): void {
    limitCheck(atlasRentalsEnforceRateLimit('review', '203.0.113.4', $review, 1, 60, limitClock(1000)) === 0, 'review request blocked');
    limitCheck(atlasRentalsEnforceRateLimit('review', '203.0.113.4', $review, 1, 60, limitClock(1001)) === 59, 'review threshold not independent');
    atlasRentalsEnforceRateLimit('submission', 'caller-forwarded-value', $submission, 10, 60, limitClock(1000));
    $unknownPath = $submission . DIRECTORY_SEPARATOR . hash('sha256', 'unknown') . '.json';
    limitCheck(is_file($unknownPath), 'invalid address did not use unknown bucket');
    foreach (array_merge(glob($submission . DIRECTORY_SEPARATOR . '*.json') ?: [], glob($review . DIRECTORY_SEPARATOR . '*.json') ?: []) as $file) {
        $contents = (string)file_get_contents($file);
        limitCheck(!str_contains($contents, '203.0.113.4') && !str_contains($contents, 'caller-forwarded-value'), 'raw address was stored');
        $state = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        limitCheck(preg_match('/^[a-f0-9]{64}$/D', $state['addressHash'] ?? '') === 1, 'address hash missing');
    }
};

$tests['state uses a lock and atomic replacement and unavailable storage fails closed'] = function () use ($submission, $root): void {
    $addressPath = $submission . DIRECTORY_SEPARATOR . hash('sha256', '198.51.100.2') . '.json';
    atlasRentalsEnforceRateLimit('submission', '198.51.100.2', $submission, 10, 60, limitClock(1000));
    limitCheck(is_file($addressPath) && is_file($addressPath . '.lock'), 'state or lock file missing');
    limitCheck((glob($submission . DIRECTORY_SEPARATOR . 'limit.tmp.*') ?: []) === [], 'atomic temporary file leaked');
    file_put_contents($addressPath, '{}');
    try { atlasRentalsEnforceRateLimit('submission', '198.51.100.2', $submission, 10, 60, limitClock(1001)); } catch (RuntimeException) { $invalidFailed = true; }
    limitCheck($invalidFailed ?? false, 'invalid authority state did not fail closed');
    try { atlasRentalsEnforceRateLimit('submission', '198.51.100.2', $root . DIRECTORY_SEPARATOR . 'missing', 10, 60, limitClock(1000)); }
    catch (RuntimeException) { return; }
    throw new RuntimeException('unavailable storage was accepted');
};

$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "PASS {$name}\n"; }
    catch (Throwable $error) { $failed++; fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n"); }
}
foreach ([$submission, $review] as $directory) {
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
    @rmdir($directory);
}
@rmdir($root);
echo sprintf("%d passed, %d failed\n", count($tests) - $failed, $failed);
exit($failed === 0 ? 0 : 1);
