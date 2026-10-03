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

namespace FastForward\Changelog\Publication;

/** Carries publication evidence derived entirely from the explicitly approved Git commit. */
final readonly class PublicationEvidence
{
    /** Stores proved commit identity and central note bytes without generating any remote state. */
    public function __construct(
        public string $sha,
        public ?string $version,
        public ?string $tag,
        public string $notes,
        public ?string $repository,
    ) {}
}
