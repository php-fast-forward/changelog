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
use FastForward\Changelog\Console\Command\StatusCommand;
use FastForward\Changelog\Console\Command\VersionCommand;
use FastForward\Changelog\Console\CommandLoader\Factory\LazyCommandFactoryInterface;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/** Discovers public metadata once from attributes without resolving a command graph. */
final class ChangelogCommandLoader implements CommandLoaderInterface
{
    private const array SERVICES = [AddCommand::class, CheckCommand::class, StatusCommand::class, VersionCommand::class,
        NotesCommand::class, PublishCommand::class, BackfillCommand::class, FormatCommand::class, GitHubCommand::class];
    /** @var array<string,array{service:string,metadata:AsCommand}> Public metadata derived solely from attributes. */
    private array $definitions = [];
    /** @var array<string,Command> Cached lazy wrappers, never eagerly resolved services. */
    private array $commands = [];

    /** Reflects attribute metadata without constructing services or querying the container. */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LazyCommandFactoryInterface $factory,
    ) {
        foreach (self::SERVICES as $service) {
            $metadata = new ReflectionClass($service)->getAttributes(AsCommand::class)[0]->newInstance();
            $this->definitions[$metadata->name] = ['service' => $service, 'metadata' => $metadata];
        }
    }

    /** Returns one cached lazy wrapper while preserving isolated command failures. */
    public function get(string $name): Command
    {
        if (! isset($this->definitions[$name])) {
            throw new CommandNotFoundException(sprintf('Command "%s" is not defined.', $name));
        }
        $definition = $this->definitions[$name];

        return $this->commands[$name] ??= $this->factory->create(
            $name,
            [],
            $definition['metadata']->description,
            $definition['service'],
            $this->container,
        );
    }

    /** Looks up public names locally without resolving dependencies. */
    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    /** Lists the exact public command names in stable presentation order. */
    public function getNames(): array
    {
        return array_keys($this->definitions);
    }
}
