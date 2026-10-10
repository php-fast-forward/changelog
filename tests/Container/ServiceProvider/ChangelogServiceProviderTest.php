<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Container\ServiceProvider;

use DateTimeZone;
use FastForward\Changelog\Automation\AutomationRunnerInterface;
use FastForward\Changelog\Automation\Output\GitHubOutputWriter;
use FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestExceptionFactoryInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestInputFactoryInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestResultFactoryInterface;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestServiceInterface;
use FastForward\Changelog\Console\CommandLoader\ChangelogCommandLoader;
use FastForward\Changelog\Console\CommandLoader\Factory\LazyCommandFactoryInterface;
use FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider;
use FastForward\Changelog\Date\Factory\TimezoneFactory;
use FastForward\Changelog\Filesystem\PackagePathResolver;
use FastForward\Changelog\Fragment\Factory\IdentifierGeneratorFactoryInterface;
use FastForward\Changelog\Fragment\IdentifierGeneratorInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\Factory\GitHubClientFactoryInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Http\Factory\HttpClientFactoryInterface;
use FastForward\Changelog\Process\Factory\ProcessFactory;
use FastForward\Changelog\Publication\PublicationServiceInterface;
use FastForward\Changelog\Release\ReleaseJournalPathResolver;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use FastForward\Changelog\Tests\Container\ServiceProvider\Fixture\RuntimeDefaults;
use FastForward\Changelog\Validator\ReleaseDateValidatorInterface;
use FastForward\Changelog\Version\ComposerPackageVersionResolver;
use FastForward\Clock\SystemClock;
use FastForward\Config\ArrayConfig;
use FastForward\Container\Factory\AliasFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function FastForward\Config\config;
use function FastForward\Container\container;

require_once __DIR__ . '/Fixture/runtime.php';

#[CoversClass(ChangelogServiceProvider::class)]
#[UsesClass(ChangelogCommandLoader::class)]
#[UsesClass(PackagePathResolver::class)]
#[UsesClass(ProcessFactory::class)]
#[UsesClass(ComposerPackageVersionResolver::class)]
#[UsesClass(TimezoneFactory::class)]
#[UsesClass(ReleaseJournalPathResolver::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(GitHubOutputWriter::class)]
final class ChangelogServiceProviderTest extends TestCase
{
    /** Resets synthetic defaults before each test; unit tests never observe the real host. */
    protected function setUp(): void
    {
        RuntimeDefaults::$workingDirectory = '/consumer';
        RuntimeDefaults::$temporaryDirectory = '/temporary-link';
        RuntimeDefaults::$resolvedTemporaryDirectory = '/synthetic-temp';
        RuntimeDefaults::$calls = 0;
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
            VersionPullRequestResultFactoryInterface::class,
            VersionPullRequestExceptionFactoryInterface::class,
            ReleaseDateValidatorInterface::class,
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
    public function explicitFactoriesUseOnlyContainerValuesAndInjectedFactories(): void
    {
        $factories = new ChangelogServiceProvider()->getFactories();
        $container = $this->createMock(ContainerInterface::class);
        $http = $this->createStub(HttpClientInterface::class);
        $httpFactory = $this->createMock(HttpClientFactoryInterface::class);
        $httpFactory->expects(self::once())->method('create')->willReturn($http);
        $github = $this->createStub(GitHubClientInterface::class);
        $githubFactory = $this->createMock(GitHubClientFactoryInterface::class);
        $githubFactory->expects(self::once())->method('create')->with(
            'fixture-token',
            'https://api.example.test',
        )->willReturn(
            $github,
        );
        $identifier = $this->createStub(IdentifierGeneratorInterface::class);
        $identifiers = $this->createMock(IdentifierGeneratorFactoryInterface::class);
        $identifiers->expects(self::once())->method('create')->willReturn($identifier);
        $lazy = $this->createMock(LazyCommandFactoryInterface::class);
        $lazy->expects(self::never())->method('create');
        $timezone = new DateTimeZone('UTC');
        $container->expects(self::exactly(11))->method('get')->willReturnMap(
            [[HttpClientFactoryInterface::class,$httpFactory],[GitHubClientFactoryInterface::class,$githubFactory],[
                IdentifierGeneratorFactoryInterface::class,
                $identifiers,
            ],
                [ChangelogServiceProvider::CONFIG, new ArrayConfig(['working_directory' => '/consumer', 'installed_version' => '1.2.3', 'token' => 'fixture-token', 'api_url' => 'https://api.example.test'])],
                [TimezoneFactory::class,new TimezoneFactory()],[DateTimeZone::class,$timezone],[
                    LazyCommandFactoryInterface::class,
                    $lazy,
                ]],
        );
        self::assertSame('1.2.3', $factories[ComposerPackageVersionResolver::class]($container)->resolve());
        self::assertSame(
            '/consumer/file.md',
            $factories[PackagePathResolver::class]($container)->absolutePath('file.md'),
        );
        self::assertInstanceOf(ProcessFactory::class, $factories[ProcessFactory::class]($container));
        self::assertSame($http, $factories[HttpClientInterface::class]($container));
        self::assertSame($github, $factories[GitHubClientInterface::class]($container));
        self::assertSame($identifier, $factories[IdentifierGeneratorInterface::class]($container));
        self::assertSame('UTC', $factories[DateTimeZone::class]($container)->getName());
        self::assertInstanceOf(SystemClock::class, $factories[SystemClock::class]($container));
        self::assertSame([
            'add','check','status','version','notes','publish','backfill','format','github'],
            $factories[CommandLoaderInterface::class]($container)->getNames(),
        );
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
