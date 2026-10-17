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
     * or an editable label/body. Fragment consumption requires both central
     * authorization and the exact managed-version kind. The legacy tracked receipt
     * path remains protected for compatibility; new transactions never create it.
     * Maintenance and waivers never hide malformed fragments or authorize consumption.
     * Local checking without since allows an empty store.
     *
     * @param ReleaseOptions $options                 Validated consumer paths, presentation settings and source-selection policy.
     * @param string|null    $since                   Optional committed Git baseline used to inspect this contribution's exact delta.
     * @param bool           $centralChangeAuthorized Whether independently verified policy permits central-history edits for this exact checkout.
     * @param bool           $waiverAuthorized        Whether verified maintainer policy permits no new fragment without hiding other validation failures.
     * @param string         $authorizationKind       Verified policy kind; managed-version alone permits consumption and maintenance permits only its narrow attribution correction.
     *
     * @return ValidationReport Complete pending inventory and independent diagnostics; inventory size is not a count of this PR's newly contributed fragments.
     */
    public function check(
        ReleaseOptions $options,
        ?string $since = null,
        bool $centralChangeAuthorized = false,
        bool $waiverAuthorized = false,
        string $authorizationKind = 'ordinary',
    ): ValidationReport;
}
