<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license https://opensource.org/licenses/MIT MIT License
 * @see https://github.com/php-fast-forward/changelog
 */

namespace FastForward\Changelog\Validator;

use FastForward\Changelog\Publication\PublicationEvidence;
use FastForward\Changelog\Release\ReleaseOptions;

/** Additional read-only proof capability; merged-PR authority remains with the contextual publisher. */
interface MergedPublicationEvidenceValidatorInterface extends PublicationEvidenceValidatorInterface
{
    /** Recomputes the merge's complete domain using immutable head metadata and identical complete trees. */
    public function validateMerged(ReleaseOptions $options, string $mergeSha, string $headSha): PublicationEvidence;
}
