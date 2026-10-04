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

namespace FastForward\Changelog\Validation\Factory;

use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Validation\ValidationReport;

/**
 * Creates aggregate validation reports after every fragment is inspected.
 */
interface ValidationReportFactoryInterface
{
    /**
     * Creates a report that preserves both successful values and diagnostics.
     *
     * @param list<Changeset>             $changesets valid independently identified fragments
     * @param array<string, list<string>> $errors     diagnostics keyed by identity
     */
    public function create(array $changesets, array $errors, bool $waived, array $hashes = []): ValidationReport;
}
