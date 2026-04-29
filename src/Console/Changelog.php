<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @author   Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license  https://opensource.org/licenses/MIT MIT License
 *
 * @see      https://github.com/php-fast-forward/changelog
 * @see      https://github.com/php-fast-forward/changelog/issues
 * @see      https://php-fast-forward.github.io/changelog/
 * @see      https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Console;

use Composer\InstalledVersions;
use DI\Container;
use FastForward\Changelog\Console\Command\ChangelogEntryCommand;
use FastForward\Changelog\Console\Command\ChangelogPromoteCommand;
use FastForward\Changelog\Console\Command\ChangelogReleaseNotesRenderCommand;
use FastForward\Changelog\Console\Command\ChangelogVersionResolveCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

final class Changelog extends Application
{
    /**
     * @var list<class-string<Command>>
     */
    private const COMMANDS = [
        ChangelogEntryCommand::class,
        ChangelogPromoteCommand::class,
        ChangelogVersionResolveCommand::class,
        ChangelogReleaseNotesRenderCommand::class,
    ];

    public function __construct(
        private readonly Container $container = new Container(),
    ) {
        $version = InstalledVersions::getPrettyVersion('fast-forward/changelog') ?? '0.1.x-dev';

        parent::__construct('Fast Forward Changelog', $version);

        foreach (self::COMMANDS as $commandClassName) {
            $this->add($this->resolveCommand($commandClassName));
        }
    }

    /**
     * @param class-string<Command> $commandClassName
     */
    private function resolveCommand(string $commandClassName): Command
    {
        /** @var Command $command */
        $command = $this->container->get($commandClassName);

        return $command;
    }
}
