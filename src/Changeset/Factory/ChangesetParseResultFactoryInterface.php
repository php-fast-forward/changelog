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
 * Creates success and failure results for one parsed fragment.
 */
interface ChangesetParseResultFactoryInterface
{
    /**
     * Creates a successful parse result.
     */
    public function valid(Changeset $changeset): ChangesetParseResult;

    /**
     * Creates a failed parse result without discarding fragment identity.
     *
     * @param list<string> $errors all diagnostics found for the fragment
     */
    public function invalid(string $id, array $errors): ChangesetParseResult;
}
