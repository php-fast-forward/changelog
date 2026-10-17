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

namespace FastForward\Changelog\Automation\Policy;

use FastForward\Changelog\Release\ReleaseOptions;

/** Resolves GitHub-backed authority; a label, title, body or local skip flag cannot grant an exception. */
interface PullRequestPolicyInterface
{
    /**
     * Inspects a live PR snapshot and returns fail-closed proof bound to its immutable head SHA.
     *
     * @param  ReleaseOptions           $options          Trusted consumer paths, rendering settings and repository selection for this
     *                                                    operation.
     * @param  int                      $prNumber         Positive pull-request number whose live authority and head are inspected.
     * @param  string                   $managedBranch    Exclusive automation branch reserved for the generated version PR.
     * @param  string                   $automationActor  Canonical GitHub Bot login required to own and sign generated commits.
     * @param  string                   $waiverLabel      Exact label whose latest privileged human grant may waive contribution presence.
     * @param  string                   $maintenanceLabel Exact label whose latest privileged human grant may authorize history
     *                                                    maintenance.
     * @return PullRequestAuthorization Verified authorization and controlled diagnostics bound to the observed
     *                                  immutable head.
     */
    public function inspect(
        ReleaseOptions $options,
        int $prNumber,
        string $managedBranch = 'changelog/version',
        string $automationActor = 'github-actions[bot]',
        string $waiverLabel = 'changelog-not-required',
        string $maintenanceLabel = 'changelog-maintenance',
    ): PullRequestAuthorization;
    /**
     * Verifies signed Bot identity, receipt bytes, ancestry and exclusively managed changes at one immutable head.
     *
     * @param  ReleaseOptions $options         Trusted consumer paths, rendering settings and repository selection for this
     *                                         operation.
     * @param  string         $headSha         Immutable generated PR head whose ownership and committed output must be proved.
     * @param  string         $automationActor Canonical GitHub Bot login required to own and sign generated commits.
     * @param  ?string        $baseSha         Approved Git base identity, or null for an explicitly non-Git operation.
     * @return bool           Whether immutable account, signature, ancestry, settings and complete generated scope are all
     *                        proved.
     */
    public function inspectHead(
        ReleaseOptions $options,
        string $headSha,
        string $automationActor = 'github-actions[bot]',
        ?string $baseSha = null,
    ): bool;
}
