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

namespace FastForward\Changelog\Validation;

use FastForward\Changelog\Release\ReleaseOptions;

/** Defines local schema checking and trusted-context pull-request contribution checking. */
interface CheckServiceInterface
{
    /**
     * Validates every pending fragment, then the changes selected by a PR baseline.
     *
     * Existing fragments cannot satisfy the addition gate. Authorization flags and
     * kind MUST come from verified automation context, not user-supplied skip flags
     * or an editable label/body. Receipt changes and fragment consumption require
     * both central authorization and the exact managed-version kind. Maintenance
     * and waivers never authorize those changes or hide malformed fragments.
     * Local checking without since allows an empty store.
     */
    public function check(
        ReleaseOptions $options,
        ?string $since = null,
        bool $centralChangeAuthorized = false,
        bool $waiverAuthorized = false,
        string $authorizationKind = 'ordinary',
    ): ValidationReport;
}
