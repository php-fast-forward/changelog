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

use FastForward\Changelog\Release\ReleaseOptions;

/** Publishes only proved explicit commit evidence, with a read-only dry-run contract. */
interface PublicationServiceInterface
{
    /** Reconciles a tag and exact release notes without ever moving an existing conflicting tag. */
    public function publish(ReleaseOptions $options, string $approvedSha, bool $dryRun = false): PublicationResult;
}
