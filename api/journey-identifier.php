<?php
declare(strict_types=1);

const ATLAS_RENTALS_JOURNEY_STATE = '/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals/journey-identifiers';
const ATLAS_RENTALS_JOURNEY_TTL = 86400;
const ATLAS_RENTALS_JOURNEY_TOMBSTONE_TTL = 7776000;

final class JourneyIdentifierExpiredException extends RuntimeException {}

function atlasRentalsJourneyStateDirectory(): string
{
    return getenv('ATLAS_RENTALS_JOURNEY_STATE_PATH') ?: ATLAS_RENTALS_JOURNEY_STATE;
}

function atlasRentalsJourneyTtl(string $environment, int $fallback): int
{
    $value = getenv($environment);
    if ($value === false || $value === '') return $fallback;
    if (preg_match('/^[1-9]\d*$/', $value) !== 1) throw new RuntimeException('Journey configuration invalid.');
    $seconds = (int)$value;
    if ($seconds < 60 || $seconds > 31536000) throw new RuntimeException('Journey configuration invalid.');
    return $seconds;
}

function atlasRentalsWriteJourneyState(string $directory, string $path, array $state): void
{
    $temporary = tempnam($directory, 'journey.tmp.');
    $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if ($temporary === false || file_put_contents($temporary, $encoded, LOCK_EX) === false) {
        if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
        throw new RuntimeException('Journey state unavailable.');
    }
    @chmod($temporary, 0640);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Journey state unavailable.');
    }
}

function atlasRentalsCleanupJourneyTombstones(string $directory, string $currentPath, int $now, int $retention): void
{
    foreach (glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [] as $candidate) {
        if ($candidate === $currentPath || !is_file($candidate) || !is_readable($candidate)) continue;
        $lock = @fopen($candidate . '.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            continue;
        }
        try {
            if (!is_file($candidate)) continue;
            $state = json_decode((string)file_get_contents($candidate), true);
            if (!is_array($state) || ($state['status'] ?? '') !== 'expired') continue;
            $expiredAt = $state['expiredAt'] ?? null;
            if (is_int($expiredAt) && $expiredAt + $retention < $now) @unlink($candidate);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

function atlasRentalsAssertJourneyIdentifier(
    string $identifier,
    ?string $directory = null,
    ?Closure $clock = null,
    ?int $ttl = null,
    ?int $tombstoneRetention = null,
): void {
    if (preg_match('/^[a-f0-9]{32}$/', $identifier) !== 1) throw new InvalidArgumentException('Journey identity invalid.');
    $directory ??= atlasRentalsJourneyStateDirectory();
    if (!is_dir($directory) || !is_readable($directory) || !is_writable($directory)) throw new RuntimeException('Journey state unavailable.');
    $ttl ??= atlasRentalsJourneyTtl('ATLAS_RENTALS_JOURNEY_TTL', ATLAS_RENTALS_JOURNEY_TTL);
    $tombstoneRetention ??= atlasRentalsJourneyTtl('ATLAS_RENTALS_JOURNEY_TOMBSTONE_TTL', ATLAS_RENTALS_JOURNEY_TOMBSTONE_TTL);
    $nowValue = $clock ? $clock() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if (!$nowValue instanceof DateTimeInterface) throw new RuntimeException('Journey clock invalid.');
    $now = $nowValue->getTimestamp();
    $hash = hash('sha256', $identifier);
    $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $hash . '.json';
    $lock = fopen($path . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Journey state unavailable.');
    try {
        if (is_file($path)) {
            $state = json_decode((string)file_get_contents($path), true);
            if (!is_array($state) || ($state['identifierHash'] ?? '') !== $hash || !is_int($state['expiresAt'] ?? null)) {
                throw new RuntimeException('Journey state invalid.');
            }
            if (($state['status'] ?? '') === 'expired' || $now >= $state['expiresAt']) {
                if (($state['status'] ?? '') !== 'expired') {
                    $state['status'] = 'expired';
                    $state['expiredAt'] = $now;
                    atlasRentalsWriteJourneyState($directory, $path, $state);
                }
                throw new JourneyIdentifierExpiredException('Journey identity expired.');
            }
            if (($state['status'] ?? '') !== 'active') throw new RuntimeException('Journey state invalid.');
        } else {
            atlasRentalsWriteJourneyState($directory, $path, [
                'version' => 1,
                'identifierHash' => $hash,
                'status' => 'active',
                'firstSeenAt' => $now,
                'expiresAt' => $now + $ttl,
            ]);
        }
        atlasRentalsCleanupJourneyTombstones($directory, $path, $now, $tombstoneRetention);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function atlasRentalsJourneyIdentifierCheck(): Closure
{
    return static function (string $identifier): void {
        atlasRentalsAssertJourneyIdentifier($identifier);
    };
}
