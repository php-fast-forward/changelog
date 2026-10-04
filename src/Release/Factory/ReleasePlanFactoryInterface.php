<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 * @see       https://github.com/php-fast-forward/changelog
 */

namespace FastForward\Changelog\Release\Factory;

use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;
use FastForward\Changelog\Release\ReleaseReceipt;

/** Constructs the immutable release transaction and its ephemeral recovery journal. */
interface ReleasePlanFactoryInterface
{
    /**
     * Creates a deterministic plan from already validated domain inputs.
     * @param array<string,string> $consumed           absolute fragment paths and SHA-256 hashes
     * @param list<string>         $historicalVersions imported section identities
     */
    public function create(
        ReleaseOptions $options,
        ?string $baseSha,
        string $currentVersion,
        ?string $nextVersion,
        ?string $impact,
        array $consumed,
        array $historicalVersions,
        string $changelogPath,
        ?string $originalChangelog,
        string $changelogContents,
        string $notes,
        string $receiptPath,
        ?string $originalReceipt,
        bool $resuming = false,
    ): ReleasePlan;
    /** Restores a validated receipt without generating new identity or treating remaining fragments as a new release. */
    public function resume(
        ReleaseOptions $options,
        ReleaseReceipt $receipt,
        string $changelogPath,
        ?string $currentContents,
        string $receiptPath,
        string $rawReceipt,
    ): ReleasePlan;
}
