<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Publication;

use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Publication\Factory\PublicationResultFactoryInterface;
use FastForward\Changelog\Publication\PublicationEvidence;
use FastForward\Changelog\Publication\PublicationResult;
use FastForward\Changelog\Publication\PublicationService;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Validator\PublicationEvidenceValidatorInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(PublicationService::class)]
#[UsesClass(PublicationResult::class)]
#[UsesClass(PublicationEvidence::class)]
#[UsesClass(ReleaseOptions::class)]
final class PublicationServiceTest extends TestCase
{
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
            'lost_tag_response' => false, 'lost_release_response' => false],
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
        return array_values(array_filter($state['calls'], static fn(array $call): bool => 'GET' !== $call[0]));
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
        $evidence = $this->createStub(PublicationEvidenceValidatorInterface::class);
        $evidence->method('validate')->willReturnCallback(static function (ReleaseOptions $options, string $sha) use (
            &$state
        ): PublicationEvidence {
            self::assertSame(str_repeat('b', 40), $sha);
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
        });
        $github = $this->createStub(GitHubClientInterface::class);
        $github->method('request')->willReturnCallback(function (string $method, string $path, ?array $body = null) use (
            &$state
        ): ?array {
            $state['calls'][] = [$method, $path, $body];
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
        $results = $this->createStub(PublicationResultFactoryInterface::class);
        $results->method('create')->willReturnCallback(
            static fn(string $state, ?string $version, ?string $tag, string $sha, ?string $url, array $actions): PublicationResult => new PublicationResult(
                $state,
                $version,
                $tag,
                $sha,
                $url,
                $actions,
            ),
        );
        $exceptions = $this->createStub(ReleaseExceptionFactoryInterface::class);
        $exceptions->method('failure')->willReturnCallback(
            static fn(string $message, ?Throwable $previous = null): RuntimeException => new RuntimeException(
                $message,
                previous: $previous,
            ),
        );

        return new PublicationService($evidence, $github, $results, $exceptions);
    }
}
