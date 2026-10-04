<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 * @see       https://github.com/php-fast-forward/changelog
 */

namespace FastForward\Changelog\Validator;

use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;

/** Binds fresh release inputs to committed evidence before local or remote application. */
interface ReleaseInputEvidenceValidatorInterface
{
    /** Checks complete base blobs without mutation; saved recovery, maintenance and non-Git plans retain their own contracts. */
    public function validate(ReleasePlan $plan): void;

    /** Requires a selected Git-bound PHP template to match its regular base blob before execution. */
    public function validateTemplate(ReleaseOptions $options, ?string $baseSha): void;
}
