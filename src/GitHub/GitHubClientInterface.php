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

use RuntimeException;

/** Isolates authenticated GitHub HTTP access from domain services and unit tests. */
interface GitHubClientInterface
{
    /**
     * Sends a relative API request; 404 and 204 return null, other failures throw.
     *
     * @param  string                   $method       Explicit uppercase HTTP method; the adapter never infers a mutation from payload presence.
     * @param  string                   $relativePath Repository-scoped relative API endpoint constrained to the trusted HTTPS origin.
     * @param  array<string,mixed>|null $body         Explicit JSON request payload, or null for a request without a JSON body.
     * @return array<mixed>|null        Decoded JSON object/list, or null only for HTTP 404 or 204.
     * @throws RuntimeException         When API settings, HTTP/JSON evidence or the bounded trusted pagination contract fail.
     */
    public function request(string $method, string $relativePath, ?array $body = null): ?array;

    /**
     * Collects list pages of 100 items without following external URLs or returning partial evidence.
     *
     * @param  string                    $relativePath Repository-scoped relative API endpoint constrained to the trusted HTTPS origin.
     * @return list<array<string,mixed>> Complete ordered record list; an incomplete or untrusted page chain never
     *                                   returns partial data.
     * @throws RuntimeException          When API settings, HTTP/JSON evidence or the bounded trusted pagination contract fail.
     */
    public function paginate(string $relativePath): array;
}
