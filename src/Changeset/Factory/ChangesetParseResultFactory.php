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

namespace FastForward\Changelog\Changeset\Factory;

use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\ChangesetParseResult;

/**
 * Provides the construction boundary for parser results.
 */
final readonly class ChangesetParseResultFactory implements ChangesetParseResultFactoryInterface
{
    /**
     * Creates a successful result that retains the canonical filename.
     */
    public function valid(Changeset $changeset): ChangesetParseResult
    {
        return new ChangesetParseResult($changeset->id, $changeset, []);
    }

    /**
     * Creates a failed result containing every accumulated diagnostic.
     *
     * @param list<string> $errors fragment-specific validation messages
     */
    public function invalid(string $id, array $errors): ChangesetParseResult
    {
        return new ChangesetParseResult($id, null, $errors);
    }
}
