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

namespace FastForward\Changelog\Automation\Dependabot;

use FastForward\Changelog\Release\ReleaseOptions;

/** Creates one deterministic core changeset for a verified same-repository Dependabot PR. */
interface DependabotFragmentServiceInterface
{
    /** Performs only API reads and create-only Contents writes; unexpected existing content is never overwritten. */
    public function synchronize(ReleaseOptions $options, DependabotInput $input): DependabotFragmentResult;
}
