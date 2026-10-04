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

/** Records a create-only operation; conflict may include a commit already created by the Contents API. */
final readonly class DependabotFragmentResult
{
    /** Status is created, unchanged, filtered, refused or conflict; diagnostics contain no raw server details. */
    public function __construct(public string $status, public string $path, public ?string $commitSha, public array $diagnostics) {}
}
