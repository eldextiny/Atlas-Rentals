<?php
declare(strict_types=1);

require_once __DIR__ . '/private-path.php';

function atlasRentalsRateLimitDirectory(string $namespace): string
{
    if (!in_array($namespace, ['submission', 'review'], true)) throw new InvalidArgumentException('Rate limit namespace invalid.');
    $base = atlasRentalsPrivatePath('ATLAS_RENTALS_RATE_LIMIT_STATE_PATH', 'atlas-rentals/rate-limits', 'directory');
    return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $namespace;
}

function atlasRentalsWriteRateLimitState(string $directory, string $path, array $state): void
{
    $temporary = tempnam($directory, 'limit.tmp.');
    $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if ($temporary === false || file_put_contents($temporary, $encoded, LOCK_EX) === false) {
        if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
        throw new RuntimeException('Rate limit state unavailable.');
    }
    @chmod($temporary, 0640);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Rate limit state unavailable.');
    }
}

/**
 * Preserve the established five positional arguments for submission callers.
 * New callers must name every optional argument, including the final namespace,
 * so a directory path and numeric limit/window values cannot exchange positions.
 */
function atlasRentalsEnforceRateLimit(
    string $clientAddress,
    ?string $directory = null,
    int $limit = 10,
    int $windowSeconds = 600,
    ?Closure $clock = null,
    string $namespace = 'submission',
): int {
    if (!in_array($namespace, ['submission', 'review'], true) || $limit < 1 || $windowSeconds < 1) throw new InvalidArgumentException('Rate limit configuration invalid.');
    if (filter_var($clientAddress, FILTER_VALIDATE_IP) === false) $clientAddress = 'unknown';
    $directory ??= atlasRentalsRateLimitDirectory($namespace);
    if (!is_dir($directory) || !is_readable($directory) || !is_writable($directory)) throw new RuntimeException('Rate limit state unavailable.');
    $time = $clock ? $clock() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if (!$time instanceof DateTimeInterface) throw new RuntimeException('Rate limit clock invalid.');
    $now = $time->getTimestamp();
    $addressHash = hash('sha256', $clientAddress);
    $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $addressHash . '.json';
    $lock = @fopen($path . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Rate limit state unavailable.');
    try {
        $stateExists = is_file($path);
        $state = $stateExists ? json_decode((string)file_get_contents($path), true) : null;
        if ($stateExists && (!is_array($state) || ($state['namespace'] ?? null) !== $namespace || ($state['addressHash'] ?? null) !== $addressHash
            || !is_int($state['windowStartedAt'] ?? null) || !is_int($state['count'] ?? null) || $state['count'] < 0)) {
            throw new RuntimeException('Rate limit state invalid.');
        }
        if (!$stateExists) {
            $state = ['version' => 1, 'namespace' => $namespace, 'addressHash' => $addressHash, 'windowStartedAt' => $now, 'count' => 0];
        } elseif ($now >= $state['windowStartedAt'] + $windowSeconds) {
            $state['windowStartedAt'] = $now; $state['count'] = 0;
        }
        if ($state['count'] >= $limit) return max(1, $state['windowStartedAt'] + $windowSeconds - $now);
        $state['count']++;
        atlasRentalsWriteRateLimitState($directory, $path, $state);
        return 0;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
