<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Command;

use FastForward\Changelog\Console\Command\PublishCommand;
use FastForward\Changelog\Console\Input\ReleaseInput;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Publication\PublicationResult;
use FastForward\Changelog\Publication\PublicationServiceInterface;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Tests\Console\PlanFixtureTrait;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PublishCommand::class)]
#[UsesClass(ReleaseInput::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(PublicationResult::class)]
final class PublishCommandTest extends TestCase
{
    use PlanFixtureTrait;

    #[Test]
    #[DataProvider('targets')]
    public function resolvesCompleteIdentityBeforePublication(?string $target, bool $dry): void
    {
        $options = $this->options();
        $sha = str_repeat('a', 40);
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willReturn($options);
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::once())->method('resolveRef')->with('/consumer', $target ?? 'HEAD')->willReturn($sha);
        $publication = $this->createMock(PublicationServiceInterface::class);
        $result = new PublicationResult(
            $dry ? 'planned' : 'published',
            '1.1.0',
            'v1.1.0',
            $sha,
            'https://example.test/release',
            [
                'create_release',

            ]);
        $publication->expects(self::once())->method('publish')->with($options, $sha, $dry)->willReturn($result);
        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('writeln')->with(
            json_encode($result->summary(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            OutputInterface::OUTPUT_RAW,
        );
        self::assertSame(
            0,
            new PublishCommand($factory, $git, $publication)($this->settings(), $output, $target, $dry),
        );
    }

    public static function targets(): array
    {
        return [[null,false],['release-commit',true]];
    }

    /** Short target and dry-run flags resolve the approved identity while retaining read-only intent. */
    public function testShortTargetAndDryRunPreserveApprovedIdentity(): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willReturn($this->options());
        $sha = str_repeat('a', 40);
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::once())->method('resolveRef')->with('/consumer', 'approved')->willReturn($sha);
        $publication = $this->createMock(PublicationServiceInterface::class);
        $publication->expects(self::once())->method('publish')->with($this->options(), $sha, true)
            ->willReturn(new PublicationResult('planned', '1.0.0', 'v1.0.0', $sha, null, []));
        $tester = new CommandTester(new Command(null, new PublishCommand($factory, $git, $publication)));
        self::assertSame(
            0,
            $tester->execute(['-C' => '/consumer', '-t' => 'approved', '-d' => true], ['interactive' => false]),
        );
        self::assertStringContainsString('"state":"planned"', $tester->getDisplay(true));
    }

    #[Test]
    public function invalidRevisionNeverReachesPublication(): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willReturn($this->options());
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::once())->method('resolveRef')->willThrowException(
            new InvalidArgumentException('invalid revision'),
        );
        $publication = $this->createMock(PublicationServiceInterface::class);
        $publication->expects(self::never())->method('publish');
        self::assertSame(
            2,
            new PublishCommand($factory, $git, $publication)($this->settings(), $this->createStub(
                OutputInterface::class,
            ), '--all'),
        );
    }

    #[Test]
    public function publicationFailureUsesErrorChannel(): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willReturn($this->options());
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::once())->method('resolveRef')->willReturn(str_repeat('a', 40));
        $publication = $this->createMock(PublicationServiceInterface::class);
        $publication->expects(self::once())->method('publish')->willThrowException(
            new RuntimeException('conflicting tag'),
        );
        $output = $this->createMock(ConsoleOutputInterface::class);
        $error = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('getErrorOutput')->willReturn($error);
        $error->expects(self::once())->method('writeln')->with('<error>conflicting tag</error>');
        self::assertSame(1, new PublishCommand($factory, $git, $publication)($this->settings(), $output));
    }
}
