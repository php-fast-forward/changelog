<?php

declare(strict_types=1);

namespace FastForward\Changelog\GitHub;

/** Optional proof that API evidence belongs to the public GitHub Git transport. */
interface GitHubPublicTransportInterface
{
    /**
     * Identifies the trusted API origin without consulting credentials or the network.
     *
     * @return bool Whether this adapter uses the canonical public GitHub API base.
     */
    public function usesPublicGitHub(): bool;
}
