<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see       https://github.com/php-fast-forward/changelog
 * @see       https://github.com/php-fast-forward/changelog/issues
 * @see       https://php-fast-forward.github.io/changelog/
 * @see       https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Document;

/**
 * Constructs documents with exactly one leading Unreleased section.
 */
final readonly class ChangelogDocumentFactory implements ChangelogDocumentFactoryInterface
{
    /**
     * Initializes the factory used to create a missing Unreleased release.
     *
     * @param ChangelogReleaseFactoryInterface $releaseFactory creates a missing Unreleased section
     */
    public function __construct(
        private ChangelogReleaseFactoryInterface $releaseFactory,
    ) {}

    /**
     * Creates a document and normalizes its Unreleased section.
     *
     * @param list<ChangelogRelease> $releases
     * @param list<string> $references Markdown reference definitions retained from input
     */
    public function create(array $releases = [], array $references = []): ChangelogDocument
    {
        $unreleased = null;
        $published = [];

        foreach ($releases as $release) {
            if ($release->isUnreleased()) {
                $unreleased ??= $release;

                continue;
            }

            $published[] = $release;
        }

        $unreleased ??= $this->releaseFactory->create(ChangelogDocument::UNRELEASED_VERSION);

        return new ChangelogDocument([$unreleased, ...$published], $references);
    }
}
