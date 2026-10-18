<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Container\ServiceProvider;

use DateTimeZone;
use FastForward\Changelog\Automation\AutomationRunnerInterface;
use FastForward\Changelog\Automation\Output\GitHubOutputWriter;
use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestInputFactoryInterface;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestServiceInterface;
use FastForward\Changelog\Console\Changelog;
use FastForward\Changelog\Console\Command\StatusCommand;
use FastForward\Changelog\Console\CommandLoader\ChangelogCommandLoader;
use FastForward\Changelog\Console\SkillInstallationWarning;
use FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider;
use FastForward\Changelog\Filesystem\Factory\FinderFactoryInterface;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolver;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Fragment\IdentifierGeneratorInterface;
use FastForward\Changelog\Fragment\RandomIdentifierGenerator;
use FastForward\Changelog\Git\GitCommitTreeReaderInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubClient;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\History\Factory\HistoryReleaseFactory;
use FastForward\Changelog\History\HistoryCodec;
use FastForward\Changelog\History\Import\HistoryImporter;
use FastForward\Changelog\Process\Factory\ProcessFactory;
use FastForward\Changelog\Publication\MergedPublicationServiceInterface;
use FastForward\Changelog\Publication\PublicationService;
use FastForward\Changelog\Publication\PublicationServiceInterface;
use FastForward\Changelog\Release\Reference\PullRequestReferenceResolver;
use FastForward\Changelog\Release\ReleaseHistoryConsolidator;
use FastForward\Changelog\Release\ReleaseJournalPathResolver;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use FastForward\Changelog\Skill\SkillInstaller;
use FastForward\Changelog\Skill\SkillInstallerInterface;
use FastForward\Changelog\Template\TemplateResolver;
use FastForward\Changelog\Tests\Container\ServiceProvider\Fixture\RuntimeDefaults;
use FastForward\Changelog\Validator\MergedPublicationEvidenceValidatorInterface;
use FastForward\Changelog\Validator\PublicationEvidenceValidator;
use FastForward\Changelog\Validator\PublicationEvidenceValidatorInterface;
use FastForward\Changelog\Validator\ReleaseDateValidatorInterface;
use FastForward\Changelog\Version\ComposerPackageVersionResolver;
use FastForward\Changelog\Version\NextVersionResolver;
use FastForward\Changelog\Version\PackageVersionResolverInterface;
use FastForward\Clock\SystemClock;
use FastForward\Config\ArrayConfig;
use FastForward\Container\Factory\AliasFactory;
use FastForward\Container\ServiceProvider\ArrayServiceProvider;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Random\Engine;
use Random\Engine\Secure;
use Random\Randomizer;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function FastForward\Config\config;
use function FastForward\Container\container;

require_once __DIR__ . '/Fixture/runtime.php';

#[CoversClass(ChangelogServiceProvider::class)]
#[UsesClass(ChangelogCommandLoader::class)]
#[UsesClass(PackagePathResolver::class)]
#[UsesClass(ProcessFactory::class)]
#[UsesClass(ComposerPackageVersionResolver::class)]
#[UsesClass(ReleaseJournalPathResolver::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(GitHubOutputWriter::class)]
#[UsesClass(GitHubClient::class)]
#[UsesClass(PublicationService::class)]
#[UsesClass(PublicationEvidenceValidator::class)]
#[UsesClass(RandomIdentifierGenerator::class)]
#[UsesClass(GitHubEvidence::class)]
#[UsesClass(HistoryReleaseFactory::class)]
#[UsesClass(HistoryCodec::class)]
#[UsesClass(HistoryImporter::class)]
#[UsesClass(ReleaseHistoryConsolidator::class)]
#[UsesClass(TemplateResolver::class)]
#[UsesClass(NextVersionResolver::class)]
#[UsesClass(PullRequestReferenceResolver::class)]
#[UsesClass(SkillInstaller::class)]
#[UsesClass(SkillInstallationWarning::class)]
#[UsesClass(Changelog::class)]
final class ChangelogServiceProviderTest extends TestCase
{
    /** Resets synthetic defaults before each test; unit tests never observe the real host. */
    protected function setUp(): void
    {
        RuntimeDefaults::$workingDirectory = '/consumer';
        RuntimeDefaults::$temporaryDirectory = '/temporary-link';
        RuntimeDefaults::$resolvedTemporaryDirectory = '/synthetic-temp';
        RuntimeDefaults::$calls = 0;
        RuntimeDefaults::$environment = ['HOME' => '/synthetic-home'];
        RuntimeDefaults::$terminal = false;
    }

    /** Scalar skill settings compose lazily while injected boundaries remain unobserved until a mode runs. */
    public function testNativeSkillCompositionIsLazyWithExplicitAndDefaultSettings(): void
    {
        $factories = new ChangelogServiceProvider()->getFactories();
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::never())->method('readlink');
        $finders = $this->createMock(FinderFactoryInterface::class);
        $finders->expects(self::never())->method('create');
        $version = $this->createStub(PackageVersionResolverInterface::class);
        $version->method('resolve')->willReturn('1.0.0');
        foreach ([true, false] as $explicit) {
            $values = [Filesystem::class => $filesystem, ManagedFileStoreInterface::class => $this->createStub(
                ManagedFileStoreInterface::class,
            ),
                FinderFactoryInterface::class => $finders, PackagePathResolverInterface::class => new PackagePathResolver(
                    '/consumer',
                ),
                LockFactory::class => $this->createStub(
                    LockFactory::class,
                ), PackageVersionResolverInterface::class => $version];
            if ($explicit) {
                $values += ['config.changelog.skill_source_directory' => '/donor', 'config.changelog.skill_home_directory' => '/home-fixture',
                    'config.changelog.installed_reference' => null];
            }
            $container = $this->createStub(ContainerInterface::class);
            $container->method('has')->willReturnCallback(
                static fn(string $id): bool => array_key_exists($id, $values),
            );
            $container->method('get')->willReturnCallback(static fn(string $id): mixed => $values[$id]);
            self::assertInstanceOf(SkillInstaller::class, $factories[SkillInstaller::class]($container));
            self::assertSame($explicit ? 0 : 1, RuntimeDefaults::$calls);
        }
    }

    /** Human callback reads raw cwd only when selected and retains the configured inspector instance. */
    public function testWarningFactoryDefersInstallerUntilAnEligibleHumanInvocation(): void
    {
        $factories = new ChangelogServiceProvider()->getFactories();
        $installer = $this->createMock(SkillInstallerInterface::class);
        $installer->expects(self::once())->method('run')->with(
            '/chosen',
            '.agents/skills',
            false,
            'check',
            false,
        )->willReturn(
            ['state' => 'outdated'],
        );
        $version = $this->createStub(PackageVersionResolverInterface::class);
        $version->method('resolve')->willReturn('test');
        $values = [SkillInstallerInterface::class => $installer, PackageVersionResolverInterface::class => $version,
            CommandLoaderInterface::class => $this->createStub(
                CommandLoaderInterface::class,
            ), 'config.changelog.skill_warning_enabled' => true];
        $lookups = [];
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static function (string $id) use (
            &$values
        ): bool {
            return array_key_exists(
                $id,
                $values,
            );
        });
        $container->method('get')->willReturnCallback(static function (string $id) use (
            &$values,
            &$lookups,
        ): mixed {
            $lookups[] = $id;

            return $values[$id];
        });
        $values[SkillInstallationWarning::class] = $factories[SkillInstallationWarning::class]($container);
        self::assertNotContains(SkillInstallerInterface::class, $lookups);
        $app = $factories[Changelog::class]($container);
        $app->addCommand(
            new Command('check')->addOption('cwd', 'C', InputOption::VALUE_REQUIRED)->setCode(static fn(): int => 0),
        );
        $error = new BufferedOutput();
        $output = $this->createStub(ConsoleOutputInterface::class);
        $output->method('getErrorOutput')->willReturn($error);
        self::assertSame(0, $app->doRun(new ArgvInput(['changelog', 'check', '--cwd=/chosen']), $output));
        self::assertStringContainsString('older than', $error->fetch());
        self::assertSame(
            1,
            count(array_filter($lookups, static fn(string $id): bool => SkillInstallerInterface::class === $id)),
        );
        self::assertSame(0, RuntimeDefaults::$calls);
        unset($values['config.changelog.skill_warning_enabled']);
        $lookups = [];
        $warning = $factories[SkillInstallationWarning::class]($container);
        $warning->warn('/unobserved', $error);
        self::assertSame('', $error->fetch());
        self::assertNotContains(SkillInstallerInterface::class, $lookups);
        self::assertGreaterThan(0, RuntimeDefaults::$calls);
    }

    #[Test]
    public function declarationsStayLazyAndAliasesDelegateOnlyToContainer(): void
    {
        $provider = new ChangelogServiceProvider();
        $factories = $provider->getFactories();
        self::assertSame([], $provider->getExtensions());
        self::assertSame(0, RuntimeDefaults::$calls);
        foreach ([
            ReleasePlannerInterface::class,
            PublicationServiceInterface::class,
            PullRequestPolicyInterface::class,
            AutomationRunnerInterface::class,
            VersionPullRequestServiceInterface::class,
            VersionPullRequestInputFactoryInterface::class,
            ReleaseDateValidatorInterface::class,
            \FastForward\Changelog\Git\GitFragmentOriginReaderInterface::class,
            \FastForward\Changelog\Release\Reference\PullRequestReferenceResolverInterface::class,
        ] as $required) {
            self::assertArrayHasKey($required, $factories);
        }
        foreach ($factories as $factory) {
            if (!$factory instanceof AliasFactory) {
                continue;
            }
            $container = $this->createMock(ContainerInterface::class);
            $value = new \stdClass();
            $container->expects(self::once())->method('get')->willReturn($value);
            self::assertSame($value, $factory($container));
        }
    }

    /** Recovery composition must use an injected temporary root instead of reading host state in a unit test. */
    #[Test]
    public function recoveryFactoryRetainsTheExplicitTemporaryDirectory(): void
    {
        $factories = new ChangelogServiceProvider()->getFactories();
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::once())->method('journalPath')->with('/consumer')->willReturn(null);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::exactly(2))->method('get')->willReturnMap(
            [[
                GitRepositoryInterface::class,
                $git,
            ], [ChangelogServiceProvider::CONFIG, new ArrayConfig(['temporary_directory' => '/synthetic-temp'])]],
        );
        $resolver = $factories[ReleaseJournalPathResolver::class]($container);
        self::assertStringStartsWith(
            '/synthetic-temp/fast-forward-changelog/',
            $resolver->resolve(new ReleaseOptions('/consumer')),
        );
    }

    #[Test]
    public function explicitFactoriesRetainRuntimeSettingsAndInjectedTransport(): void
    {
        $factories = new ChangelogServiceProvider()->getFactories();
        $container = $this->createMock(ContainerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn(['fixture' => true]);
        $response->method('getHeaders')->willReturn([]);
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->with('GET', 'https://api.example.test/meta', [
            'headers' => ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2026-03-10',
                'User-Agent' => 'FastForward-Changelog', 'Authorization' => 'Bearer fixture-token'],
            'max_redirects' => 0,
        ])->willReturn($response);
        $timezone = new DateTimeZone('UTC');
        $container->expects(self::exactly(7))->method('get')->willReturnMap(
            [[HttpClientInterface::class, $http],
                [ChangelogServiceProvider::CONFIG, new ArrayConfig(['working_directory' => '/consumer', 'installed_version' => '1.2.3', 'token' => 'fixture-token', 'api_url' => 'https://api.example.test'])],
                [DateTimeZone::class, $timezone]],
        );
        self::assertSame('1.2.3', $factories[ComposerPackageVersionResolver::class]($container)->resolve());
        self::assertSame(
            '/consumer/file.md',
            $factories[PackagePathResolver::class]($container)->absolutePath('file.md'),
        );
        self::assertInstanceOf(ProcessFactory::class, $factories[ProcessFactory::class]($container));
        self::assertInstanceOf(HttpClientInterface::class, $factories[HttpClientInterface::class]());
        self::assertSame(['fixture' => true], $factories[GitHubClient::class]($container)->request('GET', '/meta'));
        self::assertInstanceOf(Secure::class, $factories[Randomizer::class]()->engine);
        self::assertSame('UTC', $factories[DateTimeZone::class]($container)->getName());
        self::assertInstanceOf(SystemClock::class, $factories[SystemClock::class]($container));
    }

    /** Typed autowiring honors an explicit deterministic entropy boundary instead of constructing another engine. */
    public function testIdentifierAliasRetainsConsumerRandomizerOverride(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::exactly(4))->method('generate')->willReturn("\x01\x02\x03\x04");
        $randomizer = new Randomizer($engine);
        $container = container(new ArrayServiceProvider([
            Randomizer::class => static fn(): Randomizer => $randomizer,
        ]), ChangelogServiceProvider::class);
        $generator = $container->get(IdentifierGeneratorInterface::class);

        self::assertSame($randomizer, $container->get(Randomizer::class));
        self::assertSame($generator, $container->get(RandomIdentifierGenerator::class));
        self::assertSame('change-01020304010203040102030401020304.md', $generator->generate());
        self::assertSame(0, RuntimeDefaults::$calls);
    }

    /** Aliased discovery stays lazy and a selected consumer command factory failure remains visible. */
    public function testCommandLoaderAutowiresAndPreservesDeferredFactoryFailures(): void
    {
        $calls = 0;
        $failure = new RuntimeException('Configured command graph is unavailable.');
        $container = container(new ArrayServiceProvider([
            StatusCommand::class => static function () use (&$calls, $failure): never {
                ++$calls;

                throw $failure;
            },
        ]), ChangelogServiceProvider::class);
        $loader = $container->get(CommandLoaderInterface::class);
        self::assertSame($loader, $container->get(ChangelogCommandLoader::class));
        self::assertSame(
            ['add', 'check', 'status', 'version', 'notes', 'publish', 'backfill', 'format', 'github', 'skill:install'],
            $loader->getNames(),
        );
        $command = $loader->get('status');
        self::assertSame($command, $loader->get('status'));
        self::assertSame(0, $calls);
        self::assertSame(0, RuntimeDefaults::$calls);

        try {
            $command->getDefinition();
            self::fail('The selected consumer factory must fail instead of falling back to an autowired command.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
            self::assertSame(1, $calls);
        }
    }

    /** Explicit contextual construction retains optional capabilities while aliases share the same service. */
    public function testMergedPublicationRetainsOptionalPolicyAndSharedAliases(): void
    {
        $evidence = $this->createMock(MergedPublicationEvidenceValidatorInterface::class);
        $evidence->expects(self::never())->method('validateMerged');
        $policy = $this->createMock(PullRequestPolicyInterface::class);
        $policy->expects(self::never())->method('inspectHead');
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::once())->method('request')->with('GET', '/repos/owner/project/pulls/7')->willReturn(
            null,
        );
        $container = container(new ArrayServiceProvider([
            PublicationEvidenceValidatorInterface::class => static fn(): PublicationEvidenceValidatorInterface => $evidence,
            PullRequestPolicyInterface::class => static fn(): PullRequestPolicyInterface => $policy,
            GitHubClientInterface::class => static fn(): GitHubClientInterface => $github,
        ]), ChangelogServiceProvider::class);
        $service = $container->get(PublicationServiceInterface::class);
        self::assertSame($service, $container->get(MergedPublicationServiceInterface::class));
        self::assertSame($service, $container->get(PublicationService::class));
        self::assertSame(0, RuntimeDefaults::$calls);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Publication requires the merged configured version PR');
        $service->publishMerged(
            new ReleaseOptions('/consumer', repository: 'owner/project'),
            7,
            str_repeat('a', 40),
            str_repeat('b', 40),
        );
    }

    /** Local-only validator defaults cannot silently replace the configured immutable GitHub proof capability. */
    public function testMergedEvidenceRetainsOptionalGithubReaderAndSharedAliases(): void
    {
        $merge = str_repeat('a', 40);
        $head = str_repeat('b', 40);
        $git = $this->createMockForIntersectionOfInterfaces(
            [GitRepositoryInterface::class, GitCommitTreeReaderInterface::class],
        );
        $git->expects(self::once())->method('treeSha')->with('/consumer', $merge)->willReturn(str_repeat('c', 40));
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::exactly(2))->method('request')->willReturnMap([
            ['GET', '/repos/owner/project/commits/' . $head, null, null],
            ['GET', '/repos/owner/project/git/commits/' . $merge, null, null],
        ]);
        $paths = $this->createStub(PackagePathResolverInterface::class);
        $files = $this->createMock(ManagedFileStoreInterface::class);
        $files->expects(self::never())->method('read');
        $files->expects(self::never())->method('write');
        $container = container(new ArrayServiceProvider([
            GitRepositoryInterface::class => static fn(): GitRepositoryInterface => $git,
            GitHubClientInterface::class => static fn(): GitHubClientInterface => $github,
            PackagePathResolverInterface::class => static fn(): PackagePathResolverInterface => $paths,
            ManagedFileStoreInterface::class => static fn(): ManagedFileStoreInterface => $files,
        ]), ChangelogServiceProvider::class);
        $validator = $container->get(MergedPublicationEvidenceValidatorInterface::class);

        self::assertSame($validator, $container->get(PublicationEvidenceValidatorInterface::class));
        self::assertSame($validator, $container->get(PublicationEvidenceValidator::class));
        self::assertSame(0, RuntimeDefaults::$calls);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The immutable approved head and merge must have identical complete Git trees.');
        $validator->validateMerged(new ReleaseOptions('/consumer', repository: 'owner/project'), $merge, $head);
    }

    /** The output factory captures only the caller-supplied path and injected filesystem. */
    public function testGitHubOutputFactoryRetainsExplicitRunnerPath(): void
    {
        $factories = new ChangelogServiceProvider()->getFactories();
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())->method('appendToFile')->with(
            '/runner/output',
            "result={\"status\":\"valid\"}\nstatus=valid\n",
            true,
        );
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::exactly(2))->method('get')->willReturnMap(
            [[
                Filesystem::class,
                $filesystem,
            ], [ChangelogServiceProvider::CONFIG, new ArrayConfig(['github_output_file' => '/runner/output'])]],
        );
        self::assertSame(
            '{"status":"valid"}',
            $factories[GitHubOutputWriter::class]($container)->write(['status' => 'valid']),
        );
    }

    /** Class-name registration resolves defaults lazily and shares one captured configuration. */
    public function testClassNameRegistrationUsesDefaultsWithoutConstructorArguments(): void
    {
        $container = container(ChangelogServiceProvider::class);
        self::assertSame(0, RuntimeDefaults::$calls);
        $settings = $container->get(ChangelogServiceProvider::CONFIG);
        self::assertSame([
            'working_directory' => '/consumer', 'temporary_directory' => '/synthetic-temp',
            'installed_version' => null, 'token' => '', 'api_url' => 'https://api.github.com',
            'github_output_file' => null,
        ], $settings->toArray());
        self::assertSame($settings, $container->get(ChangelogServiceProvider::CONFIG));
        self::assertSame(3, RuntimeDefaults::$calls);
        self::assertSame('/consumer/file.md', $container->get(PackagePathResolver::class)->absolutePath('file.md'));
    }

    /** Config overrides, including explicit null/empty values, work in either initializer order without host reads. */
    public function testConfigInitializerOverridesAllDefaultsInEitherOrder(): void
    {
        $values = [
            'working_directory' => '/configured', 'temporary_directory' => '/configured-temp',
            'installed_version' => null, 'token' => '', 'api_url' => 'https://api.example.test',
            'github_output_file' => null,
        ];
        $config = config(['changelog' => $values]);
        foreach ([
            container($config, ChangelogServiceProvider::class),
            container(ChangelogServiceProvider::class, $config),
        ] as $container) {
            self::assertSame($values, $container->get(ChangelogServiceProvider::CONFIG)->toArray());
            self::assertSame(
                '/configured/file.md',
                $container->get(PackagePathResolver::class)->absolutePath('file.md'),
            );
            self::assertSame(
                '/configured',
                $container->get(ProcessFactory::class)->create(['git', 'status'])->getWorkingDirectory(),
            );
        }
        self::assertSame(0, RuntimeDefaults::$calls);
    }

    /** A partial config preserves default settings and handles a noncanonical temporary directory. */
    public function testPartialConfigRetainsDefaultsAndTemporaryFallback(): void
    {
        RuntimeDefaults::$resolvedTemporaryDirectory = false;
        $container = container(
            config(['changelog' => ['working_directory' => '/configured']]),
            ChangelogServiceProvider::class,
        );
        $settings = $container->get(ChangelogServiceProvider::CONFIG);
        self::assertSame('/configured', $settings->get('working_directory'));
        self::assertSame('/temporary-link', $settings->get('temporary_directory'));
        self::assertSame('', $settings->get('token'));
        self::assertSame(3, RuntimeDefaults::$calls);
    }

    /** Missing process cwd fails clearly before services receive a relative composition root. */
    public function testUnavailableWorkingDirectoryFailsClearly(): void
    {
        RuntimeDefaults::$workingDirectory = false;
        $factories = new ChangelogServiceProvider()->getFactories();
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('has')->with('config.changelog.working_directory')->willReturn(false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not resolve the changelog working directory.');
        $factories[ChangelogServiceProvider::CONFIG]($container);
    }
}
