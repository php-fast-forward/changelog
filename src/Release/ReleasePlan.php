<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see       https://github.com/php-fast-forward/changelog
 * @see       https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Release;

/** Captures the exact input evidence and output shared by status, version and automation. */
final readonly class ReleasePlan
{
    /**
     * Retains immutable bytes and hashes so applying a plan cannot silently adopt newer input.
     *
     * @param ReleaseOptions       $options            Validated consumer paths, presentation settings and source-selection policy.
     * @param string               $id                 Deterministic SHA-256 identity binding the complete immutable transaction.
     * @param string|null          $baseSha            Exact source commit identity, or null for a consumer without Git.
     * @param string               $currentVersion     Validated current stable version used as the numeric release baseline.
     * @param string|null          $nextVersion        Next numeric semantic version, or null when no new release is planned.
     * @param string|null          $impact             Greatest effective fragment impact, or null for a maintenance-only plan.
     * @param array<string,string> $consumed           absolute fragment paths mapped to SHA-256 content hashes
     * @param list<string>         $historicalVersions only previously absent sections imported by this plan
     * @param string               $changelogPath      Absolute managed central-history path selected for this transaction.
     * @param string|null          $originalChangelog  Exact central-history bytes accepted before application, or null when originally absent.
     * @param string               $changelogContents  Exact resulting central-history bytes approved by this plan.
     * @param string               $notes              Exact rendered body of the selected new release, without its outer heading.
     * @param string               $receiptPath        Absolute private recovery-journal path outside the versioned tree.
     * @param string|null          $originalReceipt    Exact journal bytes accepted before this transaction, or null when absent.
     * @param string               $receiptContents    Canonical serialized journal bytes for the planned transaction.
     * @param bool                 $resuming           Whether this plan restores a saved transaction instead of calculating a fresh one.
     */
    public function __construct(
        public ReleaseOptions $options,
        public string $id,
        public ?string $baseSha,
        public string $currentVersion,
        public ?string $nextVersion,
        public ?string $impact,
        public array $consumed,
        public array $historicalVersions,
        public string $changelogPath,
        public ?string $originalChangelog,
        public string $changelogContents,
        public string $notes,
        public string $receiptPath,
        public ?string $originalReceipt,
        public string $receiptContents,
        public bool $resuming = false,
    ) {}

    /**
     * Identifies a new release, historical maintenance, or a plan that has no changes.
     *
     * @return string release, maintenance or none, according to the transaction's exact output and recovery state.
     */
    public function mode(): string
    {
        if (null !== $this->nextVersion) {
            return 'release';
        }
        if ($this->resuming) {
            return 'maintenance';
        }

        return (null === $this->originalChangelog && '' === $this->changelogContents)
            || $this->originalChangelog === $this->changelogContents ? 'none' : 'maintenance';
    }

    /**
     * Reports only paths the transaction will write or consume.
     *
     * @return list<string> Only managed central-history and consumed-fragment paths affected by this plan.
     */
    public function affectedFiles(): array
    {
        if ('none' === $this->mode()) {
            return [];
        }

        return [$this->changelogPath, ...array_keys($this->consumed)];
    }

    /**
     * Returns ready-to-use commit text for approved Git consolidation without a tracked receipt file.
     *
     * @param string $title Single-line consolidation commit title preceding the generated scalar evidence trailers.
     *
     * @return string|null Consolidation commit text with bound scalar trailers, or null when no Git source identity exists.
     */
    public function commitMessage(string $title = 'chore: update changelog'): ?string
    {
        return null === $this->baseSha ? null : $title . "\n\nChangelog-Plan: " . $this->id
            . "\nChangelog-Base: " . $this->baseSha . "\nChangelog-Output: " . hash('sha256', $this->changelogContents)
            . "\nChangelog-Options: " . $this->options->evidenceHash();
    }

    /**
     * Exposes stable machine output without adding progress messages or mutable PR text.
     *
     * @return array<string,mixed> Stable machine plan containing identity, versions, input hashes, notes, affected files and commit text.
     */
    public function summary(): array
    {
        return [
            'id' => $this->id, 'mode' => $this->mode(), 'base_sha' => $this->baseSha,
            'current_version' => $this->currentVersion, 'next_version' => $this->nextVersion,
            'impact' => $this->impact, 'consumed' => $this->consumed,
            'historical_versions' => $this->historicalVersions, 'notes' => $this->notes,
            'affected_files' => $this->affectedFiles(), 'resuming' => $this->resuming,
            'commit_message' => $this->commitMessage(),
        ];
    }
}
