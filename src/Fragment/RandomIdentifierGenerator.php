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

namespace FastForward\Changelog\Fragment;

use Random\Randomizer;

/** Generates stable individual identities using an injected random engine. */
final readonly class RandomIdentifierGenerator implements IdentifierGeneratorInterface
{
    /** Captures the randomizer without requesting entropy during composition. */
    public function __construct(private Randomizer $randomizer) {}

    /** Returns a canonical filename carrying 128 bits of independent entropy. */
    public function generate(): string
    {
        return 'change-' . bin2hex($this->randomizer->getBytes(16)) . '.md';
    }
}
