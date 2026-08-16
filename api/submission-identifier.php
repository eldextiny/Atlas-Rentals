<?php
declare(strict_types=1);

const ATLAS_RENTALS_SUBMISSION_STATE = '/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals/submission-identifiers';
const ATLAS_RENTALS_SUBMISSION_TTL = 86400;
const ATLAS_RENTALS_SUBMISSION_TOMBSTONE_TTL = 7776000;

final class SubmissionIdentifierExpiredException extends RuntimeException {}

function atlasRentalsPrivateStateDirectory(string $environment, string $fallback): string
{
    $configured = getenv($environment);
    return $configured === false || $configured === '' ? $fallback : $configured;
}

function atlasRentalsBoundedTtl(string $environment, int $fallback): int
{
    $configured = getenv($environment);
    if ($configured === false || $configured === '') return $fallback;
    if (preg_match('/^[1-9]\d*$/', $configured) !== 1) throw new RuntimeException('Private state configuration invalid.');
    $seconds = (int)$configured;
    if ($seconds < 60 || $seconds > 31536000) throw new RuntimeException('Private state configuration invalid.');
    return $seconds;
}

function atlasRentalsWritePrivateJson(string $directory, string $path, array $state): void
{
    $temporary = tempnam($directory, 'atlas.tmp.');
    $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if ($temporary === false || file_put_contents($temporary, $encoded, LOCK_EX) === false) {
        if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
        throw new RuntimeException('Private state unavailable.');
    }
    @chmod($temporary, 0640);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Private state unavailable.');
    }
}

function atlasRentalsCleanupSubmissionTombstones(string $directory, string $currentPath, int $now, int $retention): void
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

function atlasRentalsAssertSubmissionIdentifier(
    string $identifier,
    ?string $directory = null,
    ?Closure $clock = null,
    ?int $ttl = null,
    ?int $tombstoneRetention = null,
): void {
    if (preg_match('/^[a-f0-9]{40}$/', $identifier) !== 1) throw new InvalidArgumentException('Submission identifier invalid.');
    $directory ??= atlasRentalsPrivateStateDirectory('ATLAS_RENTALS_SUBMISSION_STATE_PATH', ATLAS_RENTALS_SUBMISSION_STATE);
    if (!is_dir($directory) || !is_readable($directory) || !is_writable($directory)) throw new RuntimeException('Submission state unavailable.');
    $ttl ??= atlasRentalsBoundedTtl('ATLAS_RENTALS_SUBMISSION_TTL', ATLAS_RENTALS_SUBMISSION_TTL);
    $tombstoneRetention ??= atlasRentalsBoundedTtl('ATLAS_RENTALS_SUBMISSION_TOMBSTONE_TTL', ATLAS_RENTALS_SUBMISSION_TOMBSTONE_TTL);
    $time = $clock ? $clock() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if (!$time instanceof DateTimeInterface) throw new RuntimeException('Submission clock invalid.');
    $now = $time->getTimestamp();
    $issuedAt = hexdec(substr($identifier, 0, 8));
    if (!is_int($issuedAt) && !is_float($issuedAt)) throw new InvalidArgumentException('Submission identifier invalid.');
    $issuedAt = (int)$issuedAt;
    if ($issuedAt < 1 || $issuedAt > $now + 300) throw new InvalidArgumentException('Submission identifier invalid.');
    $expiresAt = $issuedAt + $ttl;
    $hash = hash('sha256', $identifier);
    $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $hash . '.json';
    $lock = @fopen($path . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Submission state unavailable.');
    try {
        if (is_file($path)) {
            $state = json_decode((string)file_get_contents($path), true);
            if (!is_array($state) || ($state['identifierHash'] ?? '') !== $hash || !is_int($state['expiresAt'] ?? null)) throw new RuntimeException('Submission state invalid.');
            if (($state['expiresAt'] ?? null) !== $expiresAt) throw new RuntimeException('Submission state invalid.');
            if (($state['status'] ?? '') === 'expired' || $now >= $expiresAt) {
                if (($state['status'] ?? '') !== 'expired') {
                    $state['status'] = 'expired';
                    $state['expiredAt'] = $now;
                    atlasRentalsWritePrivateJson($directory, $path, $state);
                }
                throw new SubmissionIdentifierExpiredException('Submission identifier expired.');
            }
            if (($state['status'] ?? '') !== 'active') throw new RuntimeException('Submission state invalid.');
        } elseif ($now >= $expiresAt) {
            atlasRentalsWritePrivateJson($directory, $path, [
                'version' => 1, 'identifierHash' => $hash, 'status' => 'expired',
                'issuedAt' => $issuedAt, 'expiresAt' => $expiresAt, 'expiredAt' => $now,
            ]);
            throw new SubmissionIdentifierExpiredException('Submission identifier expired.');
        } else {
            atlasRentalsWritePrivateJson($directory, $path, [
                'version' => 1, 'identifierHash' => $hash, 'status' => 'active',
                'issuedAt' => $issuedAt, 'firstSeenAt' => $now, 'expiresAt' => $expiresAt,
            ]);
        }
        atlasRentalsCleanupSubmissionTombstones($directory, $path, $now, $tombstoneRetention);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function atlasRentalsSubmissionIdentifierCheck(): Closure
{
    return static function (string $identifier): void {
        atlasRentalsAssertSubmissionIdentifier($identifier);
    };
}
