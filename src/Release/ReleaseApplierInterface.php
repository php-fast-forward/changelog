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

/** Applies an approved immutable transaction or checks it without changing managed files. */
interface ReleaseApplierInterface
{
    /** Applies under the shared fragment lock; returns false for an already applied/no-change plan. */
    public function apply(ReleasePlan $plan): bool;

    /** Reads exact evidence without constructing a lock or performing any file/Git mutation. */
    public function isApplied(ReleasePlan $plan): bool;
}
