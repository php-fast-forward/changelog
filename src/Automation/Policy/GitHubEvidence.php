<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license https://opensource.org/licenses/MIT MIT License
 * @see https://github.com/php-fast-forward/changelog
 * @see https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Automation\Policy;

/** Validates API evidence without considering user-controlled PR prose or Git author emails. */
final class GitHubEvidence
{
    /** Accepts an explicit owner/name, preventing endpoint and query injection. */
    public static function repository(?string $repository): bool
    {
        return null !== $repository && 1 === preg_match('~\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z~', $repository);
    }

    /** Matches the actual GitHub account object, including its immutable numeric identity. */
    public static function identity(mixed $user, string $login, string $type, ?int $id = null): bool
    {
        return is_array($user) && ($user['login'] ?? null) === $login && ($user['type'] ?? null) === $type
            && is_int($user['id'] ?? null) && $user['id'] > 0 && (null === $id || $user['id'] === $id);
    }

    /** Requires an open PR and a complete branch identity; privileged writes additionally require the same repository. */
    public static function pullRequest(?array $pr, string $repository, int $number, bool $sameRepository = true): bool
    {
        return null !== $pr && ($pr['number'] ?? null) === $number && ($pr['state'] ?? null) === 'open'
            && is_int($pr['base']['repo']['id'] ?? null) && $pr['base']['repo']['id'] > 0
            && is_int($pr['head']['repo']['id'] ?? null) && $pr['head']['repo']['id'] > 0
            && (! $sameRepository || ($pr['head']['repo']['id'] ?? null) === $pr['base']['repo']['id'])
            && strtolower($pr['base']['repo']['full_name'] ?? '') === strtolower($repository)
            && is_string($pr['head']['repo']['full_name'] ?? null)
            && (! $sameRepository || strtolower($pr['head']['repo']['full_name']) === strtolower($repository))
            && is_string($pr['head']['ref'] ?? null) && '' !== $pr['head']['ref']
            && 1 === preg_match('/\A[A-Za-z0-9_\/.+-]+\z/', $pr['head']['ref'])
            && ! str_contains($pr['head']['ref'], '..')
            && is_string($pr['head']['sha'] ?? null) && self::sha($pr['head']['sha'])
            && is_string($pr['base']['sha'] ?? null) && self::sha($pr['base']['sha']);
    }

    /** Accepts complete SHA-1 or SHA-256 Git object identities, never abbreviated refs. */
    public static function sha(string $sha): bool
    {
        return 1 === preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/', $sha);
    }

    /** Decodes a complete API file response; directories and truncated/download-only responses fail closed. */
    public static function content(?array $file): ?string
    {
        if (null === $file || ($file['type'] ?? null) !== 'file' || ($file['encoding'] ?? null) !== 'base64' || ! is_string($file['content'] ?? null)) {
            return null;
        }
        $contents = base64_decode($file['content'], true);
        return false === $contents ? null : $contents;
    }

    /** Encodes each validated relative path segment and pins reads to an immutable commit. */
    public static function contentsPath(string $repository, string $path, string $sha): string
    {
        return '/repos/' . $repository . '/contents/' . implode('/', array_map(rawurlencode(...), explode('/', $path))) . '?ref=' . $sha;
    }
}
