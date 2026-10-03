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

namespace FastForward\Changelog\Version;

/**
 * Defines package-version lookup independently from Composer static state.
 *
 * Implementations MUST expose stable metadata for one application instance and
 * MUST NOT require command services to be constructed.
 */
interface PackageVersionResolverInterface
{
    /**
     * Returns the installed package version or a development fallback.
     *
     * The resolver SHALL always return a non-empty display value suitable for
     * Symfony Console application metadata.
     *
     * @return string package version displayed by the CLI
     */
    public function resolve(): string;
}
