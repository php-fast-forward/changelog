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

namespace FastForward\Changelog\Container\ServiceProvider;

use Closure;
use DateTimeZone;
use FastForward\Changelog\Console\CommandLoader\ChangelogCommandLoader;
use FastForward\Changelog\Console\CommandLoader\Factory\LazyCommandFactoryInterface;
use FastForward\Changelog\Date\Factory\TimezoneFactory;
use FastForward\Changelog\Filesystem\PackagePathResolver;
use FastForward\Changelog\Fragment\Factory\IdentifierGeneratorFactoryInterface;
use FastForward\Changelog\Fragment\IdentifierGeneratorInterface;
use FastForward\Changelog\Git\Factory\ProcessFactory;
use FastForward\Changelog\GitHub\Factory\GitHubClientFactoryInterface;
use FastForward\Changelog\GitHub\Factory\HttpClientFactoryInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Version\ComposerPackageVersionResolver;
use FastForward\Clock\SystemClock;
use FastForward\Config\ArrayConfig;
use FastForward\Config\ConfigInterface;
use FastForward\Container\Factory\AliasFactory;
use Interop\Container\ServiceProviderInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Declares lazy, consumer-owned composition for the standalone fragment CLI. */
final readonly class ChangelogServiceProvider implements ServiceProviderInterface
{
    /** Module-local resolved settings; consumer overrides use config.changelog.* entries. */
    public const string CONFIG = 'changelog.config';

    /** Returns explicit factories and autowiring aliases without instantiating I/O adapters or commands. */
    public function getFactories(): array
    {
        return [
            self::CONFIG => static fn(ContainerInterface $container): ConfigInterface => new ArrayConfig([
                'working_directory' => self::configuration(
                    $container,
                    'working_directory',
                    static fn(): string => getcwd() ?: throw new RuntimeException(
                        'Could not resolve the changelog working directory.',
                    ),
                ),
                'temporary_directory' => self::configuration(
                    $container,
                    'temporary_directory',
                    static fn(): string => realpath(sys_get_temp_dir()) ?: sys_get_temp_dir(),
                ),
                'installed_version' => self::configuration(
                    $container,
                    'installed_version',
                    static fn(): ?string => null,
                ),
                'token' => self::configuration($container, 'token', static fn(): string => ''),
                'api_url' => self::configuration(
                    $container,
                    'api_url',
                    static fn(): string => 'https://api.github.com',
                ),
                'github_output_file' => self::configuration(
                    $container,
                    'github_output_file',
                    static fn(): ?string => null,
                ),
            ]),
            \FastForward\Changelog\Console\GitHubOutputWriterInterface::class => new AliasFactory(
                \FastForward\Changelog\Console\GitHubOutputWriter::class,
            ),
            \FastForward\Changelog\Console\GitHubOutputWriter::class => static fn(
                ContainerInterface $container,
            ): \FastForward\Changelog\Console\GitHubOutputWriter
                => new \FastForward\Changelog\Console\GitHubOutputWriter(
                    $container->get(\Symfony\Component\Filesystem\Filesystem::class),
                    $container->get(self::CONFIG)->get('github_output_file'),
                ),
            \FastForward\Changelog\Release\ReleaseJournalPathResolverInterface::class => new AliasFactory(
                \FastForward\Changelog\Release\ReleaseJournalPathResolver::class,
            ),
            \FastForward\Changelog\Release\ReleaseJournalPathResolver::class => static fn(
                ContainerInterface $container,
            ): \FastForward\Changelog\Release\ReleaseJournalPathResolver
                => new \FastForward\Changelog\Release\ReleaseJournalPathResolver(
                    $container->get(\FastForward\Changelog\Git\GitRepositoryInterface::class),
                    $container->get(self::CONFIG)->get('temporary_directory'),
                ),
            \FastForward\Changelog\Automation\AutomationRunnerInterface::class => new AliasFactory(
                \FastForward\Changelog\Automation\AutomationRunner::class,
            ),
            \FastForward\Changelog\Validator\ReleaseDateValidatorInterface::class => new AliasFactory(
                \FastForward\Changelog\Validator\ReleaseDateValidator::class,
            ),
            \FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestExceptionFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestExceptionFactory::class,
            ),
            \FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestInputFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestInputFactory::class,
            ),
            \FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestResultFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestResultFactory::class,
            ),
            \FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestServiceInterface::class => new AliasFactory(
                \FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestService::class,
            ),
            \FastForward\Changelog\Automation\Dependabot\DependabotFragmentServiceInterface::class => new AliasFactory(
                \FastForward\Changelog\Automation\Dependabot\DependabotFragmentService::class,
            ),
            \FastForward\Changelog\Automation\Dependabot\Factory\DependabotFragmentResultFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Automation\Dependabot\Factory\DependabotFragmentResultFactory::class,
            ),
            \FastForward\Changelog\Automation\Dependabot\Factory\DependabotInputFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Automation\Dependabot\Factory\DependabotInputFactory::class,
            ),
            \FastForward\Changelog\Automation\Policy\Factory\PullRequestAuthorizationFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Automation\Policy\Factory\PullRequestAuthorizationFactory::class,
            ),
            \FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface::class => new AliasFactory(
                \FastForward\Changelog\Automation\Policy\PullRequestPolicy::class,
            ),
            \FastForward\Changelog\Changeset\Factory\ChangesetFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Changeset\Factory\ChangesetFactory::class,
            ),
            \FastForward\Changelog\Changeset\Factory\ChangesetParseResultFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Changeset\Factory\ChangesetParseResultFactory::class,
            ),
            \FastForward\Changelog\Changeset\Parser\ChangesetParserInterface::class => new AliasFactory(
                \FastForward\Changelog\Changeset\Parser\ChangesetParser::class,
            ),
            \FastForward\Changelog\Changeset\Renderer\ChangesetRendererInterface::class => new AliasFactory(
                \FastForward\Changelog\Changeset\Renderer\ChangesetRenderer::class,
            ),
            \FastForward\Changelog\Changeset\Store\ChangesetStoreInterface::class => new AliasFactory(
                \FastForward\Changelog\Changeset\Store\FilesystemChangesetStore::class,
            ),
            \FastForward\Changelog\Configuration\Factory\ConfigSourceFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Configuration\Factory\ConfigSourceFactory::class,
            ),
            \FastForward\Changelog\Console\CommandLoader\Factory\LazyCommandFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Console\CommandLoader\Factory\LazyCommandFactory::class,
            ),
            \FastForward\Changelog\Console\PlanCommandRunnerInterface::class => new AliasFactory(
                \FastForward\Changelog\Console\PlanCommandRunner::class,
            ),
            \FastForward\Changelog\Filesystem\Factory\FinderFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Filesystem\Factory\FinderFactory::class,
            ),
            \FastForward\Changelog\Filesystem\Factory\PathExceptionFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Filesystem\Factory\PathExceptionFactory::class,
            ),
            \FastForward\Changelog\Filesystem\ManagedFileStoreInterface::class => new AliasFactory(
                \FastForward\Changelog\Filesystem\ManagedFileStore::class,
            ),
            \FastForward\Changelog\Filesystem\PackagePathResolverInterface::class => new AliasFactory(
                \FastForward\Changelog\Filesystem\PackagePathResolver::class,
            ),
            \FastForward\Changelog\Fragment\Factory\FragmentExceptionFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Fragment\Factory\FragmentExceptionFactory::class,
            ),
            \FastForward\Changelog\Fragment\Factory\IdentifierGeneratorFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Fragment\Factory\IdentifierGeneratorFactory::class,
            ),
            \FastForward\Changelog\Fragment\FragmentWriterInterface::class => new AliasFactory(
                \FastForward\Changelog\Fragment\FragmentWriter::class,
            ),
            \FastForward\Changelog\GitHub\Factory\GitHubClientFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\GitHub\Factory\GitHubClientFactory::class,
            ),
            \FastForward\Changelog\GitHub\Factory\GitHubExceptionFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\GitHub\Factory\GitHubExceptionFactory::class,
            ),
            \FastForward\Changelog\GitHub\Factory\HttpClientFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\GitHub\Factory\HttpClientFactory::class,
            ),
            \FastForward\Changelog\Git\GitRepositoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Git\GitRepository::class,
            ),
            \FastForward\Changelog\Git\Factory\ProcessFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Git\Factory\ProcessFactory::class,
            ),
            \FastForward\Changelog\History\Factory\HistoryDocumentFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\History\Factory\HistoryDocumentFactory::class,
            ),
            \FastForward\Changelog\History\Factory\HistoryExceptionFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\History\Factory\HistoryExceptionFactory::class,
            ),
            \FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\History\Factory\HistoryReleaseFactory::class,
            ),
            \FastForward\Changelog\History\HistoryCodecInterface::class => new AliasFactory(
                \FastForward\Changelog\History\HistoryCodec::class,
            ),
            \FastForward\Changelog\History\Import\Factory\HistoryImportResultFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\History\Import\Factory\HistoryImportResultFactory::class,
            ),
            \FastForward\Changelog\History\Import\HistoryImporterInterface::class => new AliasFactory(
                \FastForward\Changelog\History\Import\HistoryImporter::class,
            ),
            \FastForward\Changelog\Publication\Factory\PublicationEvidenceFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Publication\Factory\PublicationEvidenceFactory::class,
            ),
            \FastForward\Changelog\Publication\Factory\PublicationResultFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Publication\Factory\PublicationResultFactory::class,
            ),
            \FastForward\Changelog\Validator\ReleaseInputEvidenceValidatorInterface::class => new AliasFactory(
                \FastForward\Changelog\Validator\ReleaseInputEvidenceValidator::class,
            ),
            \FastForward\Changelog\Validator\PublicationEvidenceValidatorInterface::class => new AliasFactory(
                \FastForward\Changelog\Validator\PublicationEvidenceValidator::class,
            ),
            \FastForward\Changelog\Publication\PublicationServiceInterface::class => new AliasFactory(
                \FastForward\Changelog\Publication\PublicationService::class,
            ),
            \FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Release\Factory\ReleaseExceptionFactory::class,
            ),
            \FastForward\Changelog\Release\Factory\ReleasePlanFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Release\Factory\ReleasePlanFactory::class,
            ),
            \FastForward\Changelog\Release\Factory\ReleaseReceiptFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Release\Factory\ReleaseReceiptFactory::class,
            ),
            \FastForward\Changelog\Release\ReceiptCodecInterface::class => new AliasFactory(
                \FastForward\Changelog\Release\ReceiptCodec::class,
            ),
            \FastForward\Changelog\Release\ReleaseApplierInterface::class => new AliasFactory(
                \FastForward\Changelog\Release\ReleaseApplier::class,
            ),
            \FastForward\Changelog\Release\ReleaseNotesRendererInterface::class => new AliasFactory(
                \FastForward\Changelog\Release\ReleaseNotesRenderer::class,
            ),
            \FastForward\Changelog\Release\ReleaseHistoryConsolidatorInterface::class => new AliasFactory(
                \FastForward\Changelog\Release\ReleaseHistoryConsolidator::class,
            ),
            \FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Release\Factory\ReleaseOptionsFactory::class,
            ),
            \FastForward\Changelog\Release\ReleasePlannerInterface::class => new AliasFactory(
                \FastForward\Changelog\Release\ReleasePlanner::class,
            ),
            \FastForward\Changelog\Template\Factory\TemplateFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Template\Factory\TemplateFactory::class,
            ),
            \FastForward\Changelog\Template\TemplateResolverInterface::class => new AliasFactory(
                \FastForward\Changelog\Template\TemplateResolver::class,
            ),
            \FastForward\Changelog\Validator\ChangesetValidatorInterface::class => new AliasFactory(
                \FastForward\Changelog\Validator\ChangesetValidator::class,
            ),
            \FastForward\Changelog\Validation\CheckServiceInterface::class => new AliasFactory(
                \FastForward\Changelog\Validation\CheckService::class,
            ),
            \FastForward\Changelog\Validation\Factory\ValidationReportFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Validation\Factory\ValidationReportFactory::class,
            ),
            \FastForward\Changelog\Version\Factory\VersionResolutionFactoryInterface::class => new AliasFactory(
                \FastForward\Changelog\Version\Factory\VersionResolutionFactory::class,
            ),
            \FastForward\Changelog\Version\NextVersionResolverInterface::class => new AliasFactory(
                \FastForward\Changelog\Version\NextVersionResolver::class,
            ),
            \FastForward\Changelog\Version\PackageVersionResolverInterface::class => new AliasFactory(
                \FastForward\Changelog\Version\ComposerPackageVersionResolver::class,
            ),
            \FastForward\Changelog\Version\VersionImpactResolverInterface::class => new AliasFactory(
                \FastForward\Changelog\Version\VersionImpactResolver::class,
            ),

            IdentifierGeneratorInterface::class => static fn(ContainerInterface $container): IdentifierGeneratorInterface
                => $container->get(IdentifierGeneratorFactoryInterface::class)->create(),
            HttpClientInterface::class => static fn(ContainerInterface $container): HttpClientInterface
                => $container->get(HttpClientFactoryInterface::class)->create(),
            GitHubClientInterface::class => static fn(ContainerInterface $container): GitHubClientInterface
                => $container->get(GitHubClientFactoryInterface::class)->create(
                    $container->get(self::CONFIG)->get('token'),
                    $container->get(self::CONFIG)->get('api_url'),
                ),
            PersistingStoreInterface::class => new AliasFactory(FlockStore::class),
            ClockInterface::class => new AliasFactory(SystemClock::class),
            DateTimeZone::class => static fn(ContainerInterface $container): DateTimeZone
                => $container->get(TimezoneFactory::class)->create(),
            SystemClock::class => static fn(ContainerInterface $container): SystemClock
                => new SystemClock($container->get(DateTimeZone::class)),
            ComposerPackageVersionResolver::class => static fn(ContainerInterface $container): ComposerPackageVersionResolver
                => new ComposerPackageVersionResolver($container->get(self::CONFIG)->get('installed_version')),
            PackagePathResolver::class => static fn(ContainerInterface $container): PackagePathResolver
                => new PackagePathResolver($container->get(self::CONFIG)->get('working_directory')),
            ProcessFactory::class => static fn(ContainerInterface $container): ProcessFactory => new ProcessFactory(
                $container->get(
                    self::CONFIG,
                )->get(
                    'working_directory',
                ),
            ),
            CommandLoaderInterface::class => static fn(ContainerInterface $container): ChangelogCommandLoader
                => new ChangelogCommandLoader($container, $container->get(LazyCommandFactoryInterface::class)),
        ];
    }

    /** Returns an empty decoration map until an explicit extension contract is introduced. */
    public function getExtensions(): array
    {
        return [];
    }

    /** Reads an optional consumer config entry; evaluates the host default only when that entry is absent. */
    private static function configuration(ContainerInterface $container, string $key, Closure $default): mixed
    {
        $id = 'config.changelog.' . $key;

        return $container->has($id) ? $container->get($id) : $default();
    }
}
