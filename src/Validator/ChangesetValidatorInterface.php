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

namespace FastForward\Changelog\Validator;

use FastForward\Changelog\Validation\ValidationReport;

/**
 * Validates every pending fragment without failing at the first error.
 */
interface ChangesetValidatorInterface
{
    /**
     * Validates one directory, optionally requiring a fragment or trusted waiver.
     *
     * Set requireFragment=false for inventory validation; every discovered
     * fragment still undergoes the complete schema and path validation.
     */
    public function validate(string $directory, bool $waived = false, bool $requireFragment = true): ValidationReport;

    /**
     * Validates only explicitly selected paths, as required by a PR diff.
     *
     * @param list<string> $paths exact added Markdown candidates
     */
    public function validatePaths(
        string $directory,
        array $paths,
        bool $waived = false,
        bool $requireFragment = true,
    ): ValidationReport;
}
