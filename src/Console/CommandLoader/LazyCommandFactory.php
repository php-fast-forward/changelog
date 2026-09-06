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
use Symfony\Component\Console\Command\LazyCommand;

/**
 * Constructs Symfony lazy commands backed by a PSR-11 container.
 *
 * This factory is the only Changelog command-loader component that MAY create
 * a LazyCommand. It MUST defer target service resolution to the wrapper's
 * execution closure.
 */
final readonly class LazyCommandFactory implements LazyCommandFactoryInterface
{
    /**
     * Creates a metadata-complete wrapper without resolving the command service.
     *
     * The wrapper MUST expose names and descriptions during application
     * discovery. It SHALL request the concrete command only when Symfony needs
     * the selected command's executable behavior or full definition.
     *
     * @param string $name canonical command name exposed by Symfony Console
     * @param list<string> $aliases supported alternative command names
     * @param string $description short description available without service resolution
     * @param class-string<Command> $serviceId PSR-11 identifier of the concrete command
     * @param ContainerInterface $container container used only by the deferred closure
     *
     * @return Command lazy wrapper around the container-backed command
     */
    public function create(
        string $name,
        array $aliases,
        string $description,
        string $serviceId,
        ContainerInterface $container,
    ): Command {
        return new LazyCommand(
            $name,
            $aliases,
            $description,
            false,
            static function () use ($container, $serviceId): Command {
                /** @var Command */
                return $container->get($serviceId);
            },
        );
    }
}
