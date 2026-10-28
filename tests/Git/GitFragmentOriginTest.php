<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Git;

use FastForward\Changelog\Git\GitFragmentOriginReaderInterface;
use FastForward\Changelog\Git\GitRepository;
use FastForward\Changelog\Process\Factory\ProcessFactoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(GitRepository::class)]
final class GitFragmentOriginTest extends TestCase
{
    private const string BASE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string ORIGIN = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string PARENT = 'cccccccccccccccccccccccccccccccccccccccc';
    private const string OBJECT = 'dddddddddddddddddddddddddddddddddddddddd';
    private const string PATH = '.changelog/fix.md';

    public function testRootTransitionProvesTheFullIntroducingCommitWithoutReadingMessages(): void
    {
        $repository = $this->repository([
            ...$this->preflight(),
            ...$this->blob(self::BASE),
            $this->history(self::ORIGIN . "\0"),
            ...$this->blob(self::ORIGIN),
            $this->parents(self::ORIGIN, ''),
        ]);

        self::assertInstanceOf(GitFragmentOriginReaderInterface::class, $repository);
        self::assertSame(
            self::ORIGIN,
            $repository->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current')),
        );
    }

    #[TestWith([''])]
    #[TestWith(['cccccccccccccccccccccccccccccccccccccccc'])]
    public function testDirectProvenanceAcceptsRootAndOrdinaryImplementationCommits(string $parents): void
    {
        $responses = [...$this->preflight(), ...$this->blob(self::BASE),
            $this->history(self::ORIGIN . "\0"), ...$this->blob(self::ORIGIN), $this->parents(self::ORIGIN, $parents)];
        if ('' !== $parents) {
            array_push($responses, ...$this->blob(self::PARENT, 'previous'));
        }
        $responses[] = $this->parents(self::ORIGIN, $parents);
        self::assertTrue($this->repository($responses)->fragmentHasDirectOrigin(
            '/consumer', self::BASE, self::PATH, hash('sha256', 'current'),
        ));
    }

    public function testDirectProvenanceRefusesAFragmentAbsentFromTheOriginalPrHead(): void
    {
        self::assertFalse($this->repository([...$this->preflight(),
            [$this->git(['ls-tree', '-z', self::BASE, '--', self::PATH]), ''],
        ])->fragmentHasDirectOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current')));
    }

    #[TestWith(['current', false])]
    #[TestWith(['other', true])]
    public function testMergeProvenanceDistinguishesImportedBytesFromAnAuthoredMergeChange(
        string $sideBytes,
        bool $expected,
    ): void {
        $side = str_repeat('e', 40);
        $parents = self::PARENT . ' ' . $side;
        self::assertSame($expected, $this->repository([
            ...$this->preflight(), ...$this->blob(self::BASE), $this->history(self::ORIGIN . "\0"),
            ...$this->blob(self::ORIGIN), $this->parents(self::ORIGIN, $parents),
            ...$this->blob(self::PARENT, 'previous'), $this->parents(self::ORIGIN, $parents),
            [$this->git(['rev-parse', '--show-prefix']), "\n"],
            [['git', '-C', '/consumer', 'rev-parse', '--show-toplevel'], "/consumer\n"],
            ...$this->blob($side, $sideBytes),
        ])->fragmentHasDirectOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current')));
    }

    #[TestWith(['invalid'])]
    #[TestWith(['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'])]
    #[TestWith(['excessive'])]
    public function testMalformedOrExcessiveProvenanceParentsFailClosed(string $parents): void
    {
        if ('excessive' === $parents) {
            $parents = implode(' ', array_fill(0, 101, self::PARENT));
        }
        $repository = $this->repository([
            ...$this->preflight(), ...$this->blob(self::BASE), $this->history(self::ORIGIN . "\0"),
            ...$this->blob(self::ORIGIN), $this->parents(self::ORIGIN, ''),
            $this->parents(self::ORIGIN, $parents),
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provenance parents');
        $repository->fragmentHasDirectOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current'));
    }

    public function testMergeTransitionUsesOnlyTheActualFirstParent(): void
    {
        self::assertSame(self::ORIGIN, $this->repository([
            ...$this->preflight(),
            ...$this->blob(self::BASE),
            $this->history(self::ORIGIN . "\0"),
            ...$this->blob(self::ORIGIN),
            $this->parents(self::ORIGIN, self::PARENT . ' ' . str_repeat('e', 40)),
            ...$this->blob(self::PARENT, 'previous'),
        ])->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current')));
    }

    public function testRegularModeOnlyChangeRetainsTheEarlierByteIntroduction(): void
    {
        self::assertSame(self::PARENT, $this->repository([
            ...$this->preflight(),
            ...$this->blob(self::BASE),
            $this->history(self::ORIGIN . "\0" . self::PARENT . "\0"),
            ...$this->blob(self::ORIGIN, mode: '100755'),
            $this->parents(self::ORIGIN, self::PARENT),
            ...$this->blob(self::PARENT),
            ...$this->blob(self::PARENT),
            $this->parents(self::PARENT, str_repeat('e', 40)),
            [$this->git(['ls-tree', '-z', str_repeat('e', 40), '--', self::PATH]), ''],
        ])->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current')));
    }

    public function testHistoricalMissingOrDifferentBytesDoNotAcquireAnOrigin(): void
    {
        self::assertNull($this->repository([
            ...$this->preflight(),
            [$this->git(['ls-tree', '-z', self::BASE, '--', self::PATH]), ''],
        ])->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current')));
        self::assertNull($this->repository([
            ...$this->preflight(),
            ...$this->blob(self::BASE, 'different'),
        ])->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current')));
    }

    #[TestWith(['120000', 'blob'])]
    #[TestWith(['160000', 'commit'])]
    #[TestWith(['040000', 'tree'])]
    public function testOnlyRegularBaseBlobsCanProvideAttribution(string $mode, string $type): void
    {
        self::assertNull($this->repository([
            ...$this->preflight(),
            [
                $this->git(['ls-tree', '-z', self::BASE, '--', self::PATH]),
                $mode . ' ' . $type . ' ' . self::OBJECT . "\t" . self::PATH . "\0",
            ],
        ])->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current')));
    }

    public function testARegularFileReplacingASymlinkStartsANewOrigin(): void
    {
        self::assertSame(self::ORIGIN, $this->repository([
            ...$this->preflight(),
            ...$this->blob(self::BASE),
            $this->history(self::ORIGIN . "\0"),
            ...$this->blob(self::ORIGIN),
            $this->parents(self::ORIGIN, self::PARENT),
            [
                $this->git(['ls-tree', '-z', self::PARENT, '--', self::PATH]),
                '120000 blob ' . self::OBJECT . "\t" . self::PATH . "\0",
            ],
        ])->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current')));
    }

    public function testNestedConsumerAndUnusualPathBytesRemainLiteral(): void
    {
        $path = "notes/[literal]\nfragment.md";
        $repositoryPath = 'package/' . $path;
        $responses = [
            [$this->git(['rev-parse', '--is-shallow-repository'], '/consumer/package'), "false\n"],
            [$this->git(['rev-parse', '--show-prefix'], '/consumer/package'), "package/\n"],
            [['git', '-C', '/consumer/package', 'rev-parse', '--show-toplevel'], "/consumer\n"],
            [[
                'git', '-C', '/consumer', 'rev-parse', '--verify', '--end-of-options', self::BASE . '^{commit}'],
                self::BASE,
            ],
            ...$this->blob(self::BASE, path: $repositoryPath),
            $this->history(self::ORIGIN . "\0", $repositoryPath),
            ...$this->blob(self::ORIGIN, path: $repositoryPath),
            $this->parents(self::ORIGIN, ''),
        ];

        self::assertSame(
            self::ORIGIN,
            $this->repository($responses)->fragmentOrigin('/consumer/package', self::BASE, $path, hash(
                'sha256',
                'current',
            )),
        );
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputsNeverCreateAProcess(string $base, string $path, string $hash): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository([])->fragmentOrigin('/consumer', $base, $path, $hash);
    }

    public static function invalidInputs(): iterable
    {
        yield ['HEAD', self::PATH, hash('sha256', 'current')];
        yield [strtoupper(self::BASE), self::PATH, hash('sha256', 'current')];
        yield [self::BASE, '../fix.md', hash('sha256', 'current')];
        yield [self::BASE, self::PATH, 'short'];
        yield [self::BASE, self::PATH, str_repeat('A', 64)];
    }

    #[TestWith(['true'])]
    #[TestWith(['unknown'])]
    #[TestWith(['false '])]
    public function testShallowOrMalformedRepositoryCompletenessIsRefused(string $value): void
    {
        $this->expectExceptionMessage('complete non-shallow Git history');
        $this->repository([[$this->git(['rev-parse', '--is-shallow-repository']), $value . "\n"]])
            ->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current'));
    }

    #[TestWith(['package', 'malformed consumer path prefix'])]
    #[TestWith(['../package/', 'unsafe consumer path prefix'])]
    public function testMalformedConsumerPrefixIsNotTrusted(string $prefix, string $message): void
    {
        $this->expectExceptionMessage($message);
        $this->repository([
            [$this->git(['rev-parse', '--is-shallow-repository']), 'false'],
            [$this->git(['rev-parse', '--show-prefix']), $prefix],
        ])->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current'));
    }

    public function testBaseIdentityCannotResolveToAnotherCommit(): void
    {
        $responses = $this->preflight();
        $responses[array_key_last($responses)][1] = self::ORIGIN;
        $this->expectExceptionMessage('different fragment base commit');
        $this->repository($responses)->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current'));
    }

    #[DataProvider('invalidHistories')]
    public function testMalformedOrOverLimitHistoryNeverSelectsACandidate(string $history): void
    {
        $this->expectExceptionMessage('malformed or exceeds 1000 path changes');
        $this->repository([
            ...$this->preflight(), ...$this->blob(self::BASE), $this->history($history),
        ])->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current'));
    }

    public static function invalidHistories(): iterable
    {
        yield [self::ORIGIN];
        yield ["short\0"];
        yield [self::ORIGIN . "\0\0"];
        yield [self::ORIGIN . "\0" . self::ORIGIN . "\0"];
        yield [implode(
            "\0",
            array_map(static fn(int $i): string => str_pad(dechex($i), 40, '0', STR_PAD_LEFT), range(1, 1001)),
        ) . "\0"];
    }

    public function testNoProvedTransitionReturnsNoOrigin(): void
    {
        self::assertNull($this->repository([
            ...$this->preflight(), ...$this->blob(self::BASE), $this->history(''),
        ])->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current')));
        self::assertNull($this->repository([
            ...$this->preflight(), ...$this->blob(self::BASE), $this->history(self::ORIGIN . "\0"),
            ...$this->blob(self::ORIGIN), $this->parents(self::ORIGIN, self::PARENT), ...$this->blob(self::PARENT),
        ])->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current')));
    }

    public function testOmittedCurrentByteTransitionIsRefusedAsIncompleteHistory(): void
    {
        $this->expectExceptionMessage('does not retain the accepted current bytes');
        $this->repository([
            ...$this->preflight(), ...$this->blob(self::BASE), $this->history(self::ORIGIN . "\0"),
            ...$this->blob(self::ORIGIN, 'older bytes'),
        ])->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current'));
    }

    #[TestWith(['short'])]
    #[TestWith([self::ORIGIN])]
    public function testMalformedOrCyclicParentEvidenceIsRefused(string $parents): void
    {
        $this->expectExceptionMessage('malformed fragment origin parents');
        $this->repository([
            ...$this->preflight(), ...$this->blob(self::BASE), $this->history(self::ORIGIN . "\0"),
            ...$this->blob(self::ORIGIN), $this->parents(self::ORIGIN, $parents),
        ])->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current'));
    }

    #[TestWith(['broken'])]
    #[TestWith(["100644 blob dddddddddddddddddddddddddddddddddddddddd\twrong.md\0"])]
    public function testMalformedTreeRecordsCannotBeUsedAsRegularBlobs(string $entry): void
    {
        $this->expectExceptionMessage('malformed fragment origin tree metadata');
        $this->repository([
            ...$this->preflight(), [$this->git(['ls-tree', '-z', self::BASE, '--', self::PATH]), $entry],
        ])->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current'));
    }

    public function testMissingHistoryObjectsAreDiagnosticRatherThanGuessed(): void
    {
        $responses = [...$this->preflight(), ...$this->blob(self::BASE)];
        $responses[] = [$this->history('')[0], '', false, 'missing parent object'];
        $this->expectExceptionMessage('missing parent object');
        $this->repository($responses)->fragmentOrigin('/consumer', self::BASE, self::PATH, hash('sha256', 'current'));
    }

    private function preflight(): array
    {
        return [
            [$this->git(['rev-parse', '--is-shallow-repository']), "false\n"],
            [$this->git(['rev-parse', '--show-prefix']), "\n"],
            [['git', '-C', '/consumer', 'rev-parse', '--show-toplevel'], "/consumer\n"],
            [[
                'git', '-C', '/consumer', 'rev-parse', '--verify', '--end-of-options', self::BASE . '^{commit}'],
                self::BASE,
            ],
        ];
    }

    private function blob(
        string $commit,
        string $bytes = 'current',
        string $mode = '100644',
        string $path = self::PATH,
    ): array {
        return [
            [
                $this->git(['ls-tree', '-z', $commit, '--', $path]),
                $mode . ' blob ' . self::OBJECT . "\t" . $path . "\0",
            ],
            [$this->git(['cat-file', 'blob', self::OBJECT]), $bytes],
        ];
    }

    private function history(string $output, string $path = self::PATH): array
    {
        return [
            $this->git(['log', '--first-parent', '--full-history', '--no-renames', '--format=%H', '-z', '--no-patch',
                '--no-notes', '--no-show-signature', '--max-count=1001', self::BASE, '--', $path]),
            $output,
        ];
    }

    private function parents(string $commit, string $parents): array
    {
        return [
            $this->git(['show', '-s', '--no-notes', '--no-show-signature', '--format=%P', $commit, '--']),
            $parents,
        ];
    }

    private function git(array $arguments, string $directory = '/consumer'): array
    {
        return ['git', '-C', $directory, '--no-replace-objects', '--literal-pathspecs', ...$arguments];
    }

    private function repository(array $responses): GitRepository
    {
        $factory = $this->createMock(ProcessFactoryInterface::class);
        $index = 0;
        $factory->expects(self::exactly(count($responses)))->method('create')->willReturnCallback(
            function (array $argv) use ($responses, &$index): Process {
                $response = $responses[$index++];
                self::assertSame($response[0], $argv);
                $success = $response[2] ?? true;
                $process = $this->createMock(Process::class);
                $process->expects(self::once())->method('run')->willReturn($success ? 0 : 128);
                $process->expects(self::once())->method('isSuccessful')->willReturn($success);
                $process->method('getOutput')->willReturn($response[1]);
                $process->method('getErrorOutput')->willReturn($response[3] ?? '');

                return $process;
            },
        );

        return new GitRepository($factory);
    }
}
