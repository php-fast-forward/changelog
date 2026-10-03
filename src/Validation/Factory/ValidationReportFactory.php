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

use FastForward\Changelog\Validation\ValidationReport;

/**
 * Provides the construction boundary for validation reports.
 */
final readonly class ValidationReportFactory implements ValidationReportFactoryInterface
{
    /**
     * Creates an immutable aggregate after non-fail-fast validation.
     */
    public function create(array $changesets, array $errors, bool $waived, array $hashes = []): ValidationReport
    {
        return new ValidationReport($changesets, $errors, $waived, $hashes);
    }
}
