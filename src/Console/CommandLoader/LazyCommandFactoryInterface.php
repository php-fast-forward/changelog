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

namespace FastForward\Changelog\Console\CommandLoader;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;

/**
 * Creates lazy command wrappers from explicit metadata.
 *
 * Implementations MUST preserve Symfony command discovery without resolving
 * the underlying command service.
 */
interface LazyCommandFactoryInterface
{
    /**
     * Creates a wrapper that MUST defer container resolution until Symfony
     * requests the selected command's definition or execution.
     *
     * @param string $name canonical command name exposed by Symfony Console
     * @param list<string> $aliases supported alternative command names
     * @param string $description short description available during discovery
     * @param class-string<Command> $serviceId PSR-11 identifier of the concrete command
     * @param ContainerInterface $container container used for deferred resolution
     *
     * @return Command metadata-complete wrapper that SHALL load the service lazily
     */
    public function create(
        string $name,
        array $aliases,
        string $description,
        string $serviceId,
        ContainerInterface $container,
    ): Command;
}
