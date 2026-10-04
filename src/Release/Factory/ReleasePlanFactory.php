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

namespace FastForward\Changelog\Release\Factory;

use FastForward\Changelog\Release\ReceiptCodecInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;
use FastForward\Changelog\Release\ReleaseReceipt;

/** Produces deterministic transaction evidence without adding tracked technical files. */
final readonly class ReleasePlanFactory implements ReleasePlanFactoryInterface
{
    /** Shares canonical receipt validation and identity generation with receipt reads. */
    public function __construct(private ReceiptCodecInterface $codec) {}

    /**
     * Builds a new immutable transaction from validated exact inputs and output.
     * @param array<string,string> $consumed           absolute fragment paths and SHA-256 hashes
     * @param list<string>         $historicalVersions previously absent imported sections
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
    ): ReleasePlan {
        ksort($consumed, SORT_STRING);
        $relativeConsumed = [];
        foreach ($consumed as $path => $hash) {
            $relativeConsumed[substr($path, strlen(rtrim($options->workingDirectory, '/\\')) + 1)] = $hash;
        }
        $evidence = [
            'schema' => 1, 'base_sha' => $baseSha, 'current_version' => $currentVersion,
            'next_version' => $nextVersion, 'impact' => $impact,
            'consumed' => $relativeConsumed, 'historical_versions' => $historicalVersions,
            'changelog_file' => $options->changelogFile, 'fragment_directory' => $options->fragmentDirectory,
            'locale' => $options->locale, 'template' => $options->template,
            'tag_prefix' => $options->tagPrefix, 'repository' => $options->repository,
            'before_changelog_sha256' => null === $originalChangelog ? null : hash('sha256', $originalChangelog),
            'changelog_contents' => $changelogContents, 'after_changelog_sha256' => hash('sha256', $changelogContents),
            'notes' => $notes, 'notes_sha256' => hash('sha256', $notes),
        ];
        $receiptContents = $this->codec->encode($evidence);
        $receipt = $this->codec->decode($receiptContents);
        return new ReleasePlan(
            $options,
            $receipt->data['id'],
            $baseSha,
            $currentVersion,
            $nextVersion,
            $impact,
            $consumed,
            $historicalVersions,
            $changelogPath,
            $originalChangelog,
            $changelogContents,
            $notes,
            $receiptPath,
            $originalReceipt,
            $receiptContents,
            $resuming,
        );
    }

    /**
     * Restores the entire approved consumed set, including already absent fragments.
     * The caller validates current central bytes and its extracted notes against the receipt.
     */
    public function resume(
        ReleaseOptions $options,
        ReleaseReceipt $receipt,
        string $changelogPath,
        ?string $currentContents,
        string $receiptPath,
        string $rawReceipt,
    ): ReleasePlan {
        $data = $receipt->data;
        $consumed = [];
        foreach ($data['consumed'] as $relative => $hash) {
            $consumed[rtrim($options->workingDirectory, '/\\') . '/' . $relative] = $hash;
        }
        return new ReleasePlan(
            $options,
            $data['id'],
            $data['base_sha'],
            $data['current_version'],
            $data['next_version'],
            $data['impact'],
            $consumed,
            $data['historical_versions'],
            $changelogPath,
            $currentContents,
            $data['changelog_contents'],
            $data['notes'],
            $receiptPath,
            $rawReceipt,
            $rawReceipt,
            true,
        );
    }
}
