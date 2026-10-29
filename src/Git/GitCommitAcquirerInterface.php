<?php

declare(strict_types=1);

namespace FastForward\Changelog\Git;

use InvalidArgumentException;
use RuntimeException;

/** Optional acquisition of an exact commit independently corroborated by GitHub PR evidence. */
interface GitCommitAcquirerInterface
{
    /**
     * Acquires only the selected object history, without updating branches, tags or FETCH_HEAD.
     *
     * @param  string                   $directory  Consumer Git root receiving immutable objects.
     * @param  string                   $repository API-verified GitHub owner/name matching the local origin.
     * @param  string                   $sha        Complete API-verified original pull-request head.
     * @return void                     No value; callers must recheck the exact commit and PR evidence before using it.
     * @throws InvalidArgumentException When the repository or SHA is malformed.
     * @throws RuntimeException         When the origin is not the selected GitHub repository or acquisition fails.
     */
    public function acquireCommit(string $directory, string $repository, string $sha): void;
}
