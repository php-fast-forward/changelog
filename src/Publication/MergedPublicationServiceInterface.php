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

namespace FastForward\Changelog\Publication;

use FastForward\Changelog\Release\ReleaseOptions;

/** Publishes a merge only after binding it to the exact signed head of its merged managed PR. */
interface MergedPublicationServiceInterface extends PublicationServiceInterface
{
    /**
     * Keeps PR authority, complete domain validation and create-only writes inside one contextual boundary.
     *
     * @param  ReleaseOptions    $options         Trusted consumer paths, rendering settings and repository selection for this
     *                                            operation.
     * @param  int               $pullRequest     Positive pull-request number associated with the approved automation snapshot.
     * @param  string            $mergeSha        Exact approved merge commit that will be validated and, if applicable, tagged.
     * @param  string            $headSha         Immutable generated PR head whose ownership and committed output must be proved.
     * @param  string            $baseBranch      Trusted release-line branch that supplies the fresh source base.
     * @param  string            $managedBranch   Exclusive automation branch reserved for the generated version PR.
     * @param  string            $automationActor Canonical GitHub Bot login required to own and sign generated commits.
     * @param  bool              $dryRun          Whether to return the proposed transaction without creating or updating remote content.
     * @return PublicationResult Verified publication outcome for the approved managed PR and its exact merge.
     */
    public function publishMerged(
        ReleaseOptions $options,
        int $pullRequest,
        string $mergeSha,
        string $headSha,
        string $baseBranch = 'main',
        string $managedBranch = 'changelog/version',
        string $automationActor = 'github-actions[bot]',
        bool $dryRun = false,
    ): PublicationResult;
}
