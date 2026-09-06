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
 * Resolves package metadata captured by the composition root.
 *
 * The resolver MUST remain deterministic after construction and MUST NOT read
 * Composer's process-wide static state. Composition code MAY inject null when
 * the package is running from an unversioned development checkout.
 */
final readonly class ComposerPackageVersionResolver implements PackageVersionResolverInterface
{
    private const string DEVELOPMENT_VERSION = '0.1.x-dev';

    /**
     * @param string|null $installedVersion Composer's pretty version, when available
     */
    public function __construct(
        private ?string $installedVersion,
    ) {}

    /**
     * Returns the injected package version or the development fallback.
     *
     * The method MUST return the same value for the lifetime of this service.
     *
     * @return string installed pretty version or the stable development label
     */
    public function resolve(): string
    {
        return $this->installedVersion ?? self::DEVELOPMENT_VERSION;
    }
}
