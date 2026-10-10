<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Command;

use FastForward\Changelog\Console\Command\AddCommand;
use FastForward\Changelog\Console\Input\ReleaseInput;
use FastForward\Changelog\Console\Normalizer\LineAnswerNormalizer;
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
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AddCommand::class)]
#[UsesClass(ReleaseInput::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(LineAnswerNormalizer::class)]
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
            $io,
            $settings,
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
        $this->expectException(ConsoleRuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "message").');
        $tester->execute([], ['interactive' => false]);
    }

    /** Short metadata and shared flags bind to their long-name values without implicitly committing. */
    public function testShortOptionsBindSettingsAndMetadataWithoutCommitConsent(): void
    {
        $settings = new ReleaseInput();
        $settings->workingDirectory = '/consumer';
        $settings->locale = 'pt-BR';
        $settings->template = 'custom.php';
        $settings->baseRef = 'main';
        $settings->repository = 'fixture/changelog';
        $settings->source = 'tags';
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->with($settings->values())->willReturn($this->options());
        $writer = $this->createMock(FragmentWriterInterface::class);
        $writer->expects(self::once())->method('add')->with(
            $this->options(), 'Short **Markdown**', 'fixed', 'patch', 'short.md', 17, 19, 'fixture', false, 'explicit message',
        )->willReturn('/consumer/.changelog/short.md');
        $tester = new CommandTester(new Command(null, new AddCommand($factory, $writer)));
        self::assertSame(0, $tester->execute([
            'message' => 'Short **Markdown**', '-C' => '/consumer', '-l' => 'pt-BR', '-T' => 'custom.php',
            '-b' => 'main', '-r' => 'fixture/changelog', '-s' => 'tags', '-c' => 'fixed', '-t' => 'patch',
            '-f' => 'short.md', '-i' => '17', '-p' => '19', '-a' => 'fixture', '-m' => 'explicit message',
        ], ['interactive' => false]));
    }

    #[Test]
    public function nativeAskRetriesBlankAnswersAndPreservesMarkdownSpaces(): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willReturn($this->options());
        $writer = $this->createMock(FragmentWriterInterface::class);
        $writer->expects(self::once())->method('add')->with(
            $this->options(),
            '  Typed message  ',
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
        $tester->setInputs(['', '   ', "\t", '  Typed message  ']);
        $status = $tester->execute([], ['interactive' => true]);
        self::assertSame(0, $status, $tester->getDisplay(true));
        self::assertStringContainsString('meaningful text', $tester->getDisplay(true));
    }

    /** A zero description is meaningful text and survives native constraint validation unchanged. */
    public function testNativeConstraintAcceptsZeroText(): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willReturn($this->options());
        $writer = $this->createMock(FragmentWriterInterface::class);
        $writer->expects(self::once())->method('add')->with($this->options(), '0')
            ->willReturn('/consumer/.changelog/zero.md');
        $tester = new CommandTester(new Command(null, new AddCommand($factory, $writer)));
        $tester->setInputs(['0']);
        self::assertSame(0, $tester->execute([], ['interactive' => true]));
        self::assertStringNotContainsString('meaningful text', $tester->getDisplay(true));
    }

    /** Supplied arguments bypass interactive questions and reach the writer unchanged. */
    public function testExplicitMessageDoesNotPrompt(): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willReturn($this->options());
        $writer = $this->createMock(FragmentWriterInterface::class);
        $writer->expects(self::once())->method('add')->with($this->options(), '  Explicit **Markdown**  ')
            ->willReturn('/consumer/.changelog/explicit.md');
        $tester = new CommandTester(new Command(null, new AddCommand($factory, $writer)));
        self::assertSame(0, $tester->execute(['message' => '  Explicit **Markdown**  '], ['interactive' => true]));
        self::assertStringNotContainsString('Change description', $tester->getDisplay(true));
    }

    /** Ending an interactive input stream without a description never resolves options or calls the writer. */
    public function testMissingInteractiveAnswerCannotWrite(): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::never())->method('create');
        $writer = $this->createMock(FragmentWriterInterface::class);
        $writer->expects(self::never())->method('add');
        $tester = new CommandTester(new Command(null, new AddCommand($factory, $writer)));
        $tester->setInputs([]);
        $this->expectException(\Symfony\Component\Console\Exception\MissingInputException::class);
        $tester->execute([], ['interactive' => true]);
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
            new AddCommand($factory, $writer)($io, $this->settings(), 'Message', issue: $reference),
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
            new AddCommand($factory, $writer)($io, $this->settings(), 'Message'),
        );
    }
}
