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

namespace FastForward\Changelog\Release\Factory;

use FastForward\Changelog\Release\ReleaseOptions;

/** Defines the settings boundary for independently configured entrypoints. */
interface ReleaseOptionsFactoryInterface
{
    /**
     * Resolves defaults followed by explicit values and rejects unknown settings.
     *
     * @param  array<string, mixed>      $values settings supplied by flags or Action inputs
     * @throws \InvalidArgumentException when values cannot be used safely and deterministically
     */
    public function create(array $values = []): ReleaseOptions;
}
