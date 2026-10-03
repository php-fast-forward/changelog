<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Git;

use FastForward\Changelog\Git\Factory\ProcessFactoryInterface;
use FastForward\Changelog\Git\GitRepository;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

#[CoversClass(GitRepository::class)]
final class GitRepositoryTest extends TestCase
{
    private const string SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Root evidence is normalized and cannot silently become an empty GitHub file scope. */
    public function testRepositoryRootUsesExplicitGitRootEvidence(): void
    {
        self::assertSame('/consumer', $this->repository([[['rev-parse', '--show-toplevel'], "/consumer/\n"]])->repositoryRoot('/consumer'));
        self::assertSame('C:/consumer', $this->repository([[['rev-parse', '--show-toplevel'], "C:\\consumer\\\n"]])->repositoryRoot('/consumer'));
        $this->expectExceptionMessage('Git did not return a repository root.');
        $this->repository([[['rev-parse', '--show-toplevel'], "\n"]])->repositoryRoot('/consumer');
    }

    public function testRepositoryProbesAreOptionalButRealGitFailuresAreDiagnostic(): void
    {
        self::assertTrue($this->repository([[['rev-parse', '--is-inside-work-tree'], "true\n"]])->isRepository('/consumer'));
        self::assertFalse($this->repository([[['rev-parse', '--is-inside-work-tree'], '', false]])->isRepository('/consumer'));
        $this->expectExceptionMessage('Git failed: permission denied');
        $this->repository([[['rev-parse', '--verify', '--end-of-options', 'HEAD^{commit}'], '', false, 'permission denied']])->resolveRef('/consumer');
    }

    public function testResolveRefVerifiesTheExactCommitAndStripsTransportNewlines(): void
    {
        self::assertSame(self::SHA, $this->repository([$this->referenceResponse()])->resolveRef('/consumer'));
    }

    #[DataProvider('invalidReferences')]
    public function testInvalidRevisionNeverCreatesAProcess(string $reference): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository([])->resolveRef('/consumer', $reference);
    }

    public static function invalidReferences(): array
    {
        return [[''], [' '], ['--help'], ["head\0name"], ["head\nnext"]];
    }

    public function testMalformedCommitIdentityFailsClosed(): void
    {
        $this->expectExceptionMessage('Git returned an invalid commit identity.');
        $this->repository([[['rev-parse', '--verify', '--end-of-options', 'HEAD^{commit}'], 'bad']])->resolveRef('/consumer');
    }

    public function testAncestryIsVerifiedAgainstResolvedCommits(): void
    {
        self::assertTrue($this->repository([$this->referenceResponse(), $this->referenceResponse(), [['merge-base', '--is-ancestor', self::SHA, self::SHA], '']])->isAncestor('/consumer', 'HEAD'));
        self::assertFalse($this->repository([$this->referenceResponse(), $this->referenceResponse(), [['merge-base', '--is-ancestor', self::SHA, self::SHA], '', false, '', 1]])->isAncestor('/consumer', 'HEAD'));
    }

    public function testAncestryCommandFailureIsNotMistakenForUnrelatedHistory(): void
    {
        $this->expectExceptionMessage('Git ancestry verification failed: unavailable');
        $this->repository([$this->referenceResponse(), $this->referenceResponse(), [['merge-base', '--is-ancestor', self::SHA, self::SHA], '', false, 'unavailable', 128]])->isAncestor('/consumer', 'HEAD');
    }

    public function testTagDatesBelongToAnnotatedTagsAndLightweightDatesStayAbsent(): void
    {
        $raw = "v1.0.0\0tag\0" . "2026-09-01T12:00:00+00:00\0object\0" . self::SHA . "\0commit\n"
            . 'v0.1.0' . "\0commit\0\0" . self::SHA . "\0\0\n"
            . 'v0.0.1' . "\0tag\0\0object\0" . self::SHA . "\0commit\n";
        $result = $this->repository([[$this->tagsArguments(), $raw]])->tags('/consumer');
        self::assertSame('2026-09-01', $result[0]['date']);
        self::assertSame('annotated-tag', $result[0]['date_source']);
        self::assertSame(self::SHA, $result[0]['sha']);
        self::assertNull($result[1]['date']);
        self::assertNull($result[1]['date_source']);
        self::assertNull($result[2]['date']);
        self::assertSame([], $this->repository([[$this->tagsArguments(), '']])->tags('/consumer'));
    }

    #[DataProvider('badTagMetadata')]
    public function testMalformedTagMetadataIsDiagnosed(string $raw): void
    {
        $this->expectException(RuntimeException::class);
        $this->repository([[$this->tagsArguments(), $raw]])->tags('/consumer');
    }

    public static function badTagMetadata(): array
    {
        return [['broken'], ["name\0commit\0\0bad\0\0"]];
    }

    public function testTagsForNonCommitObjectsAreIgnored(): void
    {
        $raw = "artifact\0tree\0\0tree-sha\0\0\narchive\0tag\0\0tag-sha\0blob-sha\0blob\n";
        self::assertSame([], $this->repository([[$this->tagsArguments(), $raw]])->tags('/consumer'));
    }

    /** Git permits nested annotated tags; their stable identity still names the peeled commit. */
    public function testNestedAnnotatedTagUsesGitRecursiveCommitPeeling(): void
    {
        $raw = "v1.0.0\0tag\0" . "2026-09-01T12:00:00+00:00\0object\0another-tag\0tag\n";
        $tags = $this->repository([[$this->tagsArguments(), $raw], [['rev-parse', '--verify', '--end-of-options', 'refs/tags/v1.0.0^{commit}'], self::SHA]])->tags('/consumer');
        self::assertSame([['name' => 'v1.0.0', 'sha' => self::SHA, 'date' => '2026-09-01', 'date_source' => 'annotated-tag']], $tags);
    }

    public function testNulPathsAndRenamesAreKeptAsDistinctRecords(): void
    {
        $raw = "A\0.changelog/new.md\0M\0space\nfile\0R100\0old.md\0new.md\0C75\0source\0copy\0D\0deleted\0";
        $result = $this->repository([$this->referenceResponse(), [['diff', '--no-ext-diff', '--no-textconv', '--relative', '--name-status', '-z', '--find-renames', self::SHA . '...HEAD', '--'], $raw]])->changesSince('/consumer', 'HEAD');
        self::assertSame(['status' => 'A', 'path' => '.changelog/new.md', 'previous' => null], $result[0]);
        self::assertSame("space\nfile", $result[1]['path']);
        self::assertSame(['status' => 'R100', 'path' => 'new.md', 'previous' => 'old.md'], $result[2]);
        self::assertSame(['status' => 'C75', 'path' => 'copy', 'previous' => 'source'], $result[3]);
        self::assertSame('D', $result[4]['status']);
        self::assertSame([], $this->repository([$this->referenceResponse(), [['diff', '--no-ext-diff', '--no-textconv', '--relative', '--name-status', '-z', '--find-renames', self::SHA . '...HEAD', '--'], '']])->changesSince('/consumer', 'HEAD'));
    }

    public function testIncompleteDiffRecordsCannotPretendToBeAdditions(): void
    {
        $this->expectExceptionMessage('Git returned a malformed changed-path record.');
        $this->repository([$this->referenceResponse(), [['diff', '--no-ext-diff', '--no-textconv', '--relative', '--name-status', '-z', '--find-renames', self::SHA . '...HEAD', '--'], "A\0"]])->changesSince('/consumer', 'HEAD');
    }

    public function testBaselineReadsDistinguishMissingFilesFromMissingRevisions(): void
    {
        self::assertNull($this->repository([$this->referenceResponse(), [['ls-tree', '--name-only', self::SHA, '--', 'CHANGELOG.md'], '']])->readFileAt('/consumer', 'HEAD', 'CHANGELOG.md'));
        self::assertSame('original', $this->repository([$this->referenceResponse(), [['ls-tree', '--name-only', self::SHA, '--', 'CHANGELOG.md'], "CHANGELOG.md\n"], [['show', self::SHA . ':./CHANGELOG.md'], 'original']])->readFileAt('/consumer', 'HEAD', 'CHANGELOG.md'));
    }

    #[DataProvider('invalidPaths')]
    public function testInvalidBaselinePathStopsBeforeRead(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository([$this->referenceResponse()])->readFileAt('/consumer', 'HEAD', $path);
    }

    public static function invalidPaths(): array
    {
        return [[''], ['-file'], ["a\0b"], ['/file'], ['../file'], ['a/./file'], ['a\\file'], ['C:file'], ['a//file']];
    }

    /** A committed inventory preserves modes for safety policy and paths without quote decoding. */
    public function testTreeInventoryRetainsSymlinksNestedFilesAndNewlinePaths(): void
    {
        $raw = '100644 blob ' . self::SHA . "\t.changelog/new.md\0"
            . '120000 blob ' . self::SHA . "\t.changelog/linked.md\0"
            . '100755 blob ' . self::SHA . "\t.changelog/nested/space\nfile.md\0";
        self::assertSame([
            ['path' => '.changelog/new.md', 'mode' => '100644'],
            ['path' => '.changelog/linked.md', 'mode' => '120000'],
            ['path' => ".changelog/nested/space\nfile.md", 'mode' => '100755'],
        ], $this->repository([$this->referenceResponse(), [['ls-tree', '-r', '-z', self::SHA, '--', '.changelog'], $raw]])->filesAt('/consumer', 'HEAD', '.changelog'));
        self::assertSame([], $this->repository([$this->referenceResponse(), [['ls-tree', '-r', '-z', self::SHA, '--', '.changelog'], '']])->filesAt('/consumer', 'HEAD', '.changelog'));
    }

    /** Unexpected malformed or out-of-scope tree paths cannot attest an approved plan. */
    #[DataProvider('badTreeRecords')]
    public function testMalformedCommittedInventoryIsRejected(string $raw): void
    {
        $this->expectExceptionMessage('Git returned a malformed committed-path record.');
        $this->repository([$this->referenceResponse(), [['ls-tree', '-r', '-z', self::SHA, '--', '.changelog'], $raw]])->filesAt('/consumer', 'HEAD', '.changelog');
    }

    /** Supplies incomplete identities and a well-formed record outside the requested boundary. */
    public static function badTreeRecords(): array
    {
        return [['bad'], ['100644 blob short' . "\t.changelog/a.md\0"], ['100644 blob ' . self::SHA . "\telsewhere/a.md\0"]];
    }

    public function testOnlyTheGeneratedFragmentIsAddedAndCommitted(): void
    {
        $path = '.changelog/new.md';
        self::assertSame(self::SHA, $this->repository([
            [['add', '--', $path], ''],
            [['commit', '--only', '--message', 'chore: record change', '--', $path], ''],
            $this->referenceResponse(),
        ])->commitFragment('/consumer', $path, 'chore: record change'));
    }

    public function testCommitFailureDoesNotAttemptToResetOrUnstageAnyFile(): void
    {
        $path = '.changelog/new.md';
        $this->expectExceptionMessage('Git failed: identity missing');
        $this->repository([
            [['add', '--', $path], ''],
            [['commit', '--only', '--message', 'message', '--', $path], '', false, 'identity missing'],
        ])->commitFragment('/consumer', $path, 'message');
    }

    #[DataProvider('invalidCommits')]
    public function testInvalidCommitDataDoesNotStageAnything(string $path, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository([])->commitFragment('/consumer', $path, $message);
    }

    public static function invalidCommits(): array
    {
        return [['', 'message'], ['file', ' '], ["a\0b", 'message']];
    }

    private function tagsArguments(): array
    {
        return ['for-each-ref', '--format=%(refname:strip=2)%00%(objecttype)%00%(taggerdate:iso-strict)%00%(objectname)%00%(*objectname)%00%(*objecttype)', 'refs/tags/'];
    }

    private function referenceResponse(): array
    {
        return [['rev-parse', '--verify', '--end-of-options', 'HEAD^{commit}'], self::SHA . "\n"];
    }

    private function repository(array $responses): GitRepository
    {
        $factory = $this->createMock(ProcessFactoryInterface::class);
        $index = 0;
        $factory->expects(self::exactly(count($responses)))->method('create')->willReturnCallback(function (array $argv) use ($responses, &$index): Process {
            $response = $responses[$index++];
            self::assertSame(['git', '-C', '/consumer', ...$response[0]], $argv);
            $process = $this->createMock(Process::class);
            $success = $response[2] ?? true;
            $process->expects(self::once())->method('run')->willReturn($success ? 0 : 1);
            $process->expects(self::once())->method('isSuccessful')->willReturn($success);
            $process->method('getOutput')->willReturn($response[1]);
            $process->method('getErrorOutput')->willReturn($response[3] ?? '');
            $process->method('getExitCode')->willReturn($response[4] ?? ($success ? 0 : 1));
            return $process;
        });
        $exceptions = $this->createStub(ReleaseExceptionFactoryInterface::class);
        $exceptions->method('invalid')->willReturnCallback(static fn(string $message): InvalidArgumentException => new InvalidArgumentException($message));
        $exceptions->method('failure')->willReturnCallback(static fn(string $message): RuntimeException => new RuntimeException($message));
        return new GitRepository($factory, $exceptions);
    }
}
