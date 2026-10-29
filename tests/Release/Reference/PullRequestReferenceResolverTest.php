<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release\Reference;

use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitCommitAcquirerInterface;
use FastForward\Changelog\Git\GitFragmentOriginReaderInterface;
use FastForward\Changelog\Git\GitFragmentProvenanceReaderInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubClient;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\GitHub\GitHubPublicTransportInterface;
use FastForward\Changelog\Release\Reference\PullRequestReferenceResolver;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Version\VersionImpact;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(PullRequestReferenceResolver::class)]
#[UsesClass(GitHubEvidence::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(Category::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(GitHubClient::class)]
final class PullRequestReferenceResolverTest extends TestCase
{
    private const string BASE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string ORIGIN = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string MERGE = 'cccccccccccccccccccccccccccccccccccccccc';
    private const string HEAD = 'dddddddddddddddddddddddddddddddddddddddd';
    private const string BLOB = "---\ncategory: fixed\n---\nPreserve literal Markdown.\n";
    private const string PATH = 'project/.changelog/change.md';

    private array $calls = [];
    private array $responses = [];
    private array $associations = [['number' => 108]];
    private array $lists = [];
    private bool $directOrigin = true;
    private ?string $origin = self::ORIGIN;
    private ?string $headOrigin = self::ORIGIN;
    private array $blobs = [];
    private array $ancestry = [];
    private ?string $relativePath = null;
    private ?RuntimeException $transportFailure = null;
    private ?RuntimeException $gitFailure = null;

    #[DataProvider('inactiveContexts')]
    public function testInactiveOrFullyExplicitResolutionPreservesIdentityWithoutIo(
        bool $enabled,
        ?string $repository,
        ?string $base,
        bool $capability,
        bool $explicit,
        bool $empty,
    ): void {
        $git = $capability
            ? $this->createMockForIntersectionOfInterfaces(
                [
                    GitRepositoryInterface::class,
                    GitFragmentOriginReaderInterface::class,
                    GitFragmentProvenanceReaderInterface::class,
                ],
            )
            : $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::never())->method('repositoryRoot');
        $git->expects(self::never())->method('readFileAt');
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::never())->method('request');
        $github->expects(self::never())->method('paginate');
        $paths = $this->createMock(PackagePathResolverInterface::class);
        $paths->expects(self::never())->method('absolutePath');
        $changes = $empty ? [] : [$this->change($explicit ? 55 : null)];

        self::assertSame($changes, new PullRequestReferenceResolver($git, $github, $paths)->resolve(
            $this->options($enabled, $repository), $base, $changes,
        ));
    }

    public static function inactiveContexts(): array
    {
        return [
            'disabled' => [false, 'owner/project', self::BASE, true, false, false],
            'unknown repository' => [true, null, self::BASE, true, false, false],
            'no committed base' => [true, 'owner/project', null, true, false, false],
            'explicit PR' => [true, 'owner/project', self::BASE, true, true, false],
            'explicit PR without origin capability' => [true, 'owner/project', self::BASE, false, true, false],
            'empty inventory' => [true, 'owner/project', self::BASE, true, false, true],
        ];
    }

    public function testEnabledEligibleResolutionRequiresTheOriginCapability(): void
    {
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::never())->method('repositoryRoot');
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::never())->method('request');
        $paths = $this->createStub(PackagePathResolverInterface::class);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fragment-origin capability');
        new PullRequestReferenceResolver($git, $github, $paths)->resolve(
            $this->options(),
            self::BASE,
            [$this->change()],
        );
    }

    #[DataProvider('invalidContext')]
    public function testMalformedEnabledContextFailsBeforeIo(string $repository, string $base): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->fixture()->resolve($this->options(true, $repository), $base, [$this->change()]);
        } finally {
            self::assertSame([], $this->calls);
        }
    }

    public static function invalidContext(): array
    {
        return [
            ['owner/project/extra', self::BASE],
            ['', self::BASE],
            ['owner/project', 'HEAD'],
            ['owner/project', strtoupper(self::BASE)],
        ];
    }

    public function testUniqueMergedForkEnrichesOnlyTheAbsentPrAndRetainsEveryOtherField(): void
    {
        $change = $this->change();
        $explicit = $this->change(77, 'explicit.md');
        $result = $this->fixture()->resolve($this->options(), self::BASE, [$change, $explicit]);

        self::assertNotSame($change, $result[0]);
        self::assertSame(108, $result[0]->pullRequest);
        self::assertSame($explicit, $result[1]);
        self::assertSame($change->id, $result[0]->id);
        self::assertSame($change->category, $result[0]->category);
        self::assertSame($change->description, $result[0]->description);
        self::assertSame($change->author, $result[0]->author);
        self::assertSame($change->issue, $result[0]->issue);
        self::assertSame($change->type, $result[0]->type);
        self::assertSame(['git.origin', '/repo', self::BASE, self::PATH, hash('sha256', self::BLOB)], $this->calls[2]);
        self::assertCount(3, $this->callsOf('github.paginate'));
        self::assertCount(3, $this->callsOf('github.request'));
    }

    public function testCachesAssociationAndMergedIdentityOnlyInsideOneOperation(): void
    {
        $this->associations = [['number' => 108], ['number' => 108]];
        $resolver = $this->fixture();
        $changes = [$this->change(), $this->change(null, 'second.md')];

        self::assertSame([
            108, 108],
            array_column($resolver->resolve($this->options(), self::BASE, $changes), 'pullRequest'),
        );
        self::assertCount(3, $this->callsOf('github.paginate'));
        self::assertCount(3, $this->callsOf('github.request'));
        $resolver->resolve($this->options(), self::BASE, $changes);
        self::assertCount(6, $this->callsOf('github.paginate'));
        self::assertCount(6, $this->callsOf('github.request'));
    }

    #[DataProvider('missingOrigins')]
    public function testUncommittedOrUnknownOriginIsRetainedWithoutNetwork(bool $committed): void
    {
        if ($committed) {
            $this->origin = null;
        } else {
            $this->blobs[self::BASE . ':' . self::PATH] = null;
        }
        $changes = [$this->change()];
        self::assertSame($changes, $this->fixture()->resolve($this->options(), self::BASE, $changes));
        self::assertSame([], $this->callsOf('github.request'));
        self::assertSame([], $this->callsOf('github.paginate'));
    }

    public static function missingOrigins(): array
    {
        return [[false], [true]];
    }

    public function testAcceptedInventoryMustMatchTheSourceBlobBeforeOriginOrNetworkLookup(): void
    {
        $changes = [$this->change()];
        $result = $this->fixture()->resolve($this->options(), self::BASE, $changes, [
            '/repo/project/.changelog/change.md' => hash('sha256', 'changed uncommitted bytes'),
        ]);
        self::assertSame($changes, $result);
        self::assertSame([], $this->callsOf('git.origin'));
        self::assertSame([], $this->callsOf('github.request'));
        self::assertSame([], $this->callsOf('github.paginate'));
    }

    public function testMatchingAcceptedInventoryAllowsTheSameImmutableProof(): void
    {
        $result = $this->fixture()->resolve($this->options(), self::BASE, [$this->change()], [
            '/repo/project/.changelog/change.md' => hash('sha256', self::BLOB),
        ]);
        self::assertSame(108, $result[0]->pullRequest);
    }

    #[DataProvider('invalidAcceptedInventories')]
    public function testIncompleteOrMalformedAcceptedInventoryFailsBeforeReadingTheBlob(array $hashes): void
    {
        $this->expectException(RuntimeException::class);

        try {
            $this->fixture()->resolve($this->options(), self::BASE, [$this->change()], $hashes);
        } finally {
            self::assertSame([], $this->callsOf('git.read'));
            self::assertSame([], $this->callsOf('github.request'));
        }
    }

    public static function invalidAcceptedInventories(): array
    {
        $path = '/repo/project/.changelog/change.md';

        return [
            [['/other.md' => hash('sha256', self::BLOB)]],
            [[$path => null]], [[$path => '']], [[$path => 'sha256']],
            [[$path => 123]], [[$path => strtoupper(hash('sha256', self::BLOB))]],
        ];
    }

    public function testAuthoritativeEmptyAssociationsPreserveDescriptionOnly(): void
    {
        $this->associations = [];
        $changes = [$this->change()];
        self::assertSame($changes, $this->fixture()->resolve($this->options(), self::BASE, $changes));
        self::assertCount(1, $this->callsOf('github.request'));
    }

    #[DataProvider('unmergedStates')]
    public function testOpenAndClosedUnmergedAssociationsDoNotCreateReferences(string $state): void
    {
        $pr = $this->pr();
        $pr['state'] = $state;
        $pr['merged'] = false;
        $this->responses['GET /repos/owner/project/pulls/108'] = $pr;
        $changes = [$this->change()];
        self::assertSame($changes, $this->fixture()->resolve($this->options(), self::BASE, $changes));
        self::assertCount(2, $this->callsOf('github.request'));
    }

    public static function unmergedStates(): array
    {
        return [['open'], ['closed']];
    }

    #[DataProvider('unrelatedEvidence')]
    public function testAnUnrelatedMergedPrDoesNotClaimTheFragment(string $failure): void
    {
        if ('origin' === $failure) {
            $this->ancestry[self::ORIGIN . ':' . self::MERGE] = false;
        } elseif ('base' === $failure) {
            $this->ancestry[self::MERGE . ':' . self::BASE] = false;
        } else {
            $this->blobs[self::MERGE . ':' . self::PATH] = 'different source blob';
        }
        $changes = [$this->change()];
        self::assertSame($changes, $this->fixture()->resolve($this->options(), self::BASE, $changes));
    }

    public static function unrelatedEvidence(): array
    {
        return [['origin'], ['base'], ['blob']];
    }

    public function testTwoDistinctValidMergedPrNumbersAreControlledAmbiguity(): void
    {
        $this->associations = [['number' => 108], ['number' => 109]];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ambiguous');
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
    }

    public function testOriginCapabilityAloneCannotClaimImplementationProvenance(): void
    {
        $git = $this->createStubForIntersectionOfInterfaces([
            GitRepositoryInterface::class, GitFragmentOriginReaderInterface::class,
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fragment-provenance capability');
        new PullRequestReferenceResolver($git, $this->createStub(GitHubClientInterface::class),
            $this->createStub(PackagePathResolverInterface::class))->resolve(
                $this->options(),
                self::BASE,
                [$this->change()],
            );
    }

    /** A shared merge and commit association are insufficient when the original PR head inherited the fragment. */
    public function testStackIntegrationCannotMasqueradeAsImplementation(): void
    {
        $this->directOrigin = false;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('explicit pull_request fragment metadata');
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
    }

    public function testOriginalPrHeadWithoutTheFragmentDoesNotSupplyImplementationEvidence(): void
    {
        $this->headOrigin = null;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('explicit pull_request');
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
    }

    /** Complete file evidence must bind the exact accepted bytes to a real changed destination. */
    #[DataProvider('unprovedDeltas')]
    public function testUnprovedImplementationDeltasNeverBecomeReferences(?array $files, mixed $count): void
    {
        $this->responses['GET /repos/owner/project/pulls/108'] = array_replace(
            $this->pr(),
            ['changed_files' => $count],
        );
        if (null !== $files) {
            $this->lists['/repos/owner/project/pulls/108/files'] = $files;
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('explicit pull_request');
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
    }

    public static function unprovedDeltas(): array
    {
        $file = ['filename' => self::PATH, 'sha' => hash(
            'sha1',
            'blob ' . strlen(self::BLOB) . "\0" . self::BLOB,
        ), 'status' => 'added'];
        $cases = [[null, null], [null, '2'], [null, -1], [null, 3001], [[], 1], [[$file, $file], 2],
            [[], 0], [[array_replace($file, ['filename' => 'other.md'])], 1],
            [[array_replace($file, ['sha' => str_repeat('e', 40)])], 1]];
        foreach (['filename' => [null, ''], 'sha' => [null, 'HEAD'], 'status' => [
            null,
            'unknown',
            'removed',
            'unchanged',
            'changed',
        ]] as $field => $values) {
            foreach ($values as $value) {
                $cases[] = [[array_replace($file, [$field => $value])], 1];
            }
        }

        return $cases;
    }

    #[DataProvider('changedStatuses')]
    public function testExactImplementationDeltaAcceptsEachChangedDestination(string $status, string $algorithm): void
    {
        $this->responses['GET /repos/owner/project/pulls/108'] = array_replace($this->pr(), ['changed_files' => 1]);
        $this->lists['/repos/owner/project/pulls/108/files'] = [[
            'filename' => self::PATH, 'status' => $status,
            'sha' => hash($algorithm, 'blob ' . strlen(self::BLOB) . "\0" . self::BLOB),
        ]];
        self::assertSame(
            108,
            $this->fixture()->resolve($this->options(), self::BASE, [$this->change()])[0]->pullRequest,
        );
    }

    public static function changedStatuses(): array
    {
        return [['added', 'sha1'], ['modified', 'sha1'], ['renamed', 'sha1'], ['copied', 'sha256']];
    }

    #[DataProvider('unprovedCommits')]
    public function testIncompleteOrInheritedCommitEvidenceFailsClosed(mixed $count, array $records): void
    {
        $this->responses['GET /repos/owner/project/pulls/108'] = array_replace($this->pr(), ['commits' => $count]);
        $this->lists['/repos/owner/project/pulls/108/commits'] = $records;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('explicit pull_request');
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
    }

    public static function unprovedCommits(): array
    {
        return [[null, []], ['1', []], [0, []], [251, []], [1, []], [1, [['sha' => self::HEAD]]],
            [1, [[]]], [1, [['sha' => 'HEAD']]], [2, [['sha' => self::ORIGIN], ['sha' => self::ORIGIN]]]];
    }

    #[DataProvider('invalidPaths')]
    public function testCanonicalGitPathsCannotEscapeOrChangeFragmentIdentity(string $id, string $relative): void
    {
        $this->relativePath = $relative;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('details were withheld');
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change(null, $id)]);
    }

    public static function invalidPaths(): array
    {
        return [
            ['../other.md', self::PATH], ['change.md', '/outside/change.md'], ['change.md', ''],
            ['change.md', 'project\\change.md'], ['change.md', "project/cha\0nge.md"],
            ['change.md', '../change.md'], ['change.md', './change.md'], ['change.md', 'project//change.md'],
        ];
    }

    public function testMalformedOriginAndGitFailureAreRedacted(): void
    {
        $this->origin = 'HEAD';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('details were withheld');
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
    }

    #[DataProvider('operationalFailures')]
    public function testOperationalErrorsDoNotExposeRawGitOrApiDetails(string $boundary): void
    {
        if ('git' === $boundary) {
            $this->gitFailure = new RuntimeException('synthetic-private-path-or-token');
        } else {
            $this->transportFailure = new RuntimeException('synthetic-private-path-or-token');
        }

        try {
            $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
            self::fail('Unavailable evidence must fail closed.');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString('synthetic-private', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public static function operationalFailures(): array
    {
        return [['git'], ['api']];
    }

    #[DataProvider('invalidRepositoryEvidence')]
    public function testRepositoryIdentityMustBeCompleteAndCannotBeCoerced(?array $repository): void
    {
        $this->responses['GET /repos/owner/project'] = $repository;
        $this->expectException(RuntimeException::class);
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
    }

    public static function invalidRepositoryEvidence(): array
    {
        $valid = ['id' => 3_000_000_000, 'node_id' => 'opaque-repository', 'full_name' => 'owner/project'];
        $cases = [[null], [[]]];

        foreach (['id' => [0, -1, '3000000000', 3_000_000_000.0, true], 'node_id' => [null, '', 123], 'full_name' => [
            null,
            123,
            'other/project',
        ]] as $key => $values) {
            foreach ($values as $value) {
                $cases[] = [array_replace($valid, [$key => $value])];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidAssociations')]
    public function testMalformedAssociationIsNotAnEmptyResult(array $record): void
    {
        $this->associations = [$record];
        $this->expectException(RuntimeException::class);
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
    }

    public static function invalidAssociations(): array
    {
        return [[[]], [['number' => 0]], [['number' => '108']], [['number' => 108.0]], [['number' => true]]];
    }

    #[DataProvider('invalidPrEvidence')]
    public function testMalformedPrEvidenceFailsClosed(string $field, mixed $value): void
    {
        $pr = $this->pr();
        $this->replaceField($pr, $field, $value);
        $this->responses['GET /repos/owner/project/pulls/108'] = $pr;
        $this->expectException(RuntimeException::class);
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
    }

    public static function invalidPrEvidence(): array
    {
        return [
            ['number', '108'], ['number', 109], ['state', null], ['state', 'open'], ['merged', null], ['merged', 1],
            ['base.repo.id', '3000000000'], ['base.repo.id', 3_000_000_001], ['base.repo.node_id', 'other-node'],
            ['base.repo.full_name', 'other/project'], ['head.sha', null], ['head.sha', 'HEAD'],
            ['head.ref', null], ['head.ref', ''], ['base.ref', null], ['base.ref', ''],
            ['merge_commit_sha', null], ['merge_commit_sha', self::BASE],
        ];
    }

    public function testMissingFullPrResponseFailsClosed(): void
    {
        $this->responses['GET /repos/owner/project/pulls/108'] = null;
        $this->expectException(RuntimeException::class);
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
    }

    #[DataProvider('invalidGraphEvidence')]
    public function testGraphqlPartialErrorsOrIdentityDriftNeverBecomeAuthority(string $field, mixed $value): void
    {
        $proof = $this->graph();
        $this->replaceField($proof, $field, $value);
        $this->responses['POST /graphql'] = $proof;
        $this->expectException(RuntimeException::class);
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
    }

    public static function invalidGraphEvidence(): array
    {
        return [
            ['errors', [['message' => 'synthetic private error']]], ['errors', null], ['data.repository', null],
            ['data.repository', 'partial'], ['data.repository.id', null], ['data.repository.id', 'other-node'],
            ['data.repository.nameWithOwner', 'other/project'], ['data.repository.pullRequest', null],
            ['data.repository.pullRequest', 'partial'], ['data.repository.pullRequest.number', '108'],
            ['data.repository.pullRequest.number', 109], ['data.repository.pullRequest.state', 'OPEN'],
            ['data.repository.pullRequest.merged', false], ['data.repository.pullRequest.merged', 1],
            ['data.repository.pullRequest.headRefOid', self::BASE], [
                'data.repository.pullRequest.headRefName',
                'other',
            ],
            ['data.repository.pullRequest.baseRefName', 'other'], ['data.repository.pullRequest.mergeCommit', null],
            ['data.repository.pullRequest.mergeCommit.oid', 123], [
                'data.repository.pullRequest.mergeCommit.oid',
                'HEAD',
            ],
        ];
    }

    public function testMissingGraphqlResponseFailsClosed(): void
    {
        $this->responses['POST /graphql'] = null;
        $this->expectException(RuntimeException::class);
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
    }

    public function testMatchingLegacyMergeAndEmptyGraphqlErrorsAreAccepted(): void
    {
        $this->responses['GET /repos/owner/project/pulls/108'] = $this->pr() + ['merge_commit_sha' => self::MERGE];
        $this->responses['POST /graphql'] = $this->graph() + ['errors' => []];
        self::assertSame(
            108,
            $this->fixture()->resolve($this->options(), self::BASE, [$this->change()])[0]->pullRequest,
        );
    }

    public function testDeletedForkRepositoryIsNotRequiredForTargetProof(): void
    {
        $pr = $this->pr();
        $pr['head']['repo'] = null;
        $this->responses['GET /repos/owner/project/pulls/108'] = $pr;
        self::assertSame(
            108,
            $this->fixture()->resolve($this->options(), self::BASE, [$this->change()])[0]->pullRequest,
        );
    }

    public function testResolvingAPrDoesNotInferAnIssueOrAuthor(): void
    {
        $change = new Changeset('change.md', Category::Fixed, null, null, null, 'User-facing description.');
        $result = $this->fixture()->resolve($this->options(), self::BASE, [$change])[0];
        self::assertSame(108, $result->pullRequest);
        self::assertNull($result->author);
        self::assertNull($result->issue);
        self::assertSame($change->description, $result->description);
    }

    public function testRealPaginationIncludesLaterAssociationsBeforeSelectingTheUniqueMergedPr(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (
            &$requests
        ): MockResponse {
            $requests[] = [$method, $url];

            if (str_contains($url, '/commits/')) {
                $first = str_ends_with($url, 'page=1');
                $link = 'https://api.github.com/repos/owner/project/commits/' . self::ORIGIN
                    . '/pulls?per_page=100&page=2';

                return new MockResponse(json_encode([['number' => $first ? 108 : 109]], JSON_THROW_ON_ERROR), [
                    'response_headers' => $first ? ['link' => '<' . $link . '>; rel="next"'] : [],
                ]);
            }

            if (str_ends_with($url, '/pulls/108')) {
                $pr = $this->pr();
                $pr['merged'] = false;

                return new MockResponse(json_encode($pr, JSON_THROW_ON_ERROR));
            }
            if (str_contains($url, '/files?')) {
                $first = str_ends_with($url, 'page=1');
                $link = 'https://api.github.com/repos/owner/project/pulls/109/files?per_page=100&page=2';

                return new MockResponse(json_encode([$this->files()[$first ? 0 : 1]], JSON_THROW_ON_ERROR), [
                    'response_headers' => $first ? ['link' => '<' . $link . '>; rel="next"'] : [],
                ]);
            }
            $payload = match (true) {
                str_contains($url, '/commits?') => [['sha' => self::ORIGIN]],
                str_ends_with($url, '/pulls/109') => $this->pr(109),
                str_ends_with($url, '/graphql') => $this->graph(109),
                default => $this->repository(),
            };

            return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR));
        });
        $resolver = $this->fixture(new GitHubClient($http));
        self::assertSame(109, $resolver->resolve($this->options(), self::BASE, [$this->change()])[0]->pullRequest);
        self::assertCount(9, $requests);
        self::assertContains([
            'GET', 'https://api.github.com/repos/owner/project/commits/' . self::ORIGIN . '/pulls?per_page=100&page=2',
        ], $requests);
        self::assertContains([
            'GET', 'https://api.github.com/repos/owner/project/pulls/109/files?per_page=100&page=2',
        ], $requests);
    }

    public function testEnterpriseGraphqlRetainsSameOriginRoutingAndApiVersion(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (
            &$requests
        ): MockResponse {
            $requests[] = [$method, $url];
            self::assertSame([
                'X-GitHub-Api-Version: 2026-03-10'],
                $options['normalized_headers']['x-github-api-version'],
            );
            $payload = match (true) {
                str_contains($url, '/commits?') => [['sha' => self::ORIGIN]],
                str_contains($url, '/files?') => $this->files(),
                str_ends_with($url, '/commits/' . self::ORIGIN . '/pulls?per_page=100&page=1') => [['number' => 108]],
                str_ends_with($url, '/pulls/108') => $this->pr(),
                str_ends_with($url, '/api/graphql') => $this->graph(),
                default => $this->repository(),
            };

            return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR));
        });
        $resolver = $this->fixture(new GitHubClient($http, 'synthetic', 'https://enterprise.test/api/v3'));
        self::assertSame(108, $resolver->resolve($this->options(), self::BASE, [$this->change()])[0]->pullRequest);
        self::assertContains(['POST', 'https://enterprise.test/api/graphql'], $requests);
        self::assertCount(6, $requests);
    }

    public function testRealClientDoesNotTreatAssociation404AsNoPullRequest(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode($this->repository(), JSON_THROW_ON_ERROR)),
            new MockResponse('', ['http_code' => 404]),
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('details were withheld');
        $this->fixture(new GitHubClient($http))->resolve($this->options(), self::BASE, [$this->change()]);
    }

    /** Missing squash heads can be acquired only through the optional verified-commit boundary. */
    public function testMissingHeadIsAcquiredAndRecheckedBeforeProvenance(): void
    {
        $this->missingHead = true;
        self::assertSame(
            108,
            $this->fixture()->resolve($this->options(), self::BASE, [$this->change()])[0]->pullRequest,
        );
        self::assertContains(['git.acquire', '/repo', 'owner/project', self::HEAD], $this->calls);
    }

    /** A failed fetch cannot turn unavailable proof into an accepted reference. */
    public function testFailedAcquisitionRefusesResolution(): void
    {
        $this->missingHead = true;
        $this->acquisitionFailure = true;
        $this->expectExceptionMessage('details were withheld');
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
    }

    /** An acquired object must still resolve to the API-verified exact SHA. */
    public function testAcquiredMismatchingCommitRefusesResolution(): void
    {
        $this->missingHead = true;
        $this->resolvedHead = self::BASE;
        $this->expectExceptionMessage('details were withheld');
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
    }

    /** API authority must still agree after object acquisition. */
    public function testChangedApiEvidenceAfterAcquisitionRefusesResolution(): void
    {
        $this->missingHead = true;
        $this->changedAfterAcquisition = true;
        $this->expectExceptionMessage('details were withheld');
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
    }

    /** A corroborated but different PR head after acquisition cannot replace the original selection. */
    public function testChangedPrHeadAfterAcquisitionRefusesResolution(): void
    {
        $this->missingHead = true;
        $this->changedPrAfterAcquisition = true;
        $this->expectExceptionMessage('details were withheld');
        $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
    }

    /** Enterprise API evidence cannot authorize acquisition from the public GitHub transport. */
    public function testMissingEnterpriseHeadIsRefusedWithoutAcquisition(): void
    {
        $this->missingHead = true;
        $this->publicTransport = false;
        try {
            $this->fixture()->resolve($this->options(), self::BASE, [$this->change()]);
            self::fail('Expected unavailable provenance refusal.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('details were withheld', $failure->getMessage());
            self::assertSame([], $this->callsOf('git.acquire'));
        }
    }

    private bool $publicTransport = true;
    private bool $missingHead = false;
    private bool $changedPrAfterAcquisition = false;
    private bool $changedAfterAcquisition = false;
    private bool $acquired = false;
    private bool $acquisitionFailure = false;
    private string $resolvedHead = self::HEAD;

    private function fixture(?GitHubClientInterface $client = null): PullRequestReferenceResolver
    {
        $git = $this->createStubForIntersectionOfInterfaces(
            [
                GitRepositoryInterface::class,
                GitCommitAcquirerInterface::class,
                GitFragmentOriginReaderInterface::class,
                GitFragmentProvenanceReaderInterface::class,
            ],
        );
        $git->method('resolveRef')->willReturnCallback(function (): string {
            if ($this->missingHead) {
                throw new RuntimeException('Missing original head');
            }

            return $this->resolvedHead;
        });
        $git->method('acquireCommit')->willReturnCallback(
            function (string $directory, string $repository, string $sha): void {
                $this->calls[] = ['git.acquire', $directory, $repository, $sha];
                if ($this->acquisitionFailure) {
                    throw new RuntimeException('Fetch failed');
                }
                $this->missingHead = false;
                $this->acquired = true;
            },
        );
        $git->method('repositoryRoot')->willReturnCallback(function (string $directory): string {
            self::assertSame('/repo/project', $directory);

            if (null !== $this->gitFailure) {
                throw $this->gitFailure;
            }
            $this->calls[] = ['git.root', $directory];

            return '/repo';
        });
        $git->method('readFileAt')->willReturnCallback(
            function (string $directory, string $sha, string $path): ?string {
                self::assertSame('/repo', $directory);
                $this->calls[] = ['git.read', $directory, $sha, $path];

                return array_key_exists(
                    $sha . ':' . $path,
                    $this->blobs,
                ) ? $this->blobs[$sha . ':' . $path] : self::BLOB;
            },
        );
        $git->method('fragmentOrigin')->willReturnCallback(
            function (string $directory, string $sha, string $path, string $hash): ?string {
                self::assertSame('/repo', $directory);
                self::assertContains($sha, [self::BASE, self::HEAD]);
                self::assertSame(hash('sha256', self::BLOB), $hash);
                $this->calls[] = ['git.origin', $directory, $sha, $path, $hash];

                return self::HEAD === $sha ? $this->headOrigin : $this->origin;
            },
        );
        $git->method('isAncestor')->willReturnCallback(
            function (string $directory, string $ancestor, string $descendant): bool {
                self::assertSame('/repo', $directory);
                $this->calls[] = ['git.ancestor', $directory, $ancestor, $descendant];

                return $this->ancestry[$ancestor . ':' . $descendant] ?? true;
            },
        );
        $git->method('fragmentHasDirectOrigin')->willReturnCallback(
            function (string $directory, string $head, string $path, string $hash): bool {
                self::assertSame('/repo', $directory);
                self::assertSame(self::HEAD, $head);
                self::assertSame(hash('sha256', self::BLOB), $hash);
                $this->calls[] = ['git.provenance', $head, $path];

                return $this->directOrigin;
            },
        );
        $paths = $this->createStub(PackagePathResolverInterface::class);
        $paths->method('absolutePath')->willReturnCallback(
            static fn(string $path, string $base): string => $base . '/' . $path,
        );
        $paths->method('relativePath')->willReturnCallback(
            fn(string $path, string $base): string => $this->relativePath ?? substr($path, strlen($base) + 1),
        );
        $paths->method('isAbsolute')->willReturnCallback(static fn(string $path): bool => str_starts_with($path, '/'));
        $github = $this->createStubForIntersectionOfInterfaces(
            [GitHubClientInterface::class, GitHubPublicTransportInterface::class],
        );
        $github->method('usesPublicGitHub')->willReturn($this->publicTransport);
        $github->method('paginate')->willReturnCallback(function (string $path): array {
            $this->calls[] = ['github.paginate', $path];
            if (str_ends_with($path, '/files')) {
                return $this->lists[$path] ?? $this->files();
            }
            if (str_ends_with($path, '/commits')) {
                return $this->lists[$path] ?? [['sha' => self::ORIGIN]];
            }
            self::assertSame('/repos/owner/project/commits/' . self::ORIGIN . '/pulls', $path);

            return $this->associations;
        });
        $github->method('request')->willReturnCallback(
            function (string $method, string $path, ?array $body = null): ?array {
                if (null !== $this->transportFailure) {
                    throw $this->transportFailure;
                }
                $this->calls[] = ['github.request', $method, $path];

                if ('POST' === $method) {
                    self::assertSame('/graphql', $path);
                    self::assertStringStartsWith('query ChangelogFragmentPullRequest(', $body['query']);
                    self::assertStringContainsString('id', $body['query']);
                    self::assertStringNotContainsString('databaseId', $body['query']);
                    self::assertSame('owner', $body['variables']['owner']);
                    self::assertSame('project', $body['variables']['name']);
                }

                if ($this->acquired && $this->changedPrAfterAcquisition) {
                    if ('/repos/owner/project/pulls/108' === $path) {
                        $changed = $this->pr();
                        $changed['head']['sha'] = self::BASE;

                        return $changed;
                    }
                    if ('/graphql' === $path) {
                        $changed = $this->graph();
                        $changed['data']['repository']['pullRequest']['headRefOid'] = self::BASE;

                        return $changed;
                    }
                }

                if ($this->acquired && $this->changedAfterAcquisition && '/repos/owner/project' === $path) {
                    return [...$this->repository(), 'id' => 999];
                }

                if (array_key_exists($method . ' ' . $path, $this->responses)) {
                    return $this->responses[$method . ' ' . $path];
                }

                return match ($path) {
                    '/repos/owner/project' => $this->repository(),
                    '/repos/owner/project/pulls/108' => $this->pr(),
                    '/repos/owner/project/pulls/109' => $this->pr(109),
                    '/graphql' => $this->graph($body['variables']['number']),
                    default => throw new RuntimeException('Unexpected fixture request.'),
                };
            },
        );

        return new PullRequestReferenceResolver($git, $client ?? $github, $paths);
    }

    private function options(bool $enabled = true, ?string $repository = 'owner/project'): ReleaseOptions
    {
        return new ReleaseOptions(
            '/repo/project',
            repository: $repository,
            source: 'tags',
            resolvePullRequests: $enabled,
        );
    }

    private function change(?int $pullRequest = null, string $id = 'change.md'): Changeset
    {
        return new Changeset(
            $id,
            Category::Fixed,
            7,
            $pullRequest,
            'contributor',
            "Preserve **literal** Markdown.\n\nSecond paragraph.",
            VersionImpact::Minor,
        );
    }

    private function repository(): array
    {
        return ['id' => 3_000_000_000, 'node_id' => 'opaque-repository', 'full_name' => 'owner/project'];
    }

    private function pr(int $number = 108): array
    {
        return [
            'number' => $number, 'state' => 'closed', 'merged' => true, 'changed_files' => 2, 'commits' => 1,
            'head' => ['sha' => self::HEAD, 'ref' => 'feature', 'repo' => ['id' => 42, 'node_id' => 'fork-node', 'full_name' => 'contributor/fork']],
            'base' => ['ref' => 'main', 'repo' => $this->repository()],
        ];
    }

    private function files(): array
    {
        return array_map(static fn(string $path): array => ['filename' => $path, 'status' => 'added',
            'sha' => hash('sha1', 'blob ' . strlen(self::BLOB) . "\0" . self::BLOB)], [
                self::PATH,
                'project/.changelog/second.md',
            ]);
    }

    private function graph(int $number = 108): array
    {
        return ['data' => ['repository' => ['id' => 'opaque-repository', 'nameWithOwner' => 'owner/project', 'pullRequest' => [
            'number' => $number, 'state' => 'MERGED', 'merged' => true, 'headRefOid' => self::HEAD,
            'headRefName' => 'feature', 'baseRefName' => 'main', 'mergeCommit' => ['oid' => self::MERGE],
        ]]]];
    }

    private function replaceField(array &$record, string $field, mixed $value): void
    {
        $target = &$record;

        foreach (explode('.', $field) as $segment) {
            $target = &$target[$segment];
        }
        $target = $value;
    }

    private function callsOf(string $operation): array
    {
        return array_values(array_filter($this->calls, static fn(array $call): bool => $call[0] === $operation));
    }
}
