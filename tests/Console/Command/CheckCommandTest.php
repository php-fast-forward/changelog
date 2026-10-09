<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Command;

use FastForward\Changelog\Automation\Policy\PullRequestAuthorization;
use FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface;
use FastForward\Changelog\Console\Command\CheckCommand;
use FastForward\Changelog\Console\Input\ReleaseInput;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Tests\Console\PlanFixtureTrait;
use FastForward\Changelog\Validation\CheckServiceInterface;
use FastForward\Changelog\Validation\ValidationReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Style\SymfonyStyle;

#[CoversClass(CheckCommand::class)]
#[UsesClass(ReleaseInput::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(PullRequestAuthorization::class)]
#[UsesClass(ValidationReport::class)]
final class CheckCommandTest extends TestCase
{
    use PlanFixtureTrait;

    #[Test]
    public function localCheckNeverQueriesPolicyOrGit(): void
    {
        [$command, $checks, $policy, $git, $io] = $this->compose();
        $checks->expects(self::once())->method('check')->with($this->options(), null, false, false)->willReturn(
            new ValidationReport([], [], false),
        );
        $policy->expects(self::never())->method('inspect');
        $git->expects(self::never())->method('resolveRef');
        $io->expects(self::once())->method('success')->with('Validated 0 pending fragments.');
        self::assertSame(0, $command($this->settings(), $io));
    }

    #[Test]
    public function trustedAuthorizationIsBoundToTheInspectedCheckout(): void
    {
        [$command, $checks, $policy, $git, $io] = $this->compose();
        $sha = str_repeat('a', 40);
        $policy->expects(self::once())->method('inspect')->with($this->options(), 21)->willReturn(
            new PullRequestAuthorization(true, true, 'maintenance', ['human grant verified'], $sha),
        );
        $git->expects(self::once())->method('resolveRef')->with('/consumer')->willReturn($sha);
        $io->expects(self::once())->method('note')->with('human grant verified');
        $checks->expects(self::once())->method('check')->with($this->options(), 'main', true, true)->willReturn(
            new ValidationReport([], [], true),
        );
        self::assertSame(0, $command($this->settings(), $io, 'main', '21'));
    }

    #[Test]
    #[DataProvider('invalidHead')]
    public function unknownOrDifferentCheckoutNeverReceivesAuthorization(?string $head): void
    {
        [$command, $checks, $policy, $git, $io] = $this->compose();
        $policy->expects(self::once())->method('inspect')->willReturn(
            new PullRequestAuthorization(true, true, 'waiver', ['authority requires matching evidence'], $head),
        );
        $git->expects(null === $head ? self::never() : self::once())->method('resolveRef')->willReturn(
            str_repeat('a', 40),
        );
        $checks->expects(self::never())->method('check');
        $io->expects(self::once())->method('note')->with('authority requires matching evidence');
        $io->expects(self::once())->method('error')->with(
            null === $head ? 'Pull-request policy could not establish an inspected head identity.' : 'Check out the inspected pull-request head before applying its authorization.',
        );
        self::assertSame(1, $command($this->settings(), $io, 'main', '21'));
    }

    public static function invalidHead(): array
    {
        return [[null],[str_repeat('b', 40)]];
    }

    #[Test]
    #[DataProvider('invalidInputs')]
    public function rejectsInvalidPrContextBeforeInspectingRemote(?string $since, string $number): void
    {
        [$command, $checks, $policy, $git, $io] = $this->compose();
        $policy->expects(self::never())->method('inspect');
        $checks->expects(self::never())->method('check');
        $git->expects(self::never())->method('resolveRef');
        $io->expects(self::once())->method('error');
        self::assertSame(2, $command($this->settings(), $io, $since, $number));
    }

    public static function invalidInputs(): array
    {
        return [['main','0'],['main','invalid'],[null,'21']];
    }

    #[Test]
    public function invalidFragmentsStillFailWithCompleteDiagnostics(): void
    {
        [$command, $checks, $policy, $git, $io] = $this->compose();
        $policy->expects(self::never())->method('inspect');
        $git->expects(self::never())->method('resolveRef');
        $checks->expects(self::once())->method('check')->willReturn(
            new ValidationReport([], ['bad.md' => ['Invalid category','Missing description']], false),
        );
        $io->expects(self::once())->method('error')->with('{"bad.md":["Invalid category","Missing description"]}');
        self::assertSame(1, $command($this->settings(), $io, 'main'));
    }

    #[Test]
    public function serviceFailureProducesControlledFailure(): void
    {
        [$command, $checks, $policy, $git, $io] = $this->compose();
        $policy->expects(self::never())->method('inspect');
        $git->expects(self::never())->method('resolveRef');
        $checks->expects(self::once())->method('check')->willThrowException(new RuntimeException('Git failed'));
        $io->expects(self::once())->method('error')->with('Git failed');
        self::assertSame(1, $command($this->settings(), $io));
    }

    private function compose(): array
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willReturn($this->options());
        $checks = $this->createMock(CheckServiceInterface::class);
        $policy = $this->createMock(PullRequestPolicyInterface::class);
        $git = $this->createMock(GitRepositoryInterface::class);
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('getErrorStyle')->willReturn($io);

        return [new CheckCommand($factory, $checks, $policy, $git),$checks,$policy,$git,$io];
    }
}
