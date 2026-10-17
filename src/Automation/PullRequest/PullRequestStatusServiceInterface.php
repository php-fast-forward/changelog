<?php

declare(strict_types=1);

namespace FastForward\Changelog\Automation\PullRequest;

use FastForward\Changelog\Release\ReleaseOptions;
use RuntimeException;

/** Produces contribution feedback bound to live PR revisions without executing consumer code. */
interface PullRequestStatusServiceInterface
{
    /**
     * Returns JSON-ready feedback for valid or invalid contributions; unavailable or raced evidence throws.
     *
     * @param  ReleaseOptions   $options          Trusted consumer paths, rendering settings and repository selection for this
     *                                            operation.
     * @param  int              $number           Positive pull-request number bound to the supplied immutable head and base.
     * @param  string           $head             Immutable managed or contribution head selected for the current operation.
     * @param  string           $base             Immutable source-base commit used for ancestry, inventory and freshness comparisons.
     * @param  string           $baseBranch       Trusted release-line branch that supplies the fresh source base.
     * @param  string           $managedBranch    Exclusive automation branch reserved for the generated version PR.
     * @param  string           $actor            Canonical GitHub account login whose current identity must authorize the operation.
     * @param  string           $waiverLabel      Exact label whose latest privileged human grant may waive contribution presence.
     * @param  string           $maintenanceLabel Exact label whose latest privileged human grant may authorize history
     *                                            maintenance.
     * @param  bool             $publishStatus    Whether to write the fixed commit status after verifying the exact PR snapshot.
     * @return array            Exact-head contribution status, bounded inert comment and complete controlled validation
     *                          diagnostics.
     * @throws RuntimeException When immutable PR, authority, committed-blob or status evidence cannot be confirmed.
     */
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
