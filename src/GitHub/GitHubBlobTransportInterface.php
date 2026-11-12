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
 */

namespace FastForward\Changelog\GitHub;

/** Optional streaming upload for explicitly selected generated release files. */
interface GitHubBlobTransportInterface
{
    /**
     * Creates an immutable blob without changing any tree, commit or repository ref.
     *
     * @param  string            $repository Validated GitHub owner/name selected by the trusted caller.
     * @param  string            $contents   Exact binary bytes; encoding MUST remain bounded in memory.
     * @return string            Git blob SHA-1 independently checked against the submitted bytes.
     * @throws \RuntimeException When authority, size, transport or returned identity is invalid.
     */
    public function createBlob(string $repository, string $contents): string;

    /**
     * Hashes raw committed bytes while independently verifying their Git blob identity and size.
     *
     * @param  string            $repository Trusted GitHub owner/name selected by the caller.
     * @param  string            $sha        Complete Git blob SHA-1 obtained from the immutable commit tree.
     * @param  int               $size       Expected blob length obtained from the same tree.
     * @return string            SHA-256 of the exact committed bytes, without a buffered base64 response.
     * @throws \RuntimeException When authority, bounds, bytes or identity cannot be established.
     */
    public function blobDigest(string $repository, string $sha, int $size): string;
}
