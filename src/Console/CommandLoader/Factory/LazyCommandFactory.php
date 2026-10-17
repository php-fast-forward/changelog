<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 * @see       https://github.com/php-fast-forward/changelog
 */

namespace FastForward\Changelog\Console\CommandLoader\Factory;

use LogicException;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;

/** Wraps invokable services only after Symfony selects their executable definition. */
final readonly class LazyCommandFactory implements LazyCommandFactoryInterface
{
    /**
     * Creates a metadata-complete wrapper while deferring all dependency resolution.
     *
     * @param string             $name        Canonical command name to discover or resolve.
     * @param list<string>       $aliases     Alternative command names exposed without resolving the command service.
     * @param string             $description Short help description available before the command's dependencies are resolved.
     * @param class-string       $serviceId   Container identifier of the invokable command service loaded on demand.
     * @param ContainerInterface $container   Application container consulted only when the selected command is loaded.
     *
     * @return Command Metadata-complete lazy wrapper that resolves the selected invokable service only when requested.
     */
    public function create(
        string $name,
        array $aliases,
        string $description,
        string $serviceId,
        ContainerInterface $container,
    ): Command {
        return new LazyCommand($name, $aliases, $description, false, static function () use (
            $container,
            $serviceId
        ): Command {
            $handler = $container->get($serviceId);
            if (! is_callable($handler)) {
                throw new LogicException('The command service must be invokable: ' . $serviceId);
            }

            return new Command(null, $handler);
        });
    }
}
