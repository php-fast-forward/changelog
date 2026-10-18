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

namespace FastForward\Changelog\Console\CommandLoader;

use FastForward\Changelog\Console\Command\AddCommand;
use FastForward\Changelog\Console\Command\BackfillCommand;
use FastForward\Changelog\Console\Command\CheckCommand;
use FastForward\Changelog\Console\Command\FormatCommand;
use FastForward\Changelog\Console\Command\GitHubCommand;
use FastForward\Changelog\Console\Command\NotesCommand;
use FastForward\Changelog\Console\Command\PublishCommand;
use FastForward\Changelog\Console\Command\SkillInstallCommand;
use FastForward\Changelog\Console\Command\StatusCommand;
use FastForward\Changelog\Console\Command\VersionCommand;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/** Discovers public metadata once from attributes without resolving a command graph. */
final class ChangelogCommandLoader implements CommandLoaderInterface
{
    private const array SERVICES = [AddCommand::class, CheckCommand::class, StatusCommand::class, VersionCommand::class,
        NotesCommand::class, PublishCommand::class, BackfillCommand::class, FormatCommand::class, GitHubCommand::class, SkillInstallCommand::class];
    /** @var array<string,array{service:string,metadata:AsCommand}> Public metadata derived solely from attributes. */
    private array $definitions = [];
    /** @var array<string,Command> Cached lazy wrappers, never eagerly resolved services. */
    private array $commands = [];

    /**
     * Reflects attribute metadata without constructing services or querying the container.
     *
     * @param ContainerInterface $container Application container consulted only when the selected command is loaded.
     */
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
        foreach (self::SERVICES as $service) {
            $metadata = new ReflectionClass($service)->getAttributes(AsCommand::class)[0]->newInstance();
            $this->definitions[$metadata->name] = ['service' => $service, 'metadata' => $metadata];
        }
    }

    /**
     * Returns one cached lazy wrapper while preserving isolated command failures.
     *
     * @param string $name Canonical command name to discover or resolve.
     *
     * @return Command Cached lazy wrapper for the requested command; selected dependencies remain deferred.
     *
     * @throws CommandNotFoundException When no command metadata declares the requested name.
     */
    public function get(string $name): Command
    {
        if (! isset($this->definitions[$name])) {
            throw new CommandNotFoundException(sprintf('Command "%s" is not defined.', $name));
        }
        $definition = $this->definitions[$name];

        return $this->commands[$name] ??= $this->createLazyCommand(
            $name,
            $definition['metadata']->description,
            $definition['service'],
        );
    }

    /**
     * Defers service lookup and callable inspection until the selected wrapper initializes.
     *
     * @param string       $name        Canonical public command name retained without resolving the handler.
     * @param string       $description Attribute-derived description available to list and general help.
     * @param class-string $serviceId   Invokable service resolved from the injected application container on initialization.
     *
     * @return Command Lazy wrapper whose metadata remains available even when the selected service graph cannot load.
     *
     * @throws LogicException When the selected service resolves to a non-invokable value during wrapper initialization.
     */
    private function createLazyCommand(string $name, string $description, string $serviceId): Command
    {
        $container = $this->container;

        return new LazyCommand($name, [], $description, false, static function () use (
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

    /**
     * Looks up public names locally without resolving dependencies.
     *
     * @param string $name Canonical command name to discover or resolve.
     *
     * @return bool Whether the local command metadata declares the requested public name.
     */
    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    /**
     * Lists the exact public command names in stable presentation order.
     *
     * @return list<string> Public command names in stable discovery order.
     */
    public function getNames(): array
    {
        return array_keys($this->definitions);
    }
}
