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
 * @see https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Automation\VersionPullRequest;

use FastForward\Changelog\Release\ReleaseOptions;

/** Maintains one trusted version PR by planning exclusively from the freshly checked-out base. */
interface VersionPullRequestServiceInterface
{
    /** Never checks out a managed head, force-pushes a human branch or publishes tags/releases. */
    public function synchronize(ReleaseOptions $options, VersionPullRequestInput $input): VersionPullRequestResult;
}
