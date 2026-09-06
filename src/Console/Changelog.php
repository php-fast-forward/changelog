<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @author    Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see      https://github.com/php-fast-forward/changelog
 * @see      https://github.com/php-fast-forward/changelog/issues
 * @see      https://php-fast-forward.github.io/changelog/
 * @see      https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Console;

use FastForward\Changelog\Version\PackageVersionResolverInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

/**
 * Provides the standalone Symfony Console application for changelog commands.
 *
 * Command construction MUST remain delegated to the injected loader so an
 * invalid command dependency cannot prevent unrelated commands from loading.
 */
final class Changelog extends Application
{
    /**
     * Initializes application metadata and installs the lazy command loader.
     *
     * @param CommandLoaderInterface $commandLoader exposes command metadata lazily
     * @param PackageVersionResolverInterface $versionResolver resolves package metadata
     */
    public function __construct(
        CommandLoaderInterface $commandLoader,
        PackageVersionResolverInterface $versionResolver,
    ) {
        parent::__construct('Fast Forward Changelog', $versionResolver->resolve());

        $this->setCommandLoader($commandLoader);
    }
}
