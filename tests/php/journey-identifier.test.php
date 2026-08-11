<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/journey-identifier.php';

function journeyCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function journeyClock(int $timestamp): Closure
{
    return static fn(): DateTimeImmutable => (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atlas-journey-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$identifier = '0123456789abcdef0123456789abcdef';
$hash = hash('sha256', $identifier);
$path = $directory . DIRECTORY_SEPARATOR . $hash . '.json';
$tests = [];

$tests['new identifier is registered without exposing its raw value'] = function () use ($identifier, $directory, $path): void {
    atlasRentalsAssertJourneyIdentifier($identifier, $directory, journeyClock(1000), 300, 900);
    journeyCheck(is_file($path), 'journey state was not created');
    $state = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    journeyCheck($state['status'] === 'active' && $state['firstSeenAt'] === 1000 && $state['expiresAt'] === 1300, 'active state is invalid');
    journeyCheck(!str_contains((string)file_get_contents($path), $identifier), 'raw journey identifier was stored');
};

$tests['active identifier remains usable without extending its expiry'] = function () use ($identifier, $directory, $path): void {
    atlasRentalsAssertJourneyIdentifier($identifier, $directory, journeyClock(1200), 300, 900);
    $state = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    journeyCheck($state['expiresAt'] === 1300 && $state['status'] === 'active', 'active retry extended or changed expiry');
};

$tests['expired identifier fails closed before cleanup and becomes a tombstone'] = function () use ($identifier, $directory, $path): void {
    try { atlasRentalsAssertJourneyIdentifier($identifier, $directory, journeyClock(1300), 300, 900); }
    catch (JourneyIdentifierExpiredException) {
        journeyCheck(is_file($path), 'incoming expired state was deleted before recognition');
        $state = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        journeyCheck($state['status'] === 'expired' && $state['expiredAt'] === 1300, 'expiry tombstone was not retained');
        return;
    }
    throw new RuntimeException('expired identifier was accepted');
};

$tests['expired identifier can never be silently reused while tombstone exists'] = function () use ($identifier, $directory): void {
    foreach ([1400, 2200] as $timestamp) {
        try { atlasRentalsAssertJourneyIdentifier($identifier, $directory, journeyClock($timestamp), 300, 900); }
        catch (JourneyIdentifierExpiredException) { continue; }
        throw new RuntimeException('expired identifier was silently reused');
    }
};

$tests['bounded cleanup removes only old non-current tombstones'] = function () use ($directory, $path): void {
    $otherIdentifier = 'fedcba9876543210fedcba9876543210';
    $otherPath = $directory . DIRECTORY_SEPARATOR . hash('sha256', $otherIdentifier) . '.json';
    file_put_contents($otherPath, json_encode(['version' => 1, 'identifierHash' => hash('sha256', $otherIdentifier), 'status' => 'expired', 'firstSeenAt' => 1, 'expiresAt' => 2, 'expiredAt' => 3], JSON_THROW_ON_ERROR));
    atlasRentalsAssertJourneyIdentifier('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $directory, journeyClock(1000), 300, 900);
    journeyCheck(!is_file($otherPath), 'old non-current tombstone was not cleaned');
    journeyCheck(is_file($path), 'current expired identifier was unexpectedly removed');
};

$tests['invalid state and unavailable private directory fail closed'] = function () use ($directory): void {
    $invalid = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    file_put_contents($directory . DIRECTORY_SEPARATOR . hash('sha256', $invalid) . '.json', '{}');
    try { atlasRentalsAssertJourneyIdentifier($invalid, $directory, journeyClock(1000), 300, 900); }
    catch (RuntimeException) { $failedClosed = true; }
    journeyCheck($failedClosed ?? false, 'invalid state did not fail closed');
    try { atlasRentalsAssertJourneyIdentifier('cccccccccccccccccccccccccccccccc', $directory . DIRECTORY_SEPARATOR . 'missing', journeyClock(1000), 300, 900); }
    catch (RuntimeException) { return; }
    throw new RuntimeException('missing private directory was accepted');
};

$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "PASS {$name}\n"; }
    catch (Throwable $error) { $failed++; fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n"); }
}
foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
@rmdir($directory);
echo sprintf("%d passed, %d failed\n", count($tests) - $failed, $failed);
exit($failed === 0 ? 0 : 1);
