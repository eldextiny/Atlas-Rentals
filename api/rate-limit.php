<?php
declare(strict_types=1);

const ATLAS_RENTALS_RATE_LIMIT_STATE = '/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals/rate-limits';

function atlasRentalsRateLimitDirectory(string $namespace): string
{
    if (!in_array($namespace, ['submission', 'review'], true)) throw new InvalidArgumentException('Rate limit namespace invalid.');
    $configured = getenv('ATLAS_RENTALS_RATE_LIMIT_STATE_PATH');
    $base = $configured === false || $configured === '' ? ATLAS_RENTALS_RATE_LIMIT_STATE : $configured;
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

function atlasRentalsEnforceRateLimit(
    string $namespace,
    string $clientAddress,
    ?string $directory = null,
    int $limit = 10,
    int $windowSeconds = 600,
    ?Closure $clock = null,
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
