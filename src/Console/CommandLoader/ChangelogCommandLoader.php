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

use FastForward\Changelog\Console\Command\CheckCommand;
use FastForward\Changelog\Console\Command\EntryCommand;
use FastForward\Changelog\Console\Command\PromoteCommand;
use FastForward\Changelog\Console\Command\ReleaseNotesRenderCommand;
use FastForward\Changelog\Console\Command\VersionResolveCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * Maps public command names to lazy container services.
 *
 * Metadata lookup MUST NOT query the container. This preserves CLI discovery
 * when one command has an invalid dependency graph.
 */
final class ChangelogCommandLoader implements CommandLoaderInterface
{
    /**
     * @var array<string, array{service: class-string<Command>, aliases: list<string>, description: string}>
     */
    private const array DEFINITIONS = [
        'changelog:check' => [
            'service' => CheckCommand::class,
            'aliases' => [],
            'description' => 'Check whether a changelog file contains meaningful unreleased entries.',
        ],
        'changelog:entry' => [
            'service' => EntryCommand::class,
            'aliases' => [],
            'description' => 'Add a changelog entry to Unreleased or a specific version section.',
        ],
        'changelog:promote' => [
            'service' => PromoteCommand::class,
            'aliases' => [],
            'description' => 'Promote Unreleased entries into a published changelog version.',
        ],
        'changelog:resolve-version' => [
            'service' => VersionResolveCommand::class,
            'aliases' => ['changelog:next-version'],
            'description' => 'Resolve the release version from input or infer it from Unreleased entries.',
        ],
        'changelog:render-release-notes' => [
            'service' => ReleaseNotesRenderCommand::class,
            'aliases' => ['changelog:show', 'changelog:release-notes'],
            'description' => 'Render a changelog section into a release-notes body or output file.',
        ],
    ];

    /**
     * @var array<string, string>
     */
    private array $names = [];

    /**
     * @var array<string, Command>
     */
    private array $commands = [];

    /**
     * Builds the canonical-name and alias index from immutable metadata.
     *
     * Construction MUST NOT query the container or create a command. The
     * loader SHALL retain both collaborators for deferred command resolution.
     *
     * @param ContainerInterface $container resolves commands only inside lazy wrappers
     * @param LazyCommandFactoryInterface $commandFactory creates lazy wrappers
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LazyCommandFactoryInterface $commandFactory,
    ) {
        foreach (self::DEFINITIONS as $name => $definition) {
            $this->names[$name] = $name;

            foreach ($definition['aliases'] as $alias) {
                $this->names[$alias] = $name;
            }
        }
    }

    /**
     * Returns a cached lazy wrapper for a canonical name or alias.
     *
     * Aliases MUST resolve to the same wrapper as their canonical name. The
     * wrapper factory SHALL receive the container without resolving the target
     * command service.
     *
     * @param string $name canonical command name or supported alias
     *
     * @return Command metadata-complete lazy command wrapper
     *
     * @throws CommandNotFoundException when the name is unknown
     */
    public function get(string $name): Command
    {
        if (! isset($this->names[$name])) {
            throw new CommandNotFoundException(\sprintf('Command "%s" is not defined.', $name));
        }

        $canonicalName = $this->names[$name];
        $definition = self::DEFINITIONS[$canonicalName];

        return $this->commands[$canonicalName] ??= $this->commandFactory->create(
            $canonicalName,
            $definition['aliases'],
            $definition['description'],
            $definition['service'],
            $this->container,
        );
    }

    /**
     * Determines whether explicit metadata exists for a name or alias.
     *
     * This method MUST consult only the local map and MUST NOT query the
     * container because Symfony calls it while discovering commands.
     *
     * @param string $name canonical command name or supported alias
     *
     * @return bool true when the name is mapped, false otherwise
     */
    public function has(string $name): bool
    {
        return isset($this->names[$name]);
    }

    /**
     * Returns every canonical name and alias without resolving any service.
     *
     * The returned order SHALL follow the explicit definition order so CLI
     * discovery remains deterministic.
     *
     * @return list<string> canonical command names and aliases
     */
    public function getNames(): array
    {
        return array_keys($this->names);
    }
}
