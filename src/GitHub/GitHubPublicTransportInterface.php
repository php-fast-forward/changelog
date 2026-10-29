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

    /**
     * Downloads a complete pack for one independently verified PR head over authenticated public HTTPS.
     *
     * @param  string            $repository API-verified owner/name, never a URL or ref.
     * @param  string            $sha        Complete API-verified original PR head.
     * @return string            Credential-free Git pack bytes for import through the local Git boundary.
     * @throws \RuntimeException When inputs, authentication, HTTP or the bounded Git response cannot be verified.
     */
    public function commitPack(string $repository, string $sha): string;
}
