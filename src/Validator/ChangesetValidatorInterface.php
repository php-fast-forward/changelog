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
use RuntimeException;

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
     *
     * @param  string           $directory       Explicit consumer directory used for repository or fragment operations.
     * @param  bool             $waived          Whether the caller supplies an authorized fragment-presence waiver; malformed fragments still
     *                                           fail.
     * @param  bool             $requireFragment Whether an empty explicit fragment selection must produce a presence error.
     * @return ValidationReport Aggregate valid fragment values, exact content hashes and all discovered diagnostics.
     * @throws RuntimeException When the fragment discovery or file-reading boundary fails rather than returning a
     *                          validation result.
     */
    public function validate(
        string $directory,
        bool $waived = false,
        bool $requireFragment = true,
    ): ValidationReport;

    /**
     * Validates only explicitly selected paths, as required by a PR diff.
     *
     * @param  string           $directory       Configured discovery root; explicit candidates must be direct children of this
     *                                           directory.
     * @param  list<string>     $paths           Exact fragment candidate paths selected by the caller, without adding inherited files.
     * @param  bool             $waived          Whether the caller supplies an authorized fragment-presence waiver; malformed fragments still
     *                                           fail.
     * @param  bool             $requireFragment Whether an empty explicit fragment selection must produce a presence error.
     * @return ValidationReport Validation of only the supplied candidates, preserving valid siblings and every failure.
     * @throws RuntimeException When the fragment discovery or file-reading boundary fails rather than returning a
     *                          validation result.
     */
    public function validatePaths(
        string $directory,
        array $paths,
        bool $waived = false,
        bool $requireFragment = true,
    ): ValidationReport;
}
