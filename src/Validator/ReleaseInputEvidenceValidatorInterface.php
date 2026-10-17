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
use RuntimeException;

/** Binds fresh release inputs to committed evidence before local or remote application. */
interface ReleaseInputEvidenceValidatorInterface
{
    /**
     * Checks complete base blobs without mutation; saved recovery, maintenance and non-Git plans retain their own contracts.
     *
     * @param  ReleasePlan      $plan Immutable release transaction whose input and output evidence must remain consistent.
     * @return void             No value; applicable managed inputs must match the approved base before release mutation.
     * @throws RuntimeException When managed input bytes, regular-file scope or executable-template evidence cannot
     *                          match the approved base.
     */
    public function validate(ReleasePlan $plan): void;

    /**
     * Requires a selected Git-bound PHP template to match its regular base blob before execution.
     *
     * @param  ReleaseOptions   $options Trusted consumer paths, rendering settings and repository selection for this
     *                                   operation.
     * @param  ?string          $baseSha Approved Git base identity, or null for an explicitly non-Git operation.
     * @return void             No value; applicable executable selection must match a regular source-base blob.
     * @throws RuntimeException When managed input bytes, regular-file scope or executable-template evidence cannot
     *                          match the approved base.
     */
    public function validateTemplate(ReleaseOptions $options, ?string $baseSha): void;
}
