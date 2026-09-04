<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/journey-identifier.php';

function journeyCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function journeyClock(int $timestamp): Closure { return static fn(): DateTimeImmutable => (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC')); }

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atlas-journey-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$identifier = 'j1.000003e8.0123456789abcdef0123456789abcdef';
$path = $directory . DIRECTORY_SEPARATOR . hash('sha256', $identifier) . '.json';
$tests = [];

$tests['versioned identifier is registered from its intrinsic timestamp without exposing raw value'] = function () use ($identifier, $directory, $path): void {
    atlasRentalsAssertJourneyIdentifier($identifier, $directory, journeyClock(1000), 300, 900);
    $state = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    journeyCheck($state['version'] === 2 && $state['issuedAt'] === 1000 && $state['expiresAt'] === 1300 && $state['status'] === 'active', 'versioned state is invalid');
    journeyCheck(!str_contains((string)file_get_contents($path), $identifier), 'raw journey identifier was stored');
};

$tests['retry never extends intrinsic expiry'] = function () use ($identifier, $directory, $path): void {
    atlasRentalsAssertJourneyIdentifier($identifier, $directory, journeyClock(1200), 300, 900);
    $state = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    journeyCheck($state['expiresAt'] === 1300 && $state['status'] === 'active', 'retry extended or changed expiry');
};

$tests['malformed version timestamp randomness and future skew are rejected'] = function () use ($directory): void {
    foreach (['j2.000003e8.0123456789abcdef0123456789abcdef', 'j1.notatime.0123456789abcdef0123456789abcdef', 'j1.000003e8.0123456789abcdef', 'j1.000003e8.00000000000000000000000000000000', 'j1.00000515.0123456789abcdef0123456789abcdef'] as $invalid) {
        try { atlasRentalsAssertJourneyIdentifier($invalid, $directory, journeyClock(1000), 300, 900); }
        catch (InvalidArgumentException) { continue; }
        throw new RuntimeException("invalid identifier accepted: {$invalid}");
    }
};

$tests['intrinsic expiry creates a tombstone and survives tombstone deletion'] = function () use ($identifier, $directory, $path): void {
    try { atlasRentalsAssertJourneyIdentifier($identifier, $directory, journeyClock(1300), 300, 900); }
    catch (JourneyIdentifierExpiredException) {
        $state = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        journeyCheck($state['status'] === 'expired' && $state['expiredAt'] === 1300, 'expired tombstone missing');
    }
    @unlink($path);
    try { atlasRentalsAssertJourneyIdentifier($identifier, $directory, journeyClock(2301), 300, 900); }
    catch (JourneyIdentifierExpiredException) { journeyCheck(is_file($path), 'expired identifier did not recreate tombstone'); return; }
    throw new RuntimeException('identifier became active after tombstone deletion');
};

$tests['valid existing legacy journey remains active without conversion'] = function () use ($directory): void {
    $legacy = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $legacyPath = $directory . DIRECTORY_SEPARATOR . hash('sha256', $legacy) . '.json';
    file_put_contents($legacyPath, json_encode(['version' => 1, 'identifierHash' => hash('sha256', $legacy), 'status' => 'active', 'firstSeenAt' => 900, 'expiresAt' => 1200], JSON_THROW_ON_ERROR));
    atlasRentalsAssertJourneyIdentifier($legacy, $directory, journeyClock(1000), 300, 900);
    $state = json_decode((string)file_get_contents($legacyPath), true, 32, JSON_THROW_ON_ERROR);
    journeyCheck($state['version'] === 1 && !isset($state['issuedAt']) && $state['expiresAt'] === 1200, 'legacy state was converted or extended');
};

$tests['unseen legacy journey is rejected without creating state'] = function () use ($directory): void {
    $legacy = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    $legacyPath = $directory . DIRECTORY_SEPARATOR . hash('sha256', $legacy) . '.json';
    try { atlasRentalsAssertJourneyIdentifier($legacy, $directory, journeyClock(1000), 300, 900); }
    catch (JourneyIdentifierExpiredException) { journeyCheck(!is_file($legacyPath), 'unseen legacy identifier created state'); return; }
    throw new RuntimeException('unseen legacy identifier was accepted');
};

$tests['cleanup protects current state and invalid or unavailable state fails closed'] = function () use ($directory, $path): void {
    $other = 'j1.00000001.fedcba9876543210fedcba9876543210';
    $otherPath = $directory . DIRECTORY_SEPARATOR . hash('sha256', $other) . '.json';
    file_put_contents($otherPath, json_encode(['version' => 2, 'identifierHash' => hash('sha256', $other), 'status' => 'expired', 'issuedAt' => 1, 'expiresAt' => 2, 'expiredAt' => 3], JSON_THROW_ON_ERROR));
    $current = 'j1.00000384.11111111111111111111111111111111';
    atlasRentalsAssertJourneyIdentifier($current, $directory, journeyClock(1000), 300, 900);
    journeyCheck(!is_file($otherPath), 'old non-current tombstone was not cleaned');
    journeyCheck(is_file($path), 'current expired tombstone was removed during another check');
    $invalid = 'cccccccccccccccccccccccccccccccc';
    file_put_contents($directory . DIRECTORY_SEPARATOR . hash('sha256', $invalid) . '.json', '{}');
    try { atlasRentalsAssertJourneyIdentifier($invalid, $directory, journeyClock(1000), 300, 900); } catch (RuntimeException) { $failedClosed = true; }
    journeyCheck($failedClosed ?? false, 'invalid state did not fail closed');
    try { atlasRentalsAssertJourneyIdentifier($current, $directory . DIRECTORY_SEPARATOR . 'missing', journeyClock(1000), 300, 900); } catch (RuntimeException) { return; }
    throw new RuntimeException('missing private directory was accepted');
};

$failed = 0;
foreach ($tests as $name => $test) { try { $test(); echo "PASS {$name}\n"; } catch (Throwable $error) { $failed++; fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n"); } }
foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
@rmdir($directory);
echo sprintf("%d passed, %d failed\n", count($tests) - $failed, $failed);
exit($failed === 0 ? 0 : 1);
