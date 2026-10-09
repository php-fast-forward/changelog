<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Command;

use FastForward\Changelog\Console\Command\AddCommand;
use FastForward\Changelog\Console\Input\ReleaseInput;
use FastForward\Changelog\Fragment\FragmentWriterInterface;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Tests\Console\PlanFixtureTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AddCommand::class)]
#[UsesClass(ReleaseInput::class)]
#[UsesClass(ReleaseOptions::class)]
final class AddCommandTest extends TestCase
{
    use PlanFixtureTrait;

    #[Test]
    public function forwardsEveryExplicitMetadataValueAndCommitSetting(): void
    {
        $settings = $this->settings();
        $options = $this->options();
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->with($settings->values())->willReturn($options);
        $writer = $this->createMock(FragmentWriterInterface::class);
        $writer->expects(self::once())->method('add')->with(
            $options,
            'Exact **Markdown**',
            'fixed',
            'major',
            'one.md',
            21,
            34,
            'alice',
            true,
            'record one',
        )
            ->willReturn('/consumer/.changelog/one.md');
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects(self::once())->method('success')->with('Created fragment: /consumer/.changelog/one.md');
        self::assertSame(0, new AddCommand($factory, $writer)(
            $settings,
            $this->createStub(InputInterface::class),
            $io,
            'Exact **Markdown**',
            'fixed',
            'major',
            'one.md',
            '21',
            '34',
            'alice',
            true,
            'record one'
        ));
    }

    #[Test]
    public function noninteractiveMissingMessageFailsBeforeOptionsOrMutation(): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::never())->method('create');
        $writer = $this->createMock(FragmentWriterInterface::class);
        $writer->expects(self::never())->method('add');
        $tester = new CommandTester(new Command(null, new AddCommand($factory, $writer)));
        self::assertSame(2, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('Provide a change message argument', $tester->getDisplay(true));
    }

    #[Test]
    public function interactiveMessageUsesSymfonyStyleValidation(): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willReturn($this->options());
        $writer = $this->createMock(FragmentWriterInterface::class);
        $writer->expects(self::once())->method('add')->with(
            $this->options(),
            'Typed message',
            'changed',
            null,
            null,
            null,
            null,
            null,
            false,
            'chore: record changelog fragment',
        )->willReturn(
            '/consumer/.changelog/generated.md',
        );
        $tester = new CommandTester(new Command(null, new AddCommand($factory, $writer)));
        $tester->setInputs(['', 'Typed message']);
        self::assertSame(0, $tester->execute([], ['interactive' => true]));
        self::assertStringContainsString('meaningful text', $tester->getDisplay(true));
    }

    #[Test]
    #[DataProvider('invalidReferences')]
    public function rejectsInvalidReferencesBeforeWriter(string $reference): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willReturn($this->options());
        $writer = $this->createMock(FragmentWriterInterface::class);
        $writer->expects(self::never())->method('add');
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('getErrorStyle')->willReturn($io);
        $io->expects(self::once())->method('error');
        self::assertSame(
            2,
            new AddCommand($factory, $writer)($this->settings(), $this->createStub(
                InputInterface::class,
            ), $io, 'Message', issue: $reference),
        );
    }

    public static function invalidReferences(): array
    {
        return [['0'], ['-1'], ['oops'], ['999999999999999999999999999']];
    }

    #[Test]
    public function failedWriteKeepsOperationalFailureSeparateFromInvalidInput(): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willReturn($this->options());
        $writer = $this->createMock(FragmentWriterInterface::class);
        $writer->expects(self::once())->method('add')->willThrowException(
            new RuntimeException('exclusive write failed'),
        );
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('getErrorStyle')->willReturn($io);
        $io->expects(self::once())->method('error')->with('exclusive write failed');
        self::assertSame(
            1,
            new AddCommand($factory, $writer)($this->settings(), $this->createStub(
                InputInterface::class,
            ), $io, 'Message'),
        );
    }
}
