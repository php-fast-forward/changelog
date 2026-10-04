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

namespace FastForward\Changelog\Publication\Factory;

use FastForward\Changelog\Publication\PublicationResult;

/** Constructs typed publication results without mixing HTTP or Git into value construction. */
interface PublicationResultFactoryInterface
{
    /** Retains observed state, exact target identity and planned/performed actions. */
    public function create(string $state, ?string $version, ?string $tag, string $sha, ?string $url, array $actions): PublicationResult;
}
