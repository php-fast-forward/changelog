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

namespace FastForward\Changelog\Container\ServiceProvider;

use FastForward\Changelog\Checker\UnreleasedEntryChecker;
use FastForward\Changelog\Checker\UnreleasedEntryCheckerInterface;
use FastForward\Changelog\Console\CommandLoader\ChangelogCommandLoader;
use FastForward\Changelog\Console\CommandLoader\LazyCommandFactory;
use FastForward\Changelog\Console\CommandLoader\LazyCommandFactoryInterface;
use FastForward\Changelog\Date\ReleaseDateValidator;
use FastForward\Changelog\Date\ReleaseDateValidatorInterface;
use FastForward\Changelog\Document\ChangelogDocumentFactory;
use FastForward\Changelog\Document\ChangelogDocumentFactoryInterface;
use FastForward\Changelog\Document\ChangelogReleaseFactory;
use FastForward\Changelog\Document\ChangelogReleaseFactoryInterface;
use FastForward\Changelog\Entry\ChangelogEntryTypes;
use FastForward\Changelog\Entry\ChangelogEntryTypesInterface;
use FastForward\Changelog\Filesystem\PackageFilesystem;
use FastForward\Changelog\Filesystem\PackageFilesystemInterface;
use FastForward\Changelog\Filesystem\PackagePathResolver;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitFileReader;
use FastForward\Changelog\Git\GitFileReaderInterface;
use FastForward\Changelog\Git\GitRepositoryUrlResolver;
use FastForward\Changelog\Git\GitRepositoryUrlResolverInterface;
use FastForward\Changelog\Git\ProcessFactory;
use FastForward\Changelog\Git\ProcessFactoryInterface;
use FastForward\Changelog\Manager\ChangelogManager;
use FastForward\Changelog\Manager\ChangelogManagerInterface;
use FastForward\Changelog\Parser\ChangelogParser;
use FastForward\Changelog\Parser\ChangelogParserInterface;
use FastForward\Changelog\Renderer\MarkdownRenderer;
use FastForward\Changelog\Renderer\MarkdownRendererInterface;
use FastForward\Changelog\Version\ComposerPackageVersionResolver;
use FastForward\Changelog\Version\PackageVersionResolverInterface;
use FastForward\Clock\SystemClock;
use FastForward\Container\Factory\AliasFactory;
use Interop\Container\ServiceProviderInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

use function Safe\getcwd;

/**
 * Declares Changelog interface aliases and explicit composition factories.
 *
 * Factories MUST remain lazy and MUST use the provided PSR-11 container for
 * collaborator resolution.
 */
final readonly class ChangelogServiceProvider implements ServiceProviderInterface
{
    /**
     * Captures process-wide package metadata before any service is resolved.
     *
     * @param string|null $installedVersion Composer's pretty version, when available
     */
    public function __construct(
        private ?string $installedVersion = null,
    ) {}

    /**
     * Returns service factories keyed by their public contract.
     *
     * Interface aliases MUST delegate to autowireable concrete services. The
     * explicit factories MAY access process-wide state only at this composition
     * boundary; resolving the command loader MUST NOT instantiate any command.
     *
     * @return array<string, callable> factories consumed by a service-provider container
     */
    public function getFactories(): array
    {
        return [
            ChangelogDocumentFactoryInterface::class => new AliasFactory(ChangelogDocumentFactory::class),
            ChangelogReleaseFactoryInterface::class => new AliasFactory(ChangelogReleaseFactory::class),
            ChangelogEntryTypesInterface::class => new AliasFactory(ChangelogEntryTypes::class),
            PackageFilesystemInterface::class => new AliasFactory(PackageFilesystem::class),
            PackagePathResolverInterface::class => new AliasFactory(PackagePathResolver::class),
            GitFileReaderInterface::class => new AliasFactory(GitFileReader::class),
            GitRepositoryUrlResolverInterface::class => new AliasFactory(GitRepositoryUrlResolver::class),
            ProcessFactoryInterface::class => new AliasFactory(ProcessFactory::class),
            ChangelogParserInterface::class => new AliasFactory(ChangelogParser::class),
            MarkdownRendererInterface::class => new AliasFactory(MarkdownRenderer::class),
            UnreleasedEntryCheckerInterface::class => new AliasFactory(UnreleasedEntryChecker::class),
            ChangelogManagerInterface::class => new AliasFactory(ChangelogManager::class),
            LazyCommandFactoryInterface::class => new AliasFactory(LazyCommandFactory::class),
            ReleaseDateValidatorInterface::class => new AliasFactory(ReleaseDateValidator::class),
            PackageVersionResolverInterface::class => new AliasFactory(ComposerPackageVersionResolver::class),
            ClockInterface::class => new AliasFactory(SystemClock::class),
            ComposerPackageVersionResolver::class => fn(): ComposerPackageVersionResolver =>
                new ComposerPackageVersionResolver(
                    $this->installedVersion,
                ),
            PackagePathResolver::class => static fn(ContainerInterface $container): PackagePathResolver =>
                new PackagePathResolver(getcwd()),
            CommandLoaderInterface::class => static fn(ContainerInterface $container): ChangelogCommandLoader =>
                new ChangelogCommandLoader(
                    $container,
                    $container->get(LazyCommandFactoryInterface::class),
                ),
        ];
    }

    /**
     * Returns service extensions; Changelog currently defines none.
     *
     * The provider MUST return an empty map until service decoration becomes a
     * documented package contract.
     *
     * @return array<string, callable> service decorators
     */
    public function getExtensions(): array
    {
        return [];
    }
}
