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

namespace FastForward\Changelog\Validator;

use FastForward\Changelog\Publication\PublicationEvidence;
use FastForward\Changelog\Release\ReleaseOptions;
use InvalidArgumentException;
use RuntimeException;

/** Proves release evidence before any remote publication mutation can be requested. */
interface PublicationEvidenceValidatorInterface
{
    /**
     * Requires a complete explicit approved commit SHA and derives notes from that commit's central document.
     *
     * @param  ReleaseOptions           $options     Trusted consumer paths, rendering settings and repository selection for this
     *                                               operation.
     * @param  string                   $approvedSha Complete approved commit identity whose exact committed transaction is required.
     * @return PublicationEvidence      Reconstructed approved-commit publication evidence, with no version for supported
     *                                  maintenance.
     * @throws InvalidArgumentException When committed history, scope, settings, ancestry or transaction evidence
     *                                  violates the publication contract.
     * @throws RuntimeException         When an injected Git, managed-file, template or API boundary cannot supply the required
     *                                  evidence.
     */
    public function validate(ReleaseOptions $options, string $approvedSha): PublicationEvidence;
}
