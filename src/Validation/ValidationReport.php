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

use FastForward\Changelog\Changeset\Changeset;

/**
 * Holds all valid fragments and all independently keyed diagnostics.
 */
final readonly class ValidationReport
{
    /**
     * Stores validation results without discarding successful siblings.
     *
     * @param list<Changeset>             $changesets every fragment that parsed successfully
     * @param array<string, list<string>> $errors     diagnostics keyed by fragment ID or context
     * @param array<string,string>        $hashes     hashes of the exact bytes accepted by the parser
     */
    public function __construct(
        public array $changesets,
        public array $errors,
        public bool $waived,
        public array $hashes = [],
    ) {}

    /**
     * Determines whether the complete directory satisfies the selected policy.
     */
    public function isValid(): bool
    {
        return [] === $this->errors;
    }
}
