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
     * @param array<string,string> $consumed           absolute fragment paths mapped to SHA-256 content hashes
     * @param list<string>         $historicalVersions only previously absent sections imported by this plan
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

    /** Identifies a new release, historical maintenance, or a plan that has no changes. */
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

    /** Reports only paths the transaction will write or consume. */
    public function affectedFiles(): array
    {
        if ('none' === $this->mode()) {
            return [];
        }
        return [$this->changelogPath, $this->receiptPath, ...array_keys($this->consumed)];
    }

    /** Exposes stable machine output without adding progress messages or mutable PR text. */
    public function summary(): array
    {
        return [
            'id' => $this->id, 'mode' => $this->mode(), 'base_sha' => $this->baseSha,
            'current_version' => $this->currentVersion, 'next_version' => $this->nextVersion,
            'impact' => $this->impact, 'consumed' => $this->consumed,
            'historical_versions' => $this->historicalVersions, 'notes' => $this->notes,
            'affected_files' => $this->affectedFiles(), 'resuming' => $this->resuming,
        ];
    }
}
