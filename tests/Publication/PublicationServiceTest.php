<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Publication;

use FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface;
use FastForward\Changelog\GitHub\GitHubClient;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Publication\PublicationEvidence;
use FastForward\Changelog\Publication\PublicationResult;
use FastForward\Changelog\Publication\PublicationService;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Validator\MergedPublicationEvidenceValidatorInterface;
use FastForward\Changelog\Validator\PublicationEvidenceValidatorInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(PublicationService::class)]
#[UsesClass(PublicationResult::class)]
#[UsesClass(PublicationEvidence::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(\FastForward\Changelog\Automation\Policy\GitHubEvidence::class)]
#[UsesClass(GitHubClient::class)]
final class PublicationServiceTest extends TestCase
{
    /** The real client keeps REST 2026 while GraphQL proves the merge identity removed from that payload. */
    public function testRest2026PublicationUsesGraphqlMergeIdentityWithThePinnedHttpHeader(): void
    {
        $pr = $this->mergedPr();
        unset($pr['merge_commit_sha']);
        $state = $this->state(['context' => true, 'pr' => $pr, 'real_http' => true,
            'graphql' => ['errors' => [], ...self::mergedProof()]]);
        $result = $this->service($state)->publishMerged(
            new ReleaseOptions('/consumer', repository: 'owner/repo'),
            7,
            str_repeat('b', 40),
            str_repeat('f', 40),
            automationActor: 'app[bot]',
            dryRun: true,
        );
        self::assertSame('dry-run', $result->state);
        self::assertSame(str_repeat('b', 40), $result->sha);
        self::assertSame([], $this->writes($state));
        self::assertSame(1, $state['policy_calls']);
        self::assertSame(1, $state['evidence_calls']);
    }

    /** Large numeric REST IDs retain an opaque cross-API identity without decoding or narrowing it. */
    #[DataProvider('opaqueRepositoryNodes')]
    public function testLargeRestRepositoryIdsUseOpaqueNodeIdentity(string $node): void
    {
        $pr = $this->mergedPr();
        unset($pr['merge_commit_sha']);
        $pr['base']['repo']['id'] = $pr['head']['repo']['id'] = 3_000_000_000;
        $pr['base']['repo']['node_id'] = $pr['head']['repo']['node_id'] = $node;
        $proof = self::mergedProof();
        $proof['data']['repository']['id'] = $node;
        $state = $this->state(['context' => true, 'pr' => $pr, 'graphql' => $proof]);
        $result = $this->service($state)->publishMerged(
            new ReleaseOptions('/consumer', repository: 'owner/repo'),
            7,
            str_repeat('b', 40),
            str_repeat('f', 40),
            automationActor: 'app[bot]',
            dryRun: true,
        );
        self::assertSame('dry-run', $result->state);
        self::assertSame([], $this->writes($state));
        self::assertSame(1, $state['policy_calls']);
        self::assertSame(1, $state['evidence_calls']);
    }

    /** Opaque Node IDs are compared byte for byte even when they contain punctuation or look numeric. */
    public static function opaqueRepositoryNodes(): iterable
    {
        yield 'opaque punctuation' => ['opaque:repository/node+unchanged='];
        yield 'opaque numeric-looking string' => ['3000000000'];
    }

    /** Missing, differently typed or unequal GraphQL Node IDs cannot reach policy, domain proof or writes. */
    #[DataProvider('invalidGraphqlNodeIds')]
    public function testGraphqlNodeIdsNeverUseTypeCoercion(mixed $id): void
    {
        $pr = $this->mergedPr();
        $pr['base']['repo']['id'] = $pr['head']['repo']['id'] = 3_000_000_000;
        $pr['base']['repo']['node_id'] = $pr['head']['repo']['node_id'] = '3000000000';
        $proof = self::mergedProof();
        $proof['data']['repository']['id'] = $id;
        $state = $this->state(['context' => true, 'pr' => $pr, 'graphql' => $proof]);
        $failed = false;
        try {
            $this->service($state)->publishMerged(
                new ReleaseOptions('/consumer', repository: 'owner/repo'),
                7,
                str_repeat('b', 40),
                str_repeat('f', 40),
                automationActor: 'app[bot]',
                dryRun: true,
            );
        } catch (RuntimeException $error) {
            $failed = true;
            self::assertStringContainsString('GraphQL', $error->getMessage());
        }
        self::assertTrue($failed);
        self::assertSame(0, $state['policy_calls']);
        self::assertSame(0, $state['evidence_calls']);
        self::assertSame([], $this->writes($state));
    }

    /** Rejects missing/wrong opaque values and non-string types instead of comparing loosely or decoding them. */
    public static function invalidGraphqlNodeIds(): iterable
    {
        foreach ([null, '', 3_000_000_000, 3_000_000_000.0, true, false, [], 'different-opaque-node'] as $id) {
            yield [$id];
        }
    }

    /** Both REST sides must explicitly retain the same nonempty opaque Node ID before GraphQL is requested. */
    #[DataProvider('missingRestNodeSides')]
    public function testMissingRestNodeIdentityFailsBeforeGraphql(string $side): void
    {
        $pr = $this->mergedPr();
        unset($pr[$side]['repo']['node_id']);
        $state = $this->state(['context' => true, 'pr' => $pr]);
        $failed = false;
        try {
            $this->service($state)->publishMerged(
                new ReleaseOptions('/consumer', repository: 'owner/repo'),
                7,
                str_repeat('b', 40),
                str_repeat('f', 40),
                automationActor: 'app[bot]',
                dryRun: true,
            );
        } catch (RuntimeException) {
            $failed = true;
        }
        self::assertTrue($failed);
        self::assertSame([['GET', '/repos/owner/repo/pulls/7', null]], $state['calls']);
        self::assertSame(0, $state['policy_calls']);
        self::assertSame(0, $state['evidence_calls']);
        self::assertSame([], $this->writes($state));
    }

    /** Removing either protocol identity must not be treated as a legacy fallback. */
    public static function missingRestNodeSides(): iterable
    {
        yield ['base'];
        yield ['head'];
    }

    /** Every incomplete, failed or mismatched GraphQL relationship must fail before ownership/domain or mutations. */
    #[DataProvider('invalidMergedGraphql')]
    public function testInvalidGraphqlCannotAuthorizeTheMerge(?array $proof): void
    {
        $state = $this->state(['context' => true, 'graphql' => $proof]);
        $failed = false;
        try {
            $this->service($state)->publishMerged(
                new ReleaseOptions('/consumer', repository: 'owner/repo'),
                7,
                str_repeat('b', 40),
                str_repeat('f', 40),
                automationActor: 'app[bot]',
                dryRun: true,
            );
        } catch (RuntimeException $error) {
            $failed = true;
            self::assertStringContainsString('GraphQL', $error->getMessage());
        }
        self::assertTrue($failed, 'GraphQL identity must be independently verified.');
        self::assertSame(0, $state['policy_calls']);
        self::assertSame(0, $state['evidence_calls']);
        self::assertSame([], $this->writes($state));
    }

    /** Supplies partial/errors and each independently bound identity, including a valid-looking wrong merge. */
    public static function invalidMergedGraphql(): iterable
    {
        yield 'unavailable' => [null];
        yield 'missing data' => [[]];
        yield 'null repository' => [['data' => ['repository' => null]]];
        yield 'scalar repository' => [['data' => ['repository' => 'owner/repo']]];
        $proof = self::mergedProof();
        $legacy = $proof;
        unset($legacy['data']['repository']['id']);
        $legacy['data']['repository']['databaseId'] = 11;
        $legacy['data']['repository']['fullDatabaseId'] = '11';
        yield 'numeric identities alone are insufficient' => [$legacy];
        yield 'partial data with errors' => [['errors' => [['message' => 'Partial result']], ...$proof]];
        yield 'malformed errors' => [['errors' => null, ...$proof]];
        foreach (['nameWithOwner' => 'fork/repo', 'id' => 'different-repository-node', 'pullRequest' => null] as $field => $value) {
            $changed = $proof;
            $changed['data']['repository'][$field] = $value;
            yield 'repository ' . $field => [$changed];
        }
        foreach (['number' => 8, 'state' => 'CLOSED', 'merged' => false,
            'headRefOid' => str_repeat('a', 40), 'headRefName' => 'human', 'baseRefName' => 'develop',
            'mergeCommit' => null] as $field => $value) {
            $changed = $proof;
            $changed['data']['repository']['pullRequest'][$field] = $value;
            yield 'pull request ' . $field => [$changed];
        }
        foreach ([null, 'abc', str_repeat('a', 40)] as $oid) {
            $changed = $proof;
            $changed['data']['repository']['pullRequest']['mergeCommit']['oid'] = $oid;
            yield 'merge ' . ($oid ?? 'null') => [$changed];
        }
    }

    /** Squash publication tags the merge, only after exact PR identity, signed ownership and contextual domain proof. */
    public function testMergedPublicationKeepsMergeTargetAndCreateOnlyReconciliation(): void
    {
        $state = $this->state(['context' => true]);
        $service = $this->service($state);
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo');
        self::assertSame(
            'published',
            $service->publishMerged(
                $options,
                7,
                str_repeat('b', 40),
                str_repeat('f', 40),
                automationActor: 'app[bot]',
            )->state,
        );
        self::assertSame(str_repeat('b', 40), $state['tag']['object']['sha']);
        self::assertSame(
            'unchanged',
            $service->publishMerged(
                $options,
                7,
                str_repeat('b', 40),
                str_repeat('f', 40),
                automationActor: 'app[bot]',
            )->state,
        );
        self::assertCount(2, $this->writes($state));
    }

    /** Read-only previews retain every authority guard while proposing the merge's missing remote objects. */
    public function testMergedPublicationDryRunNeverWrites(): void
    {
        $state = $this->state(['context' => true]);
        $result = $this->service($state)->publishMerged(
            new ReleaseOptions('/consumer', repository: 'owner/repo'),
            7,
            str_repeat('b', 40),
            str_repeat('f', 40),
            automationActor: 'app[bot]',
            dryRun: true,
        );
        self::assertSame('dry-run', $result->state);
        self::assertSame([], $this->writes($state));
    }

    /** No forged PR relationship, ownership or domain failure reaches a write. */
    #[DataProvider('invalidMergedPr')]
    public function testInvalidMergedPrOrOwnerCannotPublish(
        array $replacement,
        bool $owned = true,
        bool $invalidEvidence = false,
        ?array $account = [],
    ): void {
        $state = $this->state(
            ['context' => true, 'pr' => array_replace_recursive(
                $this->mergedPr(),
                $replacement,
            ), 'owned' => $owned, 'invalid_evidence' => $invalidEvidence],
        );
        $state['account'] = [] === $account ? $this->mergedPr()['user'] : $account;
        $failed = false;
        try {
            $this->service($state)->publishMerged(
                new ReleaseOptions('/consumer', repository: 'owner/repo'),
                7,
                str_repeat('b', 40),
                str_repeat('f', 40),
                automationActor: 'app[bot]',
            );
        } catch (RuntimeException|InvalidArgumentException) {
            $failed = true;
        }
        self::assertTrue($failed);
        self::assertSame([], $this->writes($state));
        if (array_key_exists('merge_commit_sha', $replacement)) {
            self::assertSame([['GET', '/repos/owner/repo/pulls/7', null]], $state['calls']);
            self::assertSame(0, $state['policy_calls']);
            self::assertSame(0, $state['evidence_calls']);
        }
    }

    /** Supplies adversarial authority bindings independently of domain/tree proofs. */
    public static function invalidMergedPr(): iterable
    {
        foreach ([['number' => 8], ['state' => 'open'], ['merged' => false],
            ['merge_commit_sha' => str_repeat('c', 40)], ['merge_commit_sha' => null],
            ['head' => ['sha' => str_repeat('c', 40)]],
            ['head' => ['repo' => ['full_name' => 'fork/repo']]], ['base' => ['repo' => ['full_name' => 'other/repo']]],
            ['head' => ['repo' => ['id' => 999]]], ['base' => ['repo' => ['id' => 0]]],
            ['base' => ['repo' => ['id' => '3000000000']], 'head' => ['repo' => ['id' => '3000000000']]],
            ['base' => ['repo' => ['id' => 3_000_000_000.0]], 'head' => ['repo' => ['id' => 3_000_000_000.0]]],
            ['base' => ['repo' => ['node_id' => null]]],
            ['base' => ['repo' => ['node_id' => '']], 'head' => ['repo' => ['node_id' => '']]],
            ['base' => ['repo' => ['node_id' => 11]], 'head' => ['repo' => ['node_id' => 11]]],
            ['base' => ['repo' => ['node_id' => false]], 'head' => ['repo' => ['node_id' => false]]],
            ['base' => ['repo' => ['node_id' => []]], 'head' => ['repo' => ['node_id' => []]]],
            ['head' => ['repo' => ['node_id' => null]]], ['head' => ['repo' => ['node_id' => '']]],
            ['head' => ['repo' => ['node_id' => true]]], ['head' => ['repo' => ['node_id' => []]]],
            ['head' => ['repo' => ['node_id' => 'different-repository-node']]],
            ['head' => ['ref' => 'human-branch']], ['base' => ['ref' => 'develop']],
            ['user' => ['type' => 'User']], ['user' => ['login' => 'other[bot]']]] as $replacement) {
            yield [$replacement];
        }
        yield 'invalid signature or scope' => [[], false];
        yield 'invalid options/inventory/domain' => [[], true, true];
        yield 'unavailable current account' => [[], true, false, null];
        yield 'different Bot identity' => [[], true, false, ['login' => 'app[bot]', 'type' => 'Bot', 'id' => 999]];
    }

    /** Original injected interfaces remain valid for generic publishing and fail closed for the additional context path. */
    public function testLegacyPublisherCapabilitiesCannotBecomeAMergedFallback(): void
    {
        $state = $this->state();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contextual proof capabilities');
        $this->service($state)->publishMerged(
            new ReleaseOptions('/consumer', repository: 'owner/repo'),
            7,
            str_repeat('b', 40),
            str_repeat('f', 40),
        );
    }
    /** Both create payloads MUST pin the approved SHA and exact central notes without generated notes. */
    public function testFreshPublicationCreatesExactTagAndReleaseThenRepeatsWithoutMutation(): void
    {
        $state = $this->state();
        $service = $this->service($state);
        $result = $service->publish(new ReleaseOptions('/consumer', repository: 'owner/repo'), str_repeat('b', 40));
        self::assertSame('published', $result->state);
        self::assertSame(['create_tag', 'create_release'], $result->actions);
        self::assertSame('https://github.com/owner/repo/releases/tag/v1.0.1', $result->url);
        $writes = $this->writes($state);
        self::assertSame([
            ['POST', '/repos/owner/repo/git/refs', ['ref' => 'refs/tags/v1.0.1', 'sha' => str_repeat('b', 40)]],
            ['POST', '/repos/owner/repo/releases', ['tag_name' => 'v1.0.1', 'target_commitish' => str_repeat(
                'b',
                40,
            ), 'name' => 'v1.0.1', 'body' => "Exact  notes\n", 'draft' => false, 'prerelease' => false, 'generate_release_notes' => false]],
        ], $writes);
        $again = $service->publish(new ReleaseOptions('/consumer', repository: 'owner/repo'), str_repeat('b', 40));
        self::assertSame('unchanged', $again->state);
        self::assertSame([], $again->actions);
        self::assertSame($writes, $this->writes($state));
    }

    /** Read-only planning MUST report exactly the missing objects without any POST/PATCH/DELETE. */
    #[DataProvider('dryRunStates')]
    public function testDryRunReadsRemoteStateAndReturnsOnlyProposedActions(
        bool $tagExists,
        bool $releaseExists,
        array $actions,
    ): void {
        $state = $this->state();
        if ($tagExists) {
            $state['tag'] = $this->tag();
        }
        if ($releaseExists) {
            $state['release'] = $this->release();
        }
        $result = $this->service($state)->publish(
            new ReleaseOptions('/consumer', repository: 'owner/repo'),
            str_repeat('b', 40),
            true,
        );
        self::assertSame('dry-run', $result->state);
        self::assertSame($actions, $result->actions);
        self::assertSame([], $this->writes($state));
        self::assertSame($releaseExists ? $this->release()['html_url'] : null, $result->url);
    }

    /** Supplies empty, tag-only and complete remote publication states. */
    public static function dryRunStates(): iterable
    {
        yield [false, false, ['create_tag', 'create_release']];
        yield [true, false, ['create_release']];
        yield [true, true, []];
    }

    /** Maintenance MUST return explicitly without requesting any remote endpoint. */
    public function testMaintenanceNeverCreatesTagOrReleaseEvenOutsideDryRun(): void
    {
        $state = $this->state(['maintenance' => true]);
        $result = $this->service($state)->publish(new ReleaseOptions('/consumer'), str_repeat('b', 40));
        self::assertSame('maintenance', $result->state);
        self::assertNull($result->version);
        self::assertNull($result->tag);
        self::assertNull($result->url);
        self::assertSame([], $result->actions);
        self::assertSame([], $state['calls']);
    }

    /** A rejected local evidence proof MUST prevent even the first GitHub request. */
    public function testEvidenceFailurePreventsAllRemoteRequests(): void
    {
        $state = $this->state(['invalid_evidence' => true]);
        try {
            $this->service($state)->publish(new ReleaseOptions('/consumer'), str_repeat('b', 40));
            self::fail('Invalid local proof must fail.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('invalid local evidence', $error->getMessage());
        }
        self::assertSame([], $state['calls']);
    }

    /** An existing conflicting tag MUST never be moved or allowed to acquire a release. */
    public function testConflictingTagFailsWithoutMutation(): void
    {
        $state = $this->state(['tag' => $this->tag(str_repeat('a', 40))]);
        $this->failure($state, 'already points to another commit');
        self::assertSame([], $this->writes($state));
    }

    /** A release whose tag was removed MUST not silently be associated with a newly created target. */
    public function testExistingReleaseWithoutTagIsDivergence(): void
    {
        $state = $this->state(['release' => $this->release()]);
        $this->failure($state, 'no verified matching tag');
        self::assertSame([], $this->writes($state));
    }

    /** Lightweight and safely peeled annotated tags MUST converge on the same approved commit. */
    public function testAnnotatedTagsAreDereferencedThroughBoundedRelativeEndpoints(): void
    {
        $object = str_repeat('a', 40);
        $state = $this->state(
            ['tag' => ['ref' => 'refs/tags/v1.0.1', 'object' => ['type' => 'tag', 'sha' => $object, 'url' => 'https://untrusted.invalid']],
                'annotated' => [$object => ['sha' => $object, 'object' => ['type' => 'commit', 'sha' => str_repeat(
                    'b',
                    40,
                )]]], 'release' => $this->release()],
        );
        $result = $this->service($state)->publish(new ReleaseOptions('/consumer'), str_repeat('b', 40));
        self::assertSame('unchanged', $result->state);
        self::assertSame([], $this->writes($state));
        self::assertContains(['GET', '/repos/owner/repo/git/tags/' . $object, null], $state['calls']);
        foreach ($state['calls'] as $call) {
            self::assertStringStartsWith('/repos/owner/repo/', $call[1]);
        }
    }

    /** Invalid ref objects and malformed/cyclic annotated chains MUST fail before mutation. */
    #[DataProvider('invalidTagObjects')]
    public function testInvalidRemoteTagObjectsAreRejected(array $tag, array $annotated, string $message): void
    {
        $state = $this->state(['tag' => $tag, 'annotated' => $annotated]);
        $this->failure($state, $message);
        self::assertSame([], $this->writes($state));
    }

    /** Supplies wrong refs, unsupported object kinds, incomplete hashes and unsafe annotated responses. */
    public static function invalidTagObjects(): iterable
    {
        $sha = str_repeat('a', 40);
        yield [['ref' => 'refs/tags/other', 'object' => []], [], 'invalid exact tag reference'];
        yield [['ref' => 'refs/tags/v1.0.1'], [], 'invalid exact tag reference'];
        yield [['ref' => 'refs/tags/v1.0.1', 'object' => ['type' => 'commit']], [], 'invalid tag object identity'];
        yield [['ref' => 'refs/tags/v1.0.1', 'object' => ['type' => 'commit', 'sha' => 'abc']], [], 'invalid tag object identity'];
        yield [['ref' => 'refs/tags/v1.0.1', 'object' => ['type' => 'blob', 'sha' => $sha]], [], 'must terminate in a commit'];
        $tag = ['ref' => 'refs/tags/v1.0.1', 'object' => ['type' => 'tag', 'sha' => $sha]];
        yield [$tag, [], 'invalid annotated tag object'];
        yield [$tag, [$sha => ['sha' => 'wrong', 'object' => []]], 'invalid annotated tag object'];
        yield [$tag, [$sha => ['sha' => $sha]], 'invalid annotated tag object'];
        yield [$tag, [$sha => ['sha' => $sha, 'object' => ['type' => 'tag', 'sha' => $sha]]], 'must terminate in a commit'];
        $chain = [];
        for ($index = 1; $index <= 9; ++$index) {
            $oid = str_repeat((string) $index, 40);
            $chain[$oid] = ['sha' => $oid, 'object' => ['type' => 'tag', 'sha' => str_repeat(
                (string) ($index + 1),
                40,
            )]];
        }
        yield [['ref' => 'refs/tags/v1.0.1', 'object' => ['type' => 'tag', 'sha' => str_repeat(
            '1',
            40,
        )]], $chain, 'excessive nesting'];
    }

    /** Existing published releases MUST preserve exact notes, tag identity and stable state. */
    #[DataProvider('divergentReleases')]
    public function testDivergentReleaseFailsBeforeAnyMutation(array $changes): void
    {
        $state = $this->state(['tag' => $this->tag(), 'release' => array_replace($this->release(), $changes)]);
        $this->failure($state, 'differs from the exact approved');
        self::assertSame([], $this->writes($state));
    }

    /** Supplies all rejected identity/body/state and URL shapes. */
    public static function divergentReleases(): iterable
    {
        yield [['tag_name' => 'other']];
        yield [['body' => 'edited']];
        yield [['draft' => true]];
        yield [['prerelease' => true]];
        yield [['html_url' => null]];
        yield [['html_url' => 'http://github.com/release']];
        yield [['html_url' => 'https://user@github.com/release']];
        yield [['html_url' => "https://github.com/space release"]];
    }

    /** Uncertain POST responses MUST be recovered by persisted objects, including concurrent create conflicts. */
    public function testLostTagAndReleaseResponsesAreRecoveredWithoutDuplicatingPublication(): void
    {
        $state = $this->state(['lost_tag_response' => true, 'lost_release_response' => true]);
        $result = $this->service($state)->publish(new ReleaseOptions('/consumer'), str_repeat('b', 40));
        self::assertSame('published', $result->state);
        self::assertCount(2, $this->writes($state));
        self::assertSame(str_repeat('b', 40), $state['tag']['object']['sha']);
        self::assertSame("Exact  notes\n", $state['release']['body']);
    }

    /** A failed tag create MUST not proceed to release creation when no target is verified. */
    public function testUnverifiedTagFailureKeepsReleaseAbsentAndRetainsCause(): void
    {
        $state = $this->state(['fail_tag' => true]);
        $error = $this->failure($state, 'Tag creation outcome');
        self::assertInstanceOf(RuntimeException::class, $error->getPrevious());
        self::assertCount(1, $this->writes($state));
        self::assertNull($state['release']);
    }

    /** A tag-only partial result MUST be safely completed on a later invocation without recreating the tag. */
    public function testReleaseFailureRetainsTagAndNextInvocationCreatesOnlyTheRelease(): void
    {
        $state = $this->state(['fail_release' => true]);
        $service = $this->service($state);
        $this->failure($state, 'matching tag is retained', $service);
        self::assertSame(str_repeat('b', 40), $state['tag']['object']['sha']);
        self::assertNull($state['release']);
        $state['fail_release'] = false;
        $state['calls'] = [];
        $result = $service->publish(new ReleaseOptions('/consumer'), str_repeat('b', 40));
        self::assertSame('published', $result->state);
        self::assertSame(['create_release'], $result->actions);
        self::assertSame(['/repos/owner/repo/releases'], array_column($this->writes($state), 1));
    }

    /** External tag changes between reads MUST fail without any force/update/delete request. */
    #[DataProvider('tagRaces')]
    public function testRemoteTagDivergenceDuringPublicationIsNeverRewritten(
        bool $existingTag,
        bool $existingRelease,
        int $moveAt,
        string $message,
    ): void {
        $state = $this->state(['move_at' => $moveAt]);
        if ($existingTag) {
            $state['tag'] = $this->tag();
        }
        if ($existingRelease) {
            $state['release'] = $this->release();
        }
        $this->failure($state, $message);
        foreach ($state['calls'] as $call) {
            self::assertContains($call[0], ['GET', 'POST']);
        }
    }

    /** Supplies races after tag creation, before release creation and after complete publication. */
    public static function tagRaces(): iterable
    {
        yield [false, false, 2, 'Tag creation outcome'];
        yield [true, false, 2, 'changed before release'];
        yield [true, true, 2, 'changed during publication'];
    }

    /** Returns an isolated recording remote state; no real token, filesystem or network is consulted. */
    private function state(array $changes = []): array
    {
        return array_replace([
            'tag' => null, 'release' => null, 'annotated' => [], 'calls' => [], 'tag_reads' => 0, 'move_at' => null,
            'maintenance' => false, 'invalid_evidence' => false, 'fail_tag' => false, 'fail_release' => false,
            'lost_tag_response' => false, 'lost_release_response' => false,
            'policy_calls' => 0, 'evidence_calls' => 0],
            $changes,
        );
    }

    /** Provides a canonical remote lightweight tag response. */
    private function tag(?string $sha = null): array
    {
        return ['ref' => 'refs/tags/v1.0.1', 'object' => ['type' => 'commit', 'sha' => $sha ?? str_repeat('b', 40)]];
    }

    /** Provides a canonical already-published release with exact original note bytes. */
    private function release(): array
    {
        return ['tag_name' => 'v1.0.1', 'body' => "Exact  notes\n", 'draft' => false, 'prerelease' => false, 'html_url' => 'https://github.com/owner/repo/releases/tag/v1.0.1'];
    }

    /** Filters only actual HTTP mutations recorded by deterministic doubles. */
    private function writes(array $state): array
    {
        return array_values(array_filter($state['calls'], static fn(array $call): bool => 'GET' !== $call[0]
            && ! ('POST' === $call[0] && '/graphql' === $call[1])));
    }

    /** Captures a useful failure while allowing tests to inspect the unchanged remote world. */
    private function failure(array &$state, string $message, ?PublicationService $service = null): RuntimeException
    {
        try {
            ($service ?? $this->service($state))->publish(new ReleaseOptions('/consumer'), str_repeat('b', 40));
            self::fail('Publication divergence must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString($message, $error->getMessage());

            return $error;
        }
    }

    /** Supplies only recording/stubbed boundaries; GET/POST behavior stays entirely in memory. */
    private function service(array &$state): PublicationService
    {
        $evidence = $this->createStub(
            ($state['context'] ?? false) ? MergedPublicationEvidenceValidatorInterface::class : PublicationEvidenceValidatorInterface::class,
        );
        $prove = static function (ReleaseOptions $options, string $sha) use (
            &$state
        ): PublicationEvidence {
            self::assertSame(str_repeat('b', 40), $sha);
            ++$state['evidence_calls'];
            if ($state['invalid_evidence']) {
                throw new InvalidArgumentException('invalid local evidence');
            }

            return new PublicationEvidence(
                $sha,
                $state['maintenance'] ? null : '1.0.1',
                $state['maintenance'] ? null : 'v1.0.1',
                "Exact  notes\n",
                'owner/repo',
            );
        };
        $evidence->method('validate')->willReturnCallback($prove);
        if ($state['context'] ?? false) {
            $evidence->method('validateMerged')->willReturnCallback(
                static function (ReleaseOptions $options, string $merge, string $head) use (
                    $prove
                ): PublicationEvidence {
                    self::assertSame(str_repeat('f', 40), $head);

                    return $prove($options, $merge);
                },
            );
        }
        $github = $this->createStub(GitHubClientInterface::class);
        $github->method('request')->willReturnCallback(function (string $method, string $path, ?array $body = null) use (
            &$state
        ): ?array {
            $state['calls'][] = [$method, $path, $body];
            if ('GET' === $method && '/repos/owner/repo/pulls/7' === $path) {
                return $state['pr'] ?? $this->mergedPr();
            }
            if ('POST' === $method && '/graphql' === $path) {
                self::assertSame(self::mergedQuery(), $body['query']);
                self::assertSame(['owner' => 'owner', 'name' => 'repo', 'number' => 7], $body['variables']);

                return array_key_exists('graphql', $state) ? $state['graphql'] : self::mergedProof();
            }
            if ('GET' === $method && '/users/app%5Bbot%5D' === $path) {
                return array_key_exists('account', $state) ? $state['account'] : $this->mergedPr()['user'];
            }
            if ('GET' === $method && str_contains($path, '/git/ref/tags/')) {
                ++$state['tag_reads'];
                if ($state['tag_reads'] === $state['move_at']) {
                    $state['tag'] = $this->tag(str_repeat('c', 40));
                }

                return $state['tag'];
            }
            if ('GET' === $method && str_contains($path, '/git/tags/')) {
                return $state['annotated'][basename($path)] ?? null;
            }
            if ('GET' === $method && str_contains($path, '/releases/tags/')) {
                return $state['release'];
            }
            if ('POST' === $method && str_ends_with($path, '/git/refs')) {
                if ($state['fail_tag']) {
                    throw new RuntimeException('tag HTTP failure');
                }
                $state['tag'] = $this->tag($body['sha']);
                if ($state['lost_tag_response']) {
                    throw new RuntimeException('lost tag response');
                }

                return $state['tag'];
            }
            if ('POST' === $method && str_ends_with($path, '/releases')) {
                if ($state['fail_release']) {
                    throw new RuntimeException('release HTTP failure');
                }
                $state['release'] = $this->release();
                if ($state['lost_release_response']) {
                    throw new RuntimeException('lost release response');
                }

                return $state['release'];
            }
            self::fail('Unexpected HTTP operation: ' . $method . ' ' . $path);
        });
        if ($state['real_http'] ?? false) {
            $recorded = $github;
            $http = $this->createMock(HttpClientInterface::class);
            $http->expects(self::exactly(5))->method('request')->willReturnCallback(
                function (string $method, string $url, array $options) use ($recorded): ResponseInterface {
                    self::assertSame([
                        'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2026-03-10',
                        'User-Agent' => 'FastForward-Changelog', 'Authorization' => 'Bearer synthetic-token',
                    ], $options['headers']);
                    self::assertSame(0, $options['max_redirects']);
                    self::assertStringStartsWith('https://api.github.com/', $url);
                    $data = $recorded->request(
                        $method,
                        substr($url, strlen('https://api.github.com')),
                        $options['json'] ?? null,
                    );
                    $response = $this->createStub(ResponseInterface::class);
                    $response->method('getStatusCode')->willReturn(null === $data ? 404 : 200);
                    $response->method('toArray')->willReturn($data ?? []);
                    $response->method('getHeaders')->willReturn([]);

                    return $response;
                },
            );
            $github = new GitHubClient($http, 'synthetic-token');
        }
        $policy = null;
        if ($state['context'] ?? false) {
            $policy = $this->createStub(PullRequestPolicyInterface::class);
            $policy->method('inspectHead')->willReturnCallback(
                static function (ReleaseOptions $options, string $head, string $actor, string $merge) use (
                    &$state
                ): bool {
                    self::assertSame(str_repeat('f', 40), $head);
                    self::assertSame('app[bot]', $actor);
                    self::assertSame(str_repeat('b', 40), $merge);
                    ++$state['policy_calls'];

                    return $state['owned'] ?? true;
                },
            );
        }

        return new PublicationService($evidence, $github, $policy);
    }

    /** Provides immutable authority metadata without treating mutable branch or PR prose as evidence. */
    private function mergedPr(): array
    {
        return ['number' => 7, 'state' => 'closed', 'merged' => true, 'merge_commit_sha' => str_repeat('b', 40),
            'user' => ['login' => 'app[bot]', 'type' => 'Bot', 'id' => 123],
            'head' => ['sha' => str_repeat(
                'f',
                40,
            ), 'ref' => 'changelog/version', 'repo' => ['full_name' => 'owner/repo', 'id' => 11, 'node_id' => 'opaque-repository-node']],
            'base' => ['ref' => 'main', 'repo' => ['full_name' => 'owner/repo', 'id' => 11, 'node_id' => 'opaque-repository-node']]];
    }

    /** Pins the one permitted query so a POST fixture can never silently accept a mutation. */
    private static function mergedQuery(): string
    {
        return <<<'GRAPHQL'
query ChangelogMergedPullRequest($owner: String!, $name: String!, $number: Int!) {
  repository(owner: $owner, name: $name) {
    nameWithOwner
    id
    pullRequest(number: $number) {
      number
      state
      merged
      headRefOid
      headRefName
      baseRefName
      mergeCommit { oid }
    }
  }
}
GRAPHQL;
    }

    /** Represents complete identity evidence from the same repository and immutable managed PR head. */
    private static function mergedProof(): array
    {
        return ['data' => ['repository' => ['nameWithOwner' => 'owner/repo', 'id' => 'opaque-repository-node',
            'pullRequest' => ['number' => 7, 'state' => 'MERGED', 'merged' => true,
                'headRefOid' => str_repeat('f', 40), 'headRefName' => 'changelog/version',
                'baseRefName' => 'main', 'mergeCommit' => ['oid' => str_repeat('b', 40)]]]]];
    }
}
