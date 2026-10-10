<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console;

use FastForward\Changelog\Automation\AutomationRunnerInterface;
use FastForward\Changelog\Automation\Output\GitHubOutputWriterInterface;
use FastForward\Changelog\Console\Changelog;
use FastForward\Changelog\Console\Command\AddCommand;
use FastForward\Changelog\Console\Command\GitHubCommand;
use FastForward\Changelog\Console\Input\GitHubInput;
use FastForward\Changelog\Console\Input\ReleaseInput;
use FastForward\Changelog\Console\Normalizer\LineAnswerNormalizer;
use FastForward\Changelog\Fragment\FragmentWriterInterface;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Version\PackageVersionResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

#[CoversClass(Changelog::class)]
#[UsesClass(GitHubCommand::class)]
#[UsesClass(GitHubInput::class)]
#[UsesClass(ReleaseInput::class)]
#[UsesClass(AddCommand::class)]
#[UsesClass(LineAnswerNormalizer::class)]
final class ChangelogTest extends TestCase
{
    #[Test]
    public function applicationMetadataUsesOnlyInjectedPackageVersion(): void
    {
        $version = $this->createMock(PackageVersionResolverInterface::class);
        $version->expects(self::once())->method('resolve')->willReturn('9.8.7');
        $loader = $this->createMock(CommandLoaderInterface::class);
        $loader->expects(self::never())->method('get');
        $app = new Changelog($loader, $version);
        self::assertSame('Fast Forward Changelog', $app->getName());
        self::assertSame('9.8.7', $app->getVersion());
    }

    /** Syntax rejection happens before services run and must still produce the machine error contract. */
    #[DataProvider('invalidSyntax')]
    public function testGitHubParserFailuresAreJsonWithInvalidExit(array $arguments): void
    {
        $runner = $this->createMock(AutomationRunnerInterface::class);
        $runner->expects(self::never())->method('run');
        $writer = $this->createMock(GitHubOutputWriterInterface::class);
        $writer->expects(self::never())->method('write');
        $input = new ArgvInput(['changelog', ...$arguments]);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        self::assertSame(2, $this->application($runner, $writer)->doRun($input, $output));
        self::assertIsString(json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR)['error']);
    }

    /** Uses real argv binding for absent arguments, unknown options and missing option values. */
    public static function invalidSyntax(): array
    {
        return [[['github']], [['github', 'check', '--unknown-option']], [['github', 'check', '--since']]];
    }

    /** Actual console outputs receive parser diagnostics on stderr without any result write. */
    public function testGitHubParserErrorsUseTheErrorChannel(): void
    {
        $runner = $this->createMock(AutomationRunnerInterface::class);
        $runner->expects(self::never())->method('run');
        $writer = $this->createMock(GitHubOutputWriterInterface::class);
        $writer->expects(self::never())->method('write');
        $error = new BufferedOutput();
        $output = $this->createMock(ConsoleOutputInterface::class);
        $output->expects(self::never())->method('writeln');
        $output->expects(self::once())->method('getErrorOutput')->willReturn($error);
        $input = new ArgvInput(['changelog', 'github', 'check', '--unknown-option']);
        $input->setInteractive(false);
        self::assertSame(2, $this->application($runner, $writer)->doRun($input, $output));
        self::assertIsString(json_decode($error->fetch(), true, 512, JSON_THROW_ON_ERROR)['error']);
    }

    /** The application boundary preserves a valid command's normal result and status. */
    public function testValidGitHubInvocationKeepsTheCommandResult(): void
    {
        $runner = $this->createMock(AutomationRunnerInterface::class);
        $runner->expects(self::once())->method('run')->with(
            'check',
            new GitHubInput()->values() + ['since' => 'base'],
        )->willReturn(
            ['status' => 'valid'],
        );
        $writer = $this->createMock(GitHubOutputWriterInterface::class);
        $writer->expects(self::once())->method('write')->with(['status' => 'valid'])->willReturn('{"status":"valid"}');
        $input = new ArgvInput(['changelog', 'github', 'check', '--since=base']);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        self::assertSame(0, $this->application($runner, $writer)->doRun($input, $output));
        self::assertSame(['status' => 'valid'], json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR));
    }

    /** Other command parser errors retain Symfony's existing presentation and exception behavior. */
    public function testOrdinaryParserErrorsAreNotConvertedToGitHubJson(): void
    {
        $app = $this->application(
            $this->createStub(AutomationRunnerInterface::class),
            $this->createStub(GitHubOutputWriterInterface::class),
        );
        $app->addCommand(new Command('ordinary'));
        $input = new ArgvInput(['changelog', 'ordinary', '--unknown-option']);
        $input->setInteractive(false);
        $this->expectException(RuntimeException::class);
        $app->doRun($input, new BufferedOutput());
    }

    /** Native missing-message parsing rejects unattended add before settings or fragment writes, with exit 2. */
    public function testAddMissingMessageKeepsInvalidExitWithHumanDiagnostics(): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::never())->method('create');
        $writer = $this->createMock(FragmentWriterInterface::class);
        $writer->expects(self::never())->method('add');
        $app = $this->application(
            $this->createStub(AutomationRunnerInterface::class),
            $this->createStub(GitHubOutputWriterInterface::class),
        );
        $app->addCommand(new Command('add', new AddCommand($factory, $writer)));
        $input = new ArgvInput(['changelog', 'add', '--no-interaction']);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        self::assertSame(2, $app->doRun($input, $output));
        self::assertStringContainsString('Not enough arguments (missing: "message").', $output->fetch());
    }

    /** A console's error channel carries required-input diagnostics instead of machine JSON or stdout. */
    public function testAddMissingMessageUsesStderr(): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::never())->method('create');
        $writer = $this->createMock(FragmentWriterInterface::class);
        $writer->expects(self::never())->method('add');
        $app = $this->application(
            $this->createStub(AutomationRunnerInterface::class),
            $this->createStub(GitHubOutputWriterInterface::class),
        );
        $app->addCommand(new Command('add', new AddCommand($factory, $writer)));
        $error = new BufferedOutput();
        $output = $this->createMock(ConsoleOutputInterface::class);
        $output->expects(self::never())->method('writeln');
        $output->expects(self::once())->method('getErrorOutput')->willReturn($error);
        $input = new ArgvInput(['changelog', 'add', '--no-interaction']);
        $input->setInteractive(false);
        self::assertSame(2, $app->doRun($input, $output));
        self::assertStringContainsString('missing: "message"', $error->fetch());
    }

    /** Composes only injected service doubles and value objects; doRun avoids host environment configuration. */
    private function application(AutomationRunnerInterface $runner, GitHubOutputWriterInterface $writer): Changelog
    {
        $version = $this->createStub(PackageVersionResolverInterface::class);
        $version->method('resolve')->willReturn('test');
        $app = new Changelog($this->createStub(CommandLoaderInterface::class), $version);
        $app->addCommand(new Command('github', new GitHubCommand($runner, $writer)));

        return $app;
    }
}
