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

namespace FastForward\Changelog\Changeset;

/**
 * Reports parsing success or every diagnostic associated with one fragment.
 *
 * Parsing SHALL return a result instead of failing fast so validation can keep
 * the identity and diagnostics of every fragment in a pull request.
 */
final readonly class ChangesetParseResult
{
    /**
     * Stores a parsed value or its independent diagnostics.
     *
     * @param string         $id        canonical filename derived from the supplied path
     * @param Changeset|null $changeset parsed fragment when validation succeeds
     * @param list<string>   $errors    fragment-specific validation messages
     */
    public function __construct(
        public string $id,
        public ?Changeset $changeset,
        public array $errors,
    ) {}

    /**
     * Determines whether parsing produced a usable changeset.
     */
    public function isValid(): bool
    {
        return $this->changeset instanceof Changeset && [] === $this->errors;
    }
}
