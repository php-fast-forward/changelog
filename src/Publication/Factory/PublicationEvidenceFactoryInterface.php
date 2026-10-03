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

namespace FastForward\Changelog\Publication\Factory;

use FastForward\Changelog\Publication\PublicationEvidence;

/** Constructs typed publication evidence after local Git and domain proof. */
interface PublicationEvidenceFactoryInterface
{
    /** Retains the validated target identity, central notes and repository selection. */
    public function create(string $sha, ?string $version, ?string $tag, string $notes, ?string $repository): PublicationEvidence;
}
