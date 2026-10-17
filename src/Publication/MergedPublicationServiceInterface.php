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
    /** Keeps PR authority, complete domain validation and create-only writes inside one contextual boundary. */
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
