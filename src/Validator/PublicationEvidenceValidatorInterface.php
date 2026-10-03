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

/** Proves release evidence before any remote publication mutation can be requested. */
interface PublicationEvidenceValidatorInterface
{
    /** Requires a complete explicit approved commit SHA and derives notes from that commit's central document. */
    public function validate(ReleaseOptions $options, string $approvedSha): PublicationEvidence;
}
