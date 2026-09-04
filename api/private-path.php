<?php
declare(strict_types=1);

function atlasRentalsPublicRoot(?string $publicRoot = null): string
{
    $resolved = realpath($publicRoot ?? dirname(__DIR__));
    if ($resolved === false || !is_dir($resolved)) throw new RuntimeException('Public application root unavailable.');
    return rtrim($resolved, '/\\');
}

function atlasRentalsPathIsWithin(string $path, string $root): bool
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (DIRECTORY_SEPARATOR === '\\') { $path = strtolower($path); $root = strtolower($root); }
    return $path === $root || str_starts_with($path, $root . '/');
}

function atlasRentalsPrivateRoot(?string $publicRoot = null): string
{
    $public = atlasRentalsPublicRoot($publicRoot);
    if (strtolower(basename($public)) !== 'public_html') throw new RuntimeException('Safe private application root unavailable.');
    $private = realpath(dirname($public) . DIRECTORY_SEPARATOR . 'private_html');
    if ($private === false || !is_dir($private) || dirname($private) !== dirname($public)
        || atlasRentalsPathIsWithin($private, $public)) {
        throw new RuntimeException('Safe private application root unavailable.');
    }
    return rtrim($private, '/\\');
}

function atlasRentalsPrivatePath(
    string $environment,
    string $relativePath,
    string $type,
    ?string $publicRoot = null,
): string {
    if (!in_array($type, ['file', 'directory'], true) || $relativePath === '' || str_contains($relativePath, '..')) {
        throw new InvalidArgumentException('Private path contract invalid.');
    }
    $public = atlasRentalsPublicRoot($publicRoot);
    $override = getenv($environment);
    $candidate = $override !== false && $override !== ''
        ? $override
        : atlasRentalsPrivateRoot($public) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    return atlasRentalsAssertPrivatePath($candidate, $type, $public);
}

function atlasRentalsAssertPrivatePath(string $candidate, string $type, ?string $publicRoot = null): string
{
    if (!in_array($type, ['file', 'directory'], true)) throw new InvalidArgumentException('Private path contract invalid.');
    $public = atlasRentalsPublicRoot($publicRoot);
    if (is_link($candidate)) throw new RuntimeException('Unsafe private path rejected.');
    $resolved = realpath($candidate);
    if ($resolved === false || ($type === 'file' ? !is_file($resolved) : !is_dir($resolved))
        || atlasRentalsPathIsWithin($resolved, $public)) {
        throw new RuntimeException('Private path unavailable.');
    }
    return $resolved;
}
