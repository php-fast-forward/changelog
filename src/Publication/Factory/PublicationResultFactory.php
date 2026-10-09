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

/** Constructs immutable publication output at the explicit factory boundary. */
final readonly class PublicationResultFactory implements PublicationResultFactoryInterface
{
    /** Retains approved identity and actions exactly without I/O. */
    public function create(
        string $state,
        ?string $version,
        ?string $tag,
        string $sha,
        ?string $url,
        array $actions,
    ): PublicationResult {
        return new PublicationResult($state, $version, $tag, $sha, $url, $actions);
    }
}
