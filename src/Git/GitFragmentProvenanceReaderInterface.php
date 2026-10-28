<?php

declare(strict_types=1);

namespace FastForward\Changelog\Git;

use InvalidArgumentException;
use RuntimeException;

/** Optional proof that exact fragment bytes were authored on a PR's own first-parent line. */
interface GitFragmentProvenanceReaderInterface
{
    /**
     * Refuses fragments imported unchanged from a merge side parent, including nested implementation PRs.
     *
     * @param  string                   $directory Consumer directory used to interpret the canonical fragment path.
     * @param  string                   $headSha   Complete original PR head containing the accepted regular-file bytes.
     * @param  string                   $path      Canonical consumer-relative fragment path.
     * @param  string                   $sha256    SHA-256 of the accepted fragment bytes.
     * @return bool                     Whether a complete first-parent origin exists without inheriting its bytes from a side parent.
     * @throws InvalidArgumentException When the input identity or path is unsafe.
     * @throws RuntimeException         When immutable Git evidence is incomplete, malformed or exceeds its bounds.
     */
    public function fragmentHasDirectOrigin(string $directory, string $headSha, string $path, string $sha256): bool;
}
