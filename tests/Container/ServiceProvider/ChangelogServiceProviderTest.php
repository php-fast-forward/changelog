<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Container\ServiceProvider;

use DateTimeZone;
use FastForward\Changelog\Automation\AutomationRunnerInterface;
use FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestExceptionFactoryInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestInputFactoryInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestResultFactoryInterface;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestServiceInterface;
use FastForward\Changelog\Console\CommandLoader\ChangelogCommandLoader;
use FastForward\Changelog\Console\CommandLoader\Factory\LazyCommandFactoryInterface;
use FastForward\Changelog\Console\GitHubOutputWriter;
use FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider;
use FastForward\Changelog\Date\Factory\TimezoneFactory;
use FastForward\Changelog\Filesystem\PackagePathResolver;
use FastForward\Changelog\Fragment\Factory\IdentifierGeneratorFactoryInterface;
use FastForward\Changelog\Fragment\IdentifierGeneratorInterface;
use FastForward\Changelog\Git\Factory\ProcessFactory;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\Factory\GitHubClientFactoryInterface;
use FastForward\Changelog\GitHub\Factory\HttpClientFactoryInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Publication\PublicationServiceInterface;
use FastForward\Changelog\Release\ReleaseJournalPathResolver;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use FastForward\Changelog\Validator\ReleaseDateValidatorInterface;
use FastForward\Changelog\Version\ComposerPackageVersionResolver;
use FastForward\Clock\SystemClock;
use FastForward\Container\Factory\AliasFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
    #[Test]
    public function declarationsStayLazyAndAliasesDelegateOnlyToContainer(): void
    {
        $provider = new ChangelogServiceProvider('/consumer', '1.2.3', 'fixture-token', 'https://api.example.test', temporaryDirectory: '/synthetic-temp');
        $factories = $provider->getFactories();
        self::assertSame([], $provider->getExtensions());
        foreach ([ReleasePlannerInterface::class,PublicationServiceInterface::class,PullRequestPolicyInterface::class,AutomationRunnerInterface::class,VersionPullRequestServiceInterface::class,VersionPullRequestInputFactoryInterface::class,VersionPullRequestResultFactoryInterface::class,VersionPullRequestExceptionFactoryInterface::class,ReleaseDateValidatorInterface::class] as $required) {
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
        $factories = new ChangelogServiceProvider('/consumer', temporaryDirectory: '/synthetic-temp')->getFactories();
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::once())->method('journalPath')->with('/consumer')->willReturn(null);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with(GitRepositoryInterface::class)->willReturn($git);
        $resolver = $factories[ReleaseJournalPathResolver::class]($container);
        self::assertStringStartsWith('/synthetic-temp/fast-forward-changelog/', $resolver->resolve(new ReleaseOptions('/consumer')));
    }

    #[Test]
    public function explicitFactoriesUseOnlyConstructorValuesAndInjectedFactories(): void
    {
        $factories = new ChangelogServiceProvider('/consumer', '1.2.3', 'fixture-token', 'https://api.example.test', temporaryDirectory: '/synthetic-temp')->getFactories();
        self::assertSame('1.2.3', $factories[ComposerPackageVersionResolver::class]()->resolve());
        self::assertSame('/consumer/file.md', $factories[PackagePathResolver::class]()->absolutePath('file.md'));
        self::assertInstanceOf(ProcessFactory::class, $factories[ProcessFactory::class]());
        $container = $this->createMock(ContainerInterface::class);
        $http = $this->createStub(HttpClientInterface::class);
        $httpFactory = $this->createMock(HttpClientFactoryInterface::class);
        $httpFactory->expects(self::once())->method('create')->willReturn($http);
        $github = $this->createStub(GitHubClientInterface::class);
        $githubFactory = $this->createMock(GitHubClientFactoryInterface::class);
        $githubFactory->expects(self::once())->method('create')->with('fixture-token', 'https://api.example.test')->willReturn($github);
        $identifier = $this->createStub(IdentifierGeneratorInterface::class);
        $identifiers = $this->createMock(IdentifierGeneratorFactoryInterface::class);
        $identifiers->expects(self::once())->method('create')->willReturn($identifier);
        $lazy = $this->createMock(LazyCommandFactoryInterface::class);
        $lazy->expects(self::never())->method('create');
        $timezone = new DateTimeZone('UTC');
        $container->expects(self::exactly(6))->method('get')->willReturnMap([[HttpClientFactoryInterface::class,$httpFactory],[GitHubClientFactoryInterface::class,$githubFactory],[IdentifierGeneratorFactoryInterface::class,$identifiers],
            [TimezoneFactory::class,new TimezoneFactory()],[DateTimeZone::class,$timezone],[LazyCommandFactoryInterface::class,$lazy]]);
        self::assertSame($http, $factories[HttpClientInterface::class]($container));
        self::assertSame($github, $factories[GitHubClientInterface::class]($container));
        self::assertSame($identifier, $factories[IdentifierGeneratorInterface::class]($container));
        self::assertSame('UTC', $factories[DateTimeZone::class]($container)->getName());
        self::assertInstanceOf(SystemClock::class, $factories[SystemClock::class]($container));
        self::assertSame(['add','check','status','version','notes','publish','backfill','format','github'], $factories[CommandLoaderInterface::class]($container)->getNames());
    }

    /** The output factory captures only the caller-supplied path and injected filesystem. */
    public function testGitHubOutputFactoryRetainsExplicitRunnerPath(): void
    {
        $factories = new ChangelogServiceProvider('/consumer', githubOutputFile: '/runner/output')->getFactories();
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())->method('appendToFile')->with('/runner/output', "result={\"status\":\"valid\"}\nstatus=valid\n", true);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with(Filesystem::class)->willReturn($filesystem);
        self::assertSame('{"status":"valid"}', $factories[GitHubOutputWriter::class]($container)->write(['status' => 'valid']));
    }
}
