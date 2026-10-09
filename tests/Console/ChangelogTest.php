<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console;

use FastForward\Changelog\Automation\AutomationRunnerInterface;
use FastForward\Changelog\Console\Changelog;
use FastForward\Changelog\Console\Command\GitHubCommand;
use FastForward\Changelog\Console\GitHubOutputWriterInterface;
use FastForward\Changelog\Console\Input\GitHubInput;
use FastForward\Changelog\Console\Input\ReleaseInput;
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
