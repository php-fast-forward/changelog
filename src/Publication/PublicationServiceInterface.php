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
use InvalidArgumentException;
use RuntimeException;

/** Publishes only proved explicit commit evidence, with a read-only dry-run contract. */
interface PublicationServiceInterface
{
    /**
     * Reconciles a tag and exact release notes without ever moving an existing conflicting tag.
     *
     * @param  ReleaseOptions           $options     Trusted consumer paths, rendering settings and repository selection for this
     *                                               operation.
     * @param  string                   $approvedSha Complete approved commit identity whose exact committed transaction is required.
     * @param  bool                     $dryRun      Whether to return the proposed transaction without creating or updating remote content.
     * @return PublicationResult        Verified maintenance, dry-run, published or unchanged result for the approved commit.
     * @throws InvalidArgumentException When complete approved transaction or contextual merge evidence is absent or
     *                                  inconsistent.
     * @throws RuntimeException         When immutable API authority or matching create-only publication state cannot be
     *                                  confirmed.
     */
    public function publish(
        ReleaseOptions $options,
        string $approvedSha,
        bool $dryRun = false,
    ): PublicationResult;
}
