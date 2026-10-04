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

namespace FastForward\Changelog\Release;

/** Produces the single release or maintenance transaction used by CLI and automation. */
interface ReleasePlannerInterface
{
    /** Plans exact bytes without writing files, consuming fragments or changing Git/GitHub state. */
    public function plan(ReleaseOptions $options, string $operation = 'version'): ReleasePlan;
}
