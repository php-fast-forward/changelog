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

namespace FastForward\Changelog\Fragment\Factory;

use FastForward\Changelog\Fragment\IdentifierGeneratorInterface;
use FastForward\Changelog\Fragment\RandomIdentifierGenerator;
use Random\Engine;
use Random\Engine\Secure;
use Random\Randomizer;

/** Concentrates generator, randomizer and default-engine construction. */
final readonly class IdentifierGeneratorFactory implements IdentifierGeneratorFactoryInterface
{
    /** Builds the secure default without generating bytes until generate() is called. */
    public function create(?Engine $engine = null): IdentifierGeneratorInterface
    {
        return new RandomIdentifierGenerator(new Randomizer($engine ?? new Secure()));
    }
}
