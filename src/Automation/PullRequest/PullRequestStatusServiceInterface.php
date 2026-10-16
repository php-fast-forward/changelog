<?php

declare(strict_types=1);

namespace FastForward\Changelog\Automation\PullRequest;

use FastForward\Changelog\Release\ReleaseOptions;

/** Produces contribution feedback bound to live PR revisions without executing consumer code. */
interface PullRequestStatusServiceInterface
{
    /** Returns JSON-ready feedback for valid or invalid contributions; unavailable or raced evidence throws. */
    public function inspect(
        ReleaseOptions $options,
        int $number,
        string $head,
        string $base,
        string $baseBranch,
        string $managedBranch,
        string $actor,
        string $waiverLabel,
        string $maintenanceLabel,
        bool $publishStatus,
    ): array;
}
