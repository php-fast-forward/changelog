<?php

declare(strict_types=1);

namespace FastForward\Changelog\GitHub;

/** Optional bounded partial-pack transport; existing complete-pack adapters retain their contract. */
interface GitHubFilteredTransportInterface extends GitHubPublicTransportInterface
{
    /**
     * Omits large blobs while retaining complete commit/tree history for the exact verified PR head.
     *
     * @param  string            $repository API-verified public GitHub owner/name.
     * @param  string            $sha        Complete independently corroborated original PR head.
     * @return string            Credential-free filtered pack, subject to the unchanged 64 MiB response cap.
     * @throws \RuntimeException When the server does not support filtering or bounded transport proof fails.
     */
    public function filteredCommitPack(string $repository, string $sha): string;
}
