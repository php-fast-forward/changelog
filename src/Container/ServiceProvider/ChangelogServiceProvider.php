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
use FastForward\Changelog\Automation;
use FastForward\Changelog\Changeset;
use FastForward\Changelog\Configuration;
use FastForward\Changelog\Console;
use FastForward\Changelog\Console\CommandLoader\ChangelogCommandLoader;
use FastForward\Changelog\Filesystem;
use FastForward\Changelog\Filesystem\PackagePathResolver;
use FastForward\Changelog\Fragment;
use FastForward\Changelog\Fragment\IdentifierGeneratorInterface;
use FastForward\Changelog\Git;
use FastForward\Changelog\GitHub;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\History;
use FastForward\Changelog\Process;
use FastForward\Changelog\Process\Factory\ProcessFactory;
use FastForward\Changelog\Publication;
use FastForward\Changelog\Release;
use FastForward\Changelog\Skill;
use FastForward\Changelog\Template;
use FastForward\Changelog\Validation;
use FastForward\Changelog\Validator;
use FastForward\Changelog\Version;
use FastForward\Changelog\Version\ComposerPackageVersionResolver;
use FastForward\Clock\SystemClock;
use FastForward\Config\ArrayConfig;
use FastForward\Config\ConfigInterface;
use FastForward\Container\Factory\AliasFactory;
use FastForward\Container\Factory\InvokableFactory;
use Interop\Container\ServiceProviderInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Random\Engine\Secure;
use Random\Randomizer;
use RuntimeException;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Declares lazy, consumer-owned composition for the standalone fragment CLI. */
final readonly class ChangelogServiceProvider implements ServiceProviderInterface
{
    /** Module-local resolved settings; consumer overrides use config.changelog.* entries. */
    public const string CONFIG = 'changelog.config';

    /**
     * Returns lazy service factories grouped by their domain responsibility.
     *
     * Building this map does not read the filesystem, resolve a command or open
     * a network connection. Consumer configuration is resolved only on demand.
     *
     * @return array<string, callable> Factories indexed by service identifier.
     */
    public function getFactories(): array
    {
        return [
            ...self::configurationFactories(),
            ...self::automationFactories(),
            ...self::releaseFactories(),
            ...self::historyFactories(),
            ...self::fragmentFactories(),
            ...self::consoleFactories(),
            ...self::integrationFactories(),
            ...self::runtimeFactories(),
        ];
    }

    /**
     * Builds lazy configuration sources and the module-local settings factory.
     *
     * @return array<string, callable> Lazy factories for this service group.
     */
    private static function configurationFactories(): array
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
            Configuration\Factory\ConfigSourceFactoryInterface::class => new AliasFactory(
                Configuration\Factory\ConfigSourceFactory::class,
            ),
        ];
    }

    /**
     * Declares contribution, Dependabot and managed version-PR orchestration.
     *
     * @return array<string, callable> Lazy factories for this service group.
     */
    private static function automationFactories(): array
    {
        return [
            Automation\PullRequest\PullRequestStatusServiceInterface::class => new AliasFactory(
                Automation\PullRequest\PullRequestStatusService::class,
            ),
            Automation\Output\GitHubOutputWriterInterface::class => new AliasFactory(
                Automation\Output\GitHubOutputWriter::class,
            ),
            Automation\Output\GitHubOutputWriter::class => static fn(
                ContainerInterface $container,
            ): Automation\Output\GitHubOutputWriter
                => new Automation\Output\GitHubOutputWriter(
                    $container->get(\Symfony\Component\Filesystem\Filesystem::class),
                    $container->get(self::CONFIG)->get('github_output_file'),
                ),
            Automation\AutomationRunnerInterface::class => new AliasFactory(
                Automation\AutomationRunner::class,
            ),
            Automation\VersionPullRequest\Factory\VersionPullRequestInputFactoryInterface::class => new AliasFactory(
                Automation\VersionPullRequest\Factory\VersionPullRequestInputFactory::class,
            ),
            Automation\VersionPullRequest\VersionPullRequestServiceInterface::class => new AliasFactory(
                Automation\VersionPullRequest\VersionPullRequestService::class,
            ),
            Automation\Dependabot\DependabotFragmentServiceInterface::class => new AliasFactory(
                Automation\Dependabot\DependabotFragmentService::class,
            ),
            Automation\Dependabot\Factory\DependabotInputFactoryInterface::class => new AliasFactory(
                Automation\Dependabot\Factory\DependabotInputFactory::class,
            ),
            Automation\Policy\PullRequestPolicyInterface::class => new AliasFactory(
                Automation\Policy\PullRequestPolicy::class,
            ),
        ];
    }

    /**
     * Declares release transactions, evidence validation and approved publication.
     *
     * @return array<string, callable> Lazy factories for this service group.
     */
    private static function releaseFactories(): array
    {
        return [
            Release\Reference\PullRequestReferenceResolverInterface::class => new AliasFactory(
                Release\Reference\PullRequestReferenceResolver::class,
            ),
            Release\ReleaseJournalPathResolverInterface::class => new AliasFactory(
                Release\ReleaseJournalPathResolver::class,
            ),
            Release\ReleaseJournalPathResolver::class => static fn(
                ContainerInterface $container,
            ): Release\ReleaseJournalPathResolver
                => new Release\ReleaseJournalPathResolver(
                    $container->get(Git\GitRepositoryInterface::class),
                    $container->get(self::CONFIG)->get('temporary_directory'),
                ),
            Release\Factory\ReleasePlanFactoryInterface::class => new AliasFactory(
                Release\Factory\ReleasePlanFactory::class,
            ),
            Release\ReceiptCodecInterface::class => new AliasFactory(
                Release\ReceiptCodec::class,
            ),
            Release\ReleaseApplierInterface::class => new AliasFactory(
                Release\ReleaseApplier::class,
            ),
            Release\Renderer\ReleaseNotesRendererInterface::class => new AliasFactory(
                Release\Renderer\ReleaseNotesRenderer::class,
            ),
            Release\ReleaseHistoryConsolidatorInterface::class => new AliasFactory(
                Release\ReleaseHistoryConsolidator::class,
            ),
            Release\Factory\ReleaseOptionsFactoryInterface::class => new AliasFactory(
                Release\Factory\ReleaseOptionsFactory::class,
            ),
            Release\ReleasePlannerInterface::class => new AliasFactory(
                Release\ReleasePlanner::class,
            ),
            Publication\PublicationServiceInterface::class => new AliasFactory(
                Publication\PublicationService::class,
            ),
            Publication\MergedPublicationServiceInterface::class => new AliasFactory(
                Publication\PublicationService::class,
            ),
            // Contextual publication needs its optional policy; the supported autowirer retains optional defaults.
            Publication\PublicationService::class => new InvokableFactory(
                Publication\PublicationService::class,
                Validator\PublicationEvidenceValidatorInterface::class,
                GitHubClientInterface::class,
                Automation\Policy\PullRequestPolicyInterface::class,
            ),
            Validator\ReleaseDateValidatorInterface::class => new AliasFactory(
                Validator\ReleaseDateValidator::class,
            ),
            Validator\ReleaseInputEvidenceValidatorInterface::class => new AliasFactory(
                Validator\ReleaseInputEvidenceValidator::class,
            ),
            Validator\PublicationEvidenceValidatorInterface::class => new AliasFactory(
                Validator\PublicationEvidenceValidator::class,
            ),
            Validator\MergedPublicationEvidenceValidatorInterface::class => new AliasFactory(
                Validator\PublicationEvidenceValidator::class,
            ),
            // Inject the optional immutable API proof reader rather than silently selecting local-only validation.
            Validator\PublicationEvidenceValidator::class => new InvokableFactory(
                Validator\PublicationEvidenceValidator::class,
                Git\GitRepositoryInterface::class,
                History\HistoryCodecInterface::class,
                Changeset\Parser\ChangesetParserInterface::class,
                Version\NextVersionResolverInterface::class,
                History\Import\HistoryImporterInterface::class,
                Release\Renderer\ReleaseNotesRendererInterface::class,
                Template\TemplateResolverInterface::class,
                Filesystem\PackagePathResolverInterface::class,
                Filesystem\ManagedFileStoreInterface::class,
                History\Factory\HistoryReleaseFactoryInterface::class,
                Release\ReleaseHistoryConsolidatorInterface::class,
                Release\Reference\PullRequestReferenceResolverInterface::class,
                GitHubClientInterface::class,
                Release\ReleaseMetadataParser::class,
                History\HistoryReferenceBuilder::class,
            ),
            Validator\ChangesetValidatorInterface::class => new AliasFactory(
                Validator\ChangesetValidator::class,
            ),
        ];
    }

    /**
     * Declares Markdown history parsing, import and construction boundaries.
     *
     * @return array<string, callable> Lazy factories for this service group.
     */
    private static function historyFactories(): array
    {
        return [
            History\Factory\HistoryDocumentFactoryInterface::class => new AliasFactory(
                History\Factory\HistoryDocumentFactory::class,
            ),
            History\Factory\HistoryReleaseFactoryInterface::class => new AliasFactory(
                History\Factory\HistoryReleaseFactory::class,
            ),
            History\HistoryCodecInterface::class => new AliasFactory(
                History\HistoryCodec::class,
            ),
            History\Import\HistoryImporterInterface::class => new AliasFactory(
                History\Import\HistoryImporter::class,
            ),
        ];
    }

    /**
     * Declares fragment parsing, storage, rendering and semantic version calculation.
     *
     * @return array<string, callable> Lazy factories for this service group.
     */
    private static function fragmentFactories(): array
    {
        return [
            Changeset\Parser\ChangesetParserInterface::class => new AliasFactory(
                Changeset\Parser\ChangesetParser::class,
            ),
            Changeset\Renderer\ChangesetRendererInterface::class => new AliasFactory(
                Changeset\Renderer\ChangesetRenderer::class,
            ),
            Changeset\Store\ChangesetStoreInterface::class => new AliasFactory(
                Changeset\Store\FilesystemChangesetStore::class,
            ),
            Fragment\FragmentWriterInterface::class => new AliasFactory(
                Fragment\FragmentWriter::class,
            ),
            Version\NextVersionResolverInterface::class => new AliasFactory(
                Version\NextVersionResolver::class,
            ),
            Version\VersionBaselineResolverInterface::class => new AliasFactory(
                Version\VersionBaselineResolver::class,
            ),
            Version\PackageVersionResolverInterface::class => new AliasFactory(
                Version\ComposerPackageVersionResolver::class,
            ),
            Version\VersionImpactResolverInterface::class => new AliasFactory(
                Version\VersionImpactResolver::class,
            ),
        ];
    }

    /**
     * Declares command construction, templates and contribution-report factories.
     *
     * @return array<string, callable> Lazy factories for this service group.
     */
    private static function consoleFactories(): array
    {
        return [
            Skill\SkillInstallerInterface::class => new AliasFactory(Skill\SkillInstaller::class),
            Skill\SkillInstaller::class => static fn(ContainerInterface $container): Skill\SkillInstaller => new Skill\SkillInstaller(
                $container->get(\Symfony\Component\Filesystem\Filesystem::class),
                $container->get(Filesystem\ManagedFileStoreInterface::class),
                $container->get(Filesystem\Factory\FinderFactoryInterface::class),
                $container->get(Filesystem\PackagePathResolverInterface::class),
                $container->get(\Symfony\Component\Lock\LockFactory::class),
                $container->get(Version\PackageVersionResolverInterface::class),
                self::configuration(
                    $container,
                    'skill_source_directory',
                    static fn(): string => dirname(__DIR__, 3) . '/resources/skills/' . Skill\SkillInstaller::NAME,
                ),
                self::configuration(
                    $container,
                    'skill_home_directory',
                    static fn(): ?string => getenv('HOME') ?: (getenv('USERPROFILE') ?: null),
                ),
                self::configuration($container, 'installed_reference', static fn(): ?string => null),
            ),
            Console\SkillInstallationWarning::class => static fn(ContainerInterface $container): Console\SkillInstallationWarning => new Console\SkillInstallationWarning(
                static fn(string $cwd): array => $container->get(Skill\SkillInstallerInterface::class)->run(
                    $cwd,
                    mode: 'check',
                ),
                self::configuration(
                    $container,
                    'skill_warning_enabled',
                    static fn(): bool => defined('STDOUT') && defined('STDERR')
                    && stream_isatty(STDOUT) && stream_isatty(STDERR) && ! getenv('CI') && ! getenv('GITHUB_ACTIONS'),
                ),
            ),
            Console\Changelog::class => static fn(ContainerInterface $container): Console\Changelog => new Console\Changelog(
                $container->get(CommandLoaderInterface::class),
                $container->get(Version\PackageVersionResolverInterface::class),
                static function (
                    \Symfony\Component\Console\Input\InputInterface $input,
                    \Symfony\Component\Console\Output\OutputInterface $error,
                ) use (
                    $container
                ): void {
                    $cwd = $input->getParameterOption(['--cwd', '-C'], '.');
                    $container->get(Console\SkillInstallationWarning::class)->warn(
                        is_string($cwd) ? $cwd : '.',
                        $error,
                    );
                },
            ),
            Console\PlanCommandRunnerInterface::class => new AliasFactory(
                Console\PlanCommandRunner::class,
            ),
            Template\Factory\TemplateFactoryInterface::class => new AliasFactory(
                Template\Factory\TemplateFactory::class,
            ),
            Template\TemplateResolverInterface::class => new AliasFactory(
                Template\TemplateResolver::class,
            ),
            Validation\CheckServiceInterface::class => new AliasFactory(
                Validation\CheckService::class,
            ),
        ];
    }

    /**
     * Declares filesystem and Git adapters together with the retained process-construction boundary.
     *
     * @return array<string, callable> Lazy factories for this service group.
     */
    private static function integrationFactories(): array
    {
        return [
            Filesystem\Factory\FinderFactoryInterface::class => new AliasFactory(
                Filesystem\Factory\FinderFactory::class,
            ),
            Filesystem\ManagedFileStoreInterface::class => new AliasFactory(
                Filesystem\ManagedFileStore::class,
            ),
            Filesystem\PackagePathResolverInterface::class => new AliasFactory(
                Filesystem\PackagePathResolver::class,
            ),
            Git\GitRepositoryInterface::class => new AliasFactory(
                Git\GitRepository::class,
            ),
            Git\GitCommitTreeReaderInterface::class => new AliasFactory(
                Git\GitRepository::class,
            ),
            Git\GitFragmentOriginReaderInterface::class => new AliasFactory(
                Git\GitRepository::class,
            ),
            Process\Factory\ProcessFactoryInterface::class => new AliasFactory(
                Process\Factory\ProcessFactory::class,
            ),
        ];
    }

    /**
     * Declares shared runtime defaults without constructing adapters or commands.
     *
     * @return array<string, callable> Lazy factories for this service group.
     */
    private static function runtimeFactories(): array
    {
        return [
            IdentifierGeneratorInterface::class => new AliasFactory(Fragment\RandomIdentifierGenerator::class),
            Randomizer::class => static fn(): Randomizer => new Randomizer(new Secure()),
            HttpClientInterface::class => static fn(): HttpClientInterface => HttpClient::create(),
            GitHubClientInterface::class => new AliasFactory(GitHub\GitHubClient::class),
            GitHub\GitHubClient::class => static fn(ContainerInterface $container): GitHub\GitHubClient
                => new GitHub\GitHubClient(
                    $container->get(HttpClientInterface::class),
                    $container->get(self::CONFIG)->get('token'),
                    $container->get(self::CONFIG)->get('api_url'),
                ),
            PersistingStoreInterface::class => new AliasFactory(FlockStore::class),
            ClockInterface::class => new AliasFactory(SystemClock::class),
            DateTimeZone::class => static fn(): DateTimeZone => new DateTimeZone('UTC'),
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
            CommandLoaderInterface::class => new AliasFactory(ChangelogCommandLoader::class),
        ];
    }

    /**
     * Returns an empty decoration map until an explicit extension contract is introduced.
     *
     * @return array<never,never> Empty service-decoration map; the provider adds factories without overriding existing services through extensions.
     */
    public function getExtensions(): array
    {
        return [];
    }

    /**
     * Reads an optional consumer config entry; evaluates the host default only when that entry is absent.
     *
     * @param ContainerInterface $container Consumer container supplying optional config.changelog.* entries, including explicit null or empty values.
     * @param string             $key       Raw changelog setting name resolved through the config.changelog. namespace.
     * @param Closure():mixed    $default   Lazy fallback evaluated only when the corresponding consumer config entry is absent.
     *
     * @return mixed Explicit consumer config value, including null or empty values; otherwise the lazily evaluated fallback.
     */
    private static function configuration(ContainerInterface $container, string $key, Closure $default): mixed
    {
        $id = 'config.changelog.' . $key;

        return $container->has($id) ? $container->get($id) : $default();
    }
}
