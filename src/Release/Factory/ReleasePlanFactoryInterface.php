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
     *
     * @param ReleaseOptions       $options            Validated consumer paths, presentation settings and source-selection policy.
     * @param string|null          $baseSha            Exact source commit identity, or null for a consumer without Git.
     * @param string               $currentVersion     Validated current stable version used as the numeric release baseline.
     * @param string|null          $nextVersion        Next numeric semantic version, or null when no new release is planned.
     * @param string|null          $impact             Greatest effective fragment impact, or null for a maintenance-only plan.
     * @param array<string,string> $consumed           absolute fragment paths and SHA-256 hashes
     * @param list<string>         $historicalVersions imported section identities
     * @param string               $changelogPath      Absolute managed central-history path selected for this transaction.
     * @param string|null          $originalChangelog  Exact central-history bytes accepted before application, or null when originally absent.
     * @param string               $changelogContents  Exact resulting central-history bytes approved by this plan.
     * @param string               $notes              Exact rendered body of the selected new release, without its outer heading.
     * @param string               $receiptPath        Absolute private recovery-journal path outside the versioned tree.
     * @param string|null          $originalReceipt    Exact journal bytes accepted before this transaction, or null when absent.
     * @param bool                 $resuming           Whether this plan restores a saved transaction instead of calculating a fresh one.
     *
     * @return ReleasePlan Deterministic transaction binding supplied settings, accepted byte hashes and exact resulting history.
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
    /**
     * Restores a validated receipt without generating new identity or treating remaining fragments as a new release.
     *
     * @param ReleaseOptions $options         Validated consumer paths, presentation settings and source-selection policy.
     * @param ReleaseReceipt $receipt         Schema-validated recovery evidence for the interrupted transaction.
     * @param string         $changelogPath   Absolute managed central-history path selected for this transaction.
     * @param string|null    $currentContents Observed central-history bytes during recovery, or null when the file is absent.
     * @param string         $receiptPath     Absolute private recovery-journal path outside the versioned tree.
     * @param string         $rawReceipt      Exact serialized recovery journal from which the interrupted plan is restored.
     *
     * @return ReleasePlan Restored immutable transaction retaining its saved identity, version, date and exact notes.
     */
    public function resume(
        ReleaseOptions $options,
        ReleaseReceipt $receipt,
        string $changelogPath,
        ?string $currentContents,
        string $receiptPath,
        string $rawReceipt,
    ): ReleasePlan;
}
