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

namespace FastForward\Changelog\GitHub;

/** Isolates authenticated GitHub HTTP access from domain services and unit tests. */
interface GitHubClientInterface
{
    /**
     * Sends a relative API request; 404 and 204 return null, other failures throw.
     * @param  array<string,mixed>|null $body explicit JSON payload
     * @return array<mixed>|null        decoded JSON, without response secrets in diagnostics
     */
    public function request(string $method, string $relativePath, ?array $body = null): ?array;

    /**
     * Collects list pages of 100 items without following external URLs or returning partial evidence.
     * @return list<array<string,mixed>> complete paginated records
     */
    public function paginate(string $relativePath): array;
}
