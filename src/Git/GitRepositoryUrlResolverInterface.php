<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see       https://github.com/php-fast-forward/changelog
 * @see       https://github.com/php-fast-forward/changelog/issues
 * @see       https://php-fast-forward.github.io/changelog/
 * @see       https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Git;

/**
 * Defines origin-URL discovery for changelog reference rendering.
 */
interface GitRepositoryUrlResolverInterface
{
    /**
     * Returns the origin URL or null when no usable URL is available.
     */
    public function resolve(?string $workingDirectory): ?string;
}
