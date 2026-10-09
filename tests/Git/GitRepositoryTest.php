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

    /** Origin inference reads only local Git configuration and never contacts a remote. */
    public function testOriginUrlRetainsItsValueWithoutTransportNewlines(): void
    {
        self::assertSame('git@github.com:owner/project.git', $this->repository([
            [['remote', 'get-url', 'origin'], "git@github.com:owner/project.git\r\n"],
        ])->originUrl('/consumer'));
        self::assertNull($this->repository([[['remote', 'get-url', 'origin'], '']])->originUrl('/consumer'));
        self::assertNull(
            $this->repository([[['remote', 'get-url', 'origin'], '', false, '', 2]])->originUrl('/consumer'),
        );
    }

    /** A broken local configuration cannot silently masquerade as an origin-less project. */
    public function testOriginConfigurationFailureIsDiagnostic(): void
    {
        $this->expectExceptionMessage('Cannot read the repository-local Git origin: configuration unavailable');
        $this->repository([[['remote', 'get-url', 'origin'], '', false, 'configuration unavailable', 128]])->originUrl(
            '/consumer',
        );
    }

    /** Root evidence is normalized and cannot silently become an empty GitHub file scope. */
    public function testRepositoryRootUsesExplicitGitRootEvidence(): void
    {
        self::assertSame(
            '/consumer',
            $this->repository([[['rev-parse', '--show-toplevel'], "/consumer/\n"]])->repositoryRoot('/consumer'),
        );
        self::assertSame(
            'C:/consumer',
            $this->repository([[['rev-parse', '--show-toplevel'], "C:\\consumer\\\n"]])->repositoryRoot('/consumer'),
        );
        $this->expectExceptionMessage('Git did not return a repository root.');
        $this->repository([[['rev-parse', '--show-toplevel'], "\n"]])->repositoryRoot('/consumer');
    }

    public function testRepositoryProbesAreOptionalButRealGitFailuresAreDiagnostic(): void
    {
        self::assertTrue(
            $this->repository([[['rev-parse', '--is-inside-work-tree'], "true\n"]])->isRepository('/consumer'),
        );
        self::assertFalse(
            $this->repository([[['rev-parse', '--is-inside-work-tree'], '', false]])->isRepository('/consumer'),
        );
        $this->expectExceptionMessage('Git failed: permission denied');
        $this->repository(
            [[['rev-parse', '--verify', '--end-of-options', 'HEAD^{commit}'], '', false, 'permission denied']],
        )->resolveRef(
            '/consumer',
        );
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
        $this->repository([[['rev-parse', '--verify', '--end-of-options', 'HEAD^{commit}'], 'bad']])->resolveRef(
            '/consumer',
        );
    }

    public function testAncestryIsVerifiedAgainstResolvedCommits(): void
    {
        self::assertTrue(
            $this->repository(
                [$this->referenceResponse(), $this->referenceResponse(), [[
                    'merge-base', '--is-ancestor', self::SHA, self::SHA],
                    '',
                ]],
            )->isAncestor('/consumer', 'HEAD'),
        );
        self::assertFalse(
            $this->repository(
                [$this->referenceResponse(), $this->referenceResponse(), [[
                    'merge-base', '--is-ancestor', self::SHA, self::SHA],
                    '',
                    false,
                    '',
                    1,
                ]],
            )->isAncestor('/consumer', 'HEAD'),
        );
    }

    public function testAncestryCommandFailureIsNotMistakenForUnrelatedHistory(): void
    {
        $this->expectExceptionMessage('Git ancestry verification failed: unavailable');
        $this->repository(
            [$this->referenceResponse(), $this->referenceResponse(), [[
                'merge-base', '--is-ancestor', self::SHA, self::SHA],
                '',
                false,
                'unavailable',
                128,
            ]],
        )->isAncestor(
            '/consumer',
            'HEAD',
        );
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
        $tags = $this->repository(
            [[$this->tagsArguments(), $raw], [[
                'rev-parse', '--verify', '--end-of-options', 'refs/tags/v1.0.0^{commit}'],
                self::SHA,
            ]],
        )->tags(
            '/consumer',
        );
        self::assertSame(
            [['name' => 'v1.0.0', 'sha' => self::SHA, 'date' => '2026-09-01', 'date_source' => 'annotated-tag']],
            $tags,
        );
    }

    public function testNulPathsAndRenamesAreKeptAsDistinctRecords(): void
    {
        $raw = "A\0.changelog/new.md\0M\0space\nfile\0R100\0old.md\0new.md\0C75\0source\0copy\0D\0deleted\0";
        $result = $this->repository(
            [$this->referenceResponse(), [[
                'diff', '--no-ext-diff', '--no-textconv', '--relative', '--name-status', '-z', '--find-renames', self::SHA . '...HEAD', '--'],
                $raw,
            ]],
        )->changesSince(
            '/consumer',
            'HEAD',
        );
        self::assertSame(['status' => 'A', 'path' => '.changelog/new.md', 'previous' => null], $result[0]);
        self::assertSame("space\nfile", $result[1]['path']);
        self::assertSame(['status' => 'R100', 'path' => 'new.md', 'previous' => 'old.md'], $result[2]);
        self::assertSame(['status' => 'C75', 'path' => 'copy', 'previous' => 'source'], $result[3]);
        self::assertSame('D', $result[4]['status']);
        self::assertSame([
        ],
            $this->repository(
                [$this->referenceResponse(), [[
                    'diff', '--no-ext-diff', '--no-textconv', '--relative', '--name-status', '-z', '--find-renames', self::SHA . '...HEAD', '--'],
                    '',
                ]],
            )->changesSince('/consumer', 'HEAD'),
        );
    }

    public function testIncompleteDiffRecordsCannotPretendToBeAdditions(): void
    {
        $this->expectExceptionMessage('Git returned a malformed changed-path record.');
        $this->repository(
            [$this->referenceResponse(), [[
                'diff', '--no-ext-diff', '--no-textconv', '--relative', '--name-status', '-z', '--find-renames', self::SHA . '...HEAD', '--'],
                "A\0",
            ]],
        )->changesSince(
            '/consumer',
            'HEAD',
        );
    }

    public function testBaselineReadsDistinguishMissingFilesFromMissingRevisions(): void
    {
        self::assertNull(
            $this->repository(
                [$this->referenceResponse(), [['ls-tree', '--name-only', self::SHA, '--', 'CHANGELOG.md'], '']],
            )->readFileAt('/consumer', 'HEAD', 'CHANGELOG.md'),
        );
        self::assertSame(
            'original',
            $this->repository(
                [$this->referenceResponse(), [[
                    'ls-tree', '--name-only', self::SHA, '--', 'CHANGELOG.md'],
                    "CHANGELOG.md\n",
                ], [[
                    'show',
                    self::SHA . ':./CHANGELOG.md',
                ],
                    'original',
                ]],
            )->readFileAt('/consumer', 'HEAD', 'CHANGELOG.md'),
        );
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
        ], $this->repository(
            [$this->referenceResponse(), [['ls-tree', '-r', '-z', self::SHA, '--', '.changelog'], $raw]],
        )->filesAt(
            '/consumer',
            'HEAD',
            '.changelog',
        ));
        self::assertSame([
        ],
            $this->repository(
                [$this->referenceResponse(), [['ls-tree', '-r', '-z', self::SHA, '--', '.changelog'], '']],
            )->filesAt('/consumer', 'HEAD', '.changelog'),
        );
    }

    /** Unexpected malformed or out-of-scope tree paths cannot attest an approved plan. */
    #[DataProvider('badTreeRecords')]
    public function testMalformedCommittedInventoryIsRejected(string $raw): void
    {
        $this->expectExceptionMessage('Git returned a malformed committed-path record.');
        $this->repository(
            [$this->referenceResponse(), [['ls-tree', '-r', '-z', self::SHA, '--', '.changelog'], $raw]],
        )->filesAt(
            '/consumer',
            'HEAD',
            '.changelog',
        );
    }

    /** Supplies incomplete identities and a well-formed record outside the requested boundary. */
    public static function badTreeRecords(): array
    {
        return [[
            'bad'],
            ['100644 blob short' . "\t.changelog/a.md\0"],
            ['100644 blob ' . self::SHA . "\telsewhere/a.md\0"],
        ];
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

    /** Each worktree gets a private journal path without touching the tracked project or real host files. */
    public function testJournalUsesAbsolutePrivateGitDirectoryAndNonGitHasNoJournal(): void
    {
        self::assertNull(
            $this->repository([[['rev-parse', '--is-inside-work-tree'], '', false]])->journalPath('/consumer'),
        );
        foreach ([
            '/repository/.git/worktrees/consumer',
            'C:\\repository\\.git\\worktrees\\consumer\\',
            '//server/share/repository/.git',
        ] as $path) {
            $normalized = rtrim(str_replace('\\', '/', $path), '/');
            self::assertSame($normalized . '/changelog-release-plan.json', $this->repository([
                [['rev-parse', '--is-inside-work-tree'], "true\n"],
                [['rev-parse', '--absolute-git-dir'], $path . "\n"],
            ])->journalPath('/consumer'));
        }
    }

    /** An invalid private Git path cannot direct journal writes outside a verified absolute location. */
    #[DataProvider('invalidJournalPaths')]
    public function testJournalRejectsMalformedPrivatePaths(string $path): void
    {
        $this->expectExceptionMessage('safe absolute private directory');
        $this->repository([
            [['rev-parse', '--is-inside-work-tree'], "true\n"],
            [['rev-parse', '--absolute-git-dir'], $path],
        ])->journalPath('/consumer');
    }

    /** Supplies empty, relative, traversal and NUL output from the injected Git process. */
    public static function invalidJournalPaths(): iterable
    {
        foreach (['', '.git', '/consumer/../.git', '/consumer/./.git', "/consumer/\0git"] as $path) {
            yield [$path];
        }
    }

    /** Exact scalar trailers bind the planned source and settings to the approved central bytes. */
    public function testReleaseMetadataUsesExactDirectCommitTrailersAndCanonicalFieldOrder(): void
    {
        $message = implode("\r\n", array_reverse(explode("\n", $this->metadataMessage())));
        self::assertSame(
            $this->metadataValues(),
            $this->repository($this->metadataResponses($message))->releaseMetadata('/consumer', 'HEAD'),
        );
    }

    /** A direct trailer set cannot silently authorize another output, even if a merge ancestor matches. */
    public function testDirectMetadataRejectsAnOutputMismatch(): void
    {
        $this->expectExceptionMessage('differs from its approved changelog blob');
        $this->repository($this->metadataResponses($this->metadataMessage('different')))->releaseMetadata(
            '/consumer',
            'HEAD',
        );
    }

    /** Missing central history and ordinary nonmerge commits do not invent release metadata. */
    public function testNoHistoryOrOrdinaryCommitReturnsNoMetadata(): void
    {
        self::assertNull(
            $this->repository($this->metadataResponses('ordinary', null))->releaseMetadata('/consumer', 'HEAD'),
        );
        foreach (['', str_repeat('b', 40)] as $parents) {
            self::assertNull($this->repository([
                ...$this->metadataResponses('ordinary'),
                [['show', '-s', '--format=%P', self::SHA, '--'], $parents],
            ])->releaseMetadata('/consumer', 'HEAD'));
        }
    }

    /** Whole-line syntax, all four fields and uniqueness are required before reading any changelog bytes. */
    #[DataProvider('malformedReleaseMessages')]
    public function testMalformedReleaseMetadataFailsClosed(string $message, string $diagnostic): void
    {
        $this->expectExceptionMessage($diagnostic);
        $this->repository([
            $this->referenceResponse(),
            [['show', '-s', '--format=%B', self::SHA, '--'], $message],
        ])->releaseMetadata('/consumer', 'HEAD');
    }

    /** Supplies incomplete, duplicate and invalid trailer values rather than trusting editable prose. */
    public static function malformedReleaseMessages(): iterable
    {
        $base = 'Changelog-Base: ' . str_repeat('a', 40);
        yield [$base, 'incomplete'];
        yield [$base . "\n" . $base, 'duplicate trailers'];
        yield ['Changelog-Base: short', 'malformed'];
        yield ['Changelog-Plan: ' . str_repeat('A', 64), 'malformed'];
        yield ['Changelog-Output:' . str_repeat('a', 64), 'malformed'];
        yield ['Changelog-Options: ' . str_repeat('a', 64) . ' prose', 'malformed'];
    }

    /** Approved merges can select matching generated metadata while ignoring ordinary and superseded commits. */
    public function testMergedMetadataFiltersOutputAndCoalescesIdenticalMatchingTransactions(): void
    {
        $first = str_repeat('b', 40);
        $matching = str_repeat('c', 40);
        $older = str_repeat('d', 40);
        $duplicate = str_repeat('e', 40);
        self::assertSame($this->metadataValues(), $this->repository([
            ...$this->metadataResponses('merge'),
            [['show', '-s', '--format=%P', self::SHA, '--'], $first . ' ' . $matching],
            [['show', '-s', '--format=%B', $first, '--'], 'ordinary base'],
            [['show', '-s', '--format=%B', $matching, '--'], 'ordinary branch head'],
            [[
                'rev-list', '--topo-order', '--max-count=101', $first . '..' . self::SHA, '--'],
                self::SHA . "\n" . $matching . "\n" . $older . "\n" . $duplicate,
            ],
            [['show', '-s', '--format=%B', self::SHA, '--'], 'merge'],
            [['show', '-s', '--format=%B', $matching, '--'], $this->metadataMessage()],
            [['show', '-s', '--format=%B', $older, '--'], $this->metadataMessage('older output')],
            [['show', '-s', '--format=%B', $duplicate, '--'], $this->metadataMessage()],
        ])->releaseMetadata('/consumer', 'HEAD'));
    }

    /** Distinct matching source transactions are ambiguous and never resolved by arbitrary traversal order. */
    public function testMergedMetadataRejectsConflictingMatchingTransactions(): void
    {
        $first = str_repeat('b', 40);
        $matching = str_repeat('c', 40);
        $other = str_repeat('d', 40);
        $this->expectExceptionMessage('ambiguous matching release metadata');
        $this->repository([
            ...$this->metadataResponses('merge'),
            [['show', '-s', '--format=%P', self::SHA, '--'], $first . ' ' . $matching],
            [['show', '-s', '--format=%B', $first, '--'], 'ordinary base'],
            [['show', '-s', '--format=%B', $matching, '--'], 'ordinary branch head'],
            [[
                'rev-list', '--topo-order', '--max-count=101', $first . '..' . self::SHA, '--'],
                $matching . "\n" . $other,
            ],
            [['show', '-s', '--format=%B', $matching, '--'], $this->metadataMessage()],
            [
                ['show', '-s', '--format=%B', $other, '--'],
                str_replace('Changelog-Plan: ' . str_repeat('c', 64), 'Changelog-Plan: ' . str_repeat(
                    'd',
                    64,
                ), $this->metadataMessage()),
            ],
        ])->releaseMetadata('/consumer', 'HEAD');
    }

    /** A bounded merge scan with no matching generated output yields no evidence. */
    public function testMergedMetadataWithoutMatchingOutputReturnsNull(): void
    {
        $first = str_repeat('b', 40);
        $second = str_repeat('c', 40);
        foreach (['', $second] as $inventory) {
            $responses = [
                ...$this->metadataResponses('merge'),
                [['show', '-s', '--format=%P', self::SHA, '--'], $first . ' ' . $second],
                [['show', '-s', '--format=%B', $first, '--'], 'ordinary base'],
                [['show', '-s', '--format=%B', $second, '--'], 'ordinary branch head'],
                [['rev-list', '--topo-order', '--max-count=101', $first . '..' . self::SHA, '--'], $inventory],
            ];
            if ('' !== $inventory) {
                $responses[] = [['show', '-s', '--format=%B', $second, '--'], $this->metadataMessage('different')];
            }
            self::assertNull($this->repository($responses)->releaseMetadata('/consumer', 'HEAD'));
        }
    }

    /** Malformed graph identities and an over-limit merge scan fail before unbounded message reads. */
    #[DataProvider('invalidMergeInventories')]
    public function testMergedMetadataRejectsMalformedOrTruncatedGraph(
        string $parents,
        ?string $inventory,
        string $diagnostic,
    ): void {
        $responses = [...$this->metadataResponses('merge'), [['show', '-s', '--format=%P', self::SHA, '--'], $parents]];
        if (null !== $inventory) {
            foreach (explode(' ', $parents) as $parent) {
                $responses[] = [['show', '-s', '--format=%B', $parent, '--'], 'ordinary parent'];
            }
            $responses[] = [[
                'rev-list', '--topo-order', '--max-count=101', explode(' ', $parents)[0] . '..' . self::SHA, '--'],
                $inventory,
            ];
        }
        $this->expectExceptionMessage($diagnostic);
        $this->repository($responses)->releaseMetadata('/consumer', 'HEAD');
    }

    /** Supplies malformed parents, malformed merge inventory and the limit sentinel. */
    public static function invalidMergeInventories(): iterable
    {
        $parents = str_repeat('b', 40) . ' ' . str_repeat('c', 40);
        yield ['short', null, 'malformed release commit parents'];
        yield [$parents, 'short', 'malformed merged release evidence'];
        yield [$parents, implode("\n", array_fill(0, 101, str_repeat('c', 40))), 'bounded commit inventory'];
    }

    /** The latest direct generated head wins over superseded ancestor plans that produce identical Markdown. */
    public function testMergedDirectHeadSupersedesAnOlderSameOutputPlanWithoutScanningAncestors(): void
    {
        $base = str_repeat('b', 40);
        $latest = str_repeat('c', 40);
        $newMessage = str_replace(
            'Changelog-Base: ' . $base,
            'Changelog-Base: ' . str_repeat('f', 40),
            $this->metadataMessage(),
        );
        $newMessage = str_replace(
            'Changelog-Plan: ' . str_repeat('c', 64),
            'Changelog-Plan: ' . str_repeat('f', 64),
            $newMessage,
        );
        $expected = $this->metadataValues();
        $expected['base_sha'] = str_repeat('f', 40);
        $expected['plan_id'] = str_repeat('f', 64);
        self::assertSame($expected, $this->repository([
            ...$this->metadataResponses('approved merge'),
            [['show', '-s', '--format=%P', self::SHA, '--'], $base . ' ' . $latest],
            [['show', '-s', '--format=%B', $base, '--'], 'fresh main'],
            [['show', '-s', '--format=%B', $latest, '--'], $newMessage],
        ])->releaseMetadata('/consumer', 'HEAD'));
        // An extra rev-list or read of the older same-output plan would fail the exact process expectations.
    }

    /** Identical direct-parent evidence is one transaction; genuinely different matching direct parents are refused. */
    public function testMatchingDirectParentsMustIdentifyTheSameTransaction(): void
    {
        $first = str_repeat('b', 40);
        $second = str_repeat('c', 40);
        $responses = [
            ...$this->metadataResponses('approved merge'),
            [['show', '-s', '--format=%P', self::SHA, '--'], $first . ' ' . $second],
            [['show', '-s', '--format=%B', $first, '--'], $this->metadataMessage()],
            [['show', '-s', '--format=%B', $second, '--'], $this->metadataMessage()],
        ];
        self::assertSame($this->metadataValues(), $this->repository($responses)->releaseMetadata('/consumer', 'HEAD'));
        $responses[array_key_last($responses)][1] = str_replace(
            'Changelog-Base: ' . str_repeat('b', 40),
            'Changelog-Base: ' . str_repeat('f', 40),
            $this->metadataMessage(),
        );
        $this->expectExceptionMessage('conflicting matching direct-parent');
        $this->repository($responses)->releaseMetadata('/consumer', 'HEAD');
    }

    /** Builds injected process responses for one target commit and exact central blob. */
    private function metadataResponses(string $message, ?string $central = 'exact'): array
    {
        $responses = [
            $this->referenceResponse(),
            [['show', '-s', '--format=%B', self::SHA, '--'], $message],
            [['rev-parse', '--verify', '--end-of-options', self::SHA . '^{commit}'], self::SHA],
            [['ls-tree', '--name-only', self::SHA, '--', 'CHANGELOG.md'], null === $central ? '' : "CHANGELOG.md\n"],
        ];
        if (null !== $central) {
            $responses[] = [['show', self::SHA . ':./CHANGELOG.md'], $central];
        }

        return $responses;
    }

    /** Defines stable source, plan and settings evidence for an exact generated changelog. */
    private function metadataValues(string $central = 'exact'): array
    {
        return ['base_sha' => str_repeat('b', 40), 'plan_id' => str_repeat('c', 64),
            'output_sha256' => hash('sha256', $central), 'options_sha256' => str_repeat('d', 64)];
    }

    /** Produces generated scalar trailers independently of the adapter's parser. */
    private function metadataMessage(string $central = 'exact'): string
    {
        $values = $this->metadataValues($central);

        return "chore: release\n\nChangelog-Base: " . $values['base_sha'] . "\nChangelog-Plan: " . $values['plan_id']
            . "\nChangelog-Output: " . $values['output_sha256'] . "\nChangelog-Options: " . $values['options_sha256'];
    }

    private function tagsArguments(): array
    {
        return [
            'for-each-ref',
            '--format=%(refname:strip=2)%00%(objecttype)%00%(taggerdate:iso-strict)%00%(objectname)%00%(*objectname)%00%(*objecttype)',
            'refs/tags/',
        ];
    }

    private function referenceResponse(): array
    {
        return [['rev-parse', '--verify', '--end-of-options', 'HEAD^{commit}'], self::SHA . "\n"];
    }

    private function repository(array $responses): GitRepository
    {
        $factory = $this->createMock(ProcessFactoryInterface::class);
        $index = 0;
        $factory->expects(self::exactly(count($responses)))->method('create')->willReturnCallback(
            function (array $argv) use ($responses, &$index): Process {
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
            },
        );
        $exceptions = $this->createStub(ReleaseExceptionFactoryInterface::class);
        $exceptions->method('invalid')->willReturnCallback(
            static fn(string $message): InvalidArgumentException => new InvalidArgumentException($message),
        );
        $exceptions->method('failure')->willReturnCallback(
            static fn(string $message): RuntimeException => new RuntimeException($message),
        );

        return new GitRepository($factory, $exceptions);
    }
}
