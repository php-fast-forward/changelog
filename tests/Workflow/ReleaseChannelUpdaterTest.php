<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Workflow;

use FastForward\Changelog\Repository\ReleaseChannelUpdater;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/.github/ReleaseChannelUpdater.php';
require_once __DIR__ . '/ReleaseChannelFixture.php';

#[CoversClass(ReleaseChannelUpdater::class)]
final class ReleaseChannelUpdaterTest extends TestCase
{
    private ReleaseChannelFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new ReleaseChannelFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->close();
    }

    public function testCreatesOnlyTheReleasedMajorBranchIndependentlyOfMain(): void
    {
        $f = $this->fixture;
        $tags = $f->git(['show-ref', '--tags']);
        self::assertSame('created', $f->run()['state']);
        self::assertSame($f->commits['v0.2.0'], $f->git(['rev-parse', 'refs/heads/v0']));
        self::assertNotSame($f->commits['unreleased'], $f->commits['v0.2.0']);
        self::assertSame($tags, $f->git(['show-ref', '--tags']));
        self::assertSame(str_repeat('0', 40), $f->mutations[0]['beforeOid']);
        self::assertSame('current', $f->run()['state']);
        self::assertCount(1, $f->mutations);
    }

    public function testAdvancesWithAnExactLeaseAndNeverRegresses(): void
    {
        $f = $this->fixture;
        $f->git(['branch', 'v0', $f->commits['v0.1.0']]);
        self::assertSame('advanced', $f->run()['state']);
        self::assertSame($f->commits['v0.1.0'], $f->mutations[0]['beforeOid']);
        self::assertSame('superseded', $f->run('v0.1.0')['state']);
        self::assertCount(1, $f->mutations);
    }

    #[DataProvider('invalidSelectors')]
    public function testRejectsIncompleteUnstableOrUnsafeSelectors(string $tag): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('complete stable');
        $this->fixture->run($tag);
    }

    public static function invalidSelectors(): iterable
    {
        foreach (['v0', 'v0.1', 'v00.1.0', 'v0.2.0-rc.1', 'main', '../v0.2.0'] as $tag) {
            yield $tag => [$tag];
        }
    }

    #[DataProvider('invalidReleaseEvidence')]
    public function testRejectsUnpublishedOrMismatchedReleaseEvidence(string $kind): void
    {
        $f = $this->fixture;
        if ('missing' === $kind) {
            unset($f->releases['v0.2.0']);
        } elseif ('unpublished' === $kind) {
            $f->releases['v0.2.0']['published_at'] = null;
        } elseif ('sha' !== $kind) {
            $f->releases['v0.2.0'][$kind] = true;
        }
        $this->expectException(RuntimeException::class);
        $f->run('v0.2.0', 'sha' === $kind ? $f->commits['unreleased'] : null);
    }

    public static function invalidReleaseEvidence(): iterable
    {
        foreach (['draft', 'prerelease', 'unpublished', 'missing', 'sha'] as $kind) {
            yield $kind => [$kind];
        }
    }

    public function testRejectsAConflictingTagWithoutDeletingIt(): void
    {
        $f = $this->fixture;
        $f->git(['tag', 'v0', $f->commits['v0.1.0']]);
        $this->expectExceptionMessage('conflicting channel tag');
        $f->run();
    }

    public function testRejectsAnUnrelatedExistingBranch(): void
    {
        $f = $this->fixture;
        $f->git(['branch', 'v0', $f->commits['unreleased']]);
        $this->expectExceptionMessage('not an older published');
        $f->run();
    }

    public function testRejectsStaleRecoveryBeforeCreatingAnOldChannel(): void
    {
        $this->expectExceptionMessage('newest published');
        $this->fixture->run('v0.1.0');
    }

    public function testUsesNumericSemverWithoutIntegerOverflow(): void
    {
        $f = $this->fixture;
        foreach (['v0.9.0', 'v0.10.0', 'v0.90071992547409931234567890.0'] as $index => $tag) {
            $f->git(['tag', $tag, 0 === $index ? $f->commits['v0.1.0'] : $f->commits['v0.2.0']]);
            $f->releases[$tag] = [...$f->releases['v0.2.0'], 'id' => $index + 3, 'tag_name' => $tag];
        }
        $f->git(['branch', 'v0', $f->commits['v0.1.0']]);
        self::assertSame('advanced', $f->run($tag, $f->commits['v0.2.0'])['state']);
    }

    public function testRejectsDivergentReleasedHistory(): void
    {
        $f = $this->fixture;
        $f->git(['branch', 'v0', $f->commits['v0.1.0']]);
        $f->git(['checkout', '--orphan', 'divergent']);
        $f->git(['commit', '--no-gpg-sign', '--allow-empty', '-m', 'Synthetic divergent release']);
        $sha = $f->git(['rev-parse', 'HEAD']);
        $f->git(['tag', 'v0.3.0', $sha]);
        $f->releases['v0.3.0'] = [...$f->releases['v0.2.0'], 'id' => 3, 'tag_name' => 'v0.3.0'];
        $this->expectExceptionMessage('fast-forward');
        $f->run('v0.3.0', $sha);
    }

    public function testPeelsAnnotatedTagsAndRejectsCycles(): void
    {
        $f = $this->fixture;
        $f->git(['tag', '--delete', 'v0.2.0']);
        $f->git(['tag', '-a', 'v0.2.0', $f->commits['v0.2.0'], '-m', 'Synthetic annotated release']);
        self::assertSame('created', $f->run()['state']);
        $f->git(['branch', '--delete', 'v0']);
        $f->tagCycle = true;
        $this->expectExceptionMessage('bounded commit');
        $f->run();
    }

    public function testRevalidatesTheTagImmediatelyBeforeMutation(): void
    {
        $f = $this->fixture;
        $f->releaseRead = static function (int $count) use ($f): void {
            if (2 === $count) {
                $f->git(['tag', '-f', 'v0.2.0', $f->commits['unreleased']]);
            }
        };
        $this->expectExceptionMessage('do not match');
        $f->run();
    }

    public function testRejectsAnAnnotatedObjectWithTheWrongOwnOid(): void
    {
        $f = $this->fixture;
        $f->git(['tag', '--delete', 'v0.2.0']);
        $f->git(['tag', '-a', 'v0.2.0', $f->commits['v0.2.0'], '-m', 'Synthetic annotated release']);
        $f->wrongTagIdentity = true;
        $this->expectExceptionMessage('object identity');
        $f->run();
    }

    public function testDoesNotHideAHigherStableReleaseWithPartialPublicationMetadata(): void
    {
        $f = $this->fixture;
        $f->releases['partial'] = [...$f->releases['v0.2.0'], 'id' => null, 'tag_name' => 'v0.3.0'];
        $this->expectExceptionMessage('Partial stable publication');
        $f->run();
    }

    #[DataProvider('invalidPublicationTimes')]
    public function testRejectsNonCanonicalOrImpossiblePublicationTimes(string $timestamp): void
    {
        $f = $this->fixture;
        $f->releases['partial'] = [...$f->releases['v0.2.0'], 'id' => 3,
            'tag_name' => 'v0.3.0', 'published_at' => $timestamp];
        $this->expectExceptionMessage('Partial stable publication');
        $f->run();
    }

    public static function invalidPublicationTimes(): iterable
    {
        foreach (['tomorrow', '2026-02-31T00:00:00Z', '2026-01-01T24:00:00Z',
            '2026-01-01T00:00:00+01:00', ''] as $timestamp) {
            yield [$timestamp];
        }
    }

    public function testRejectsANewerReleaseDiscoveredBeforeMutation(): void
    {
        $f = $this->fixture;
        $f->catalog = static fn(int $count): array => 1 === $count ? array_values($f->releases)
            : [...array_values($f->releases), [...$f->releases['v0.2.0'], 'id' => 3, 'tag_name' => 'v0.3.0']];
        $this->expectExceptionMessage('changed before');
        $f->run();
    }

    public function testRejectsWithdrawnPreviousChannelEvidence(): void
    {
        $f = $this->fixture;
        $f->git(['branch', 'v0', $f->commits['v0.1.0']]);
        $f->catalog = static fn(int $count): array => 1 === $count ? array_values($f->releases) : [
            $f->releases['v0.2.0'],
        ];
        $this->expectExceptionMessage('previous channel release evidence');
        $f->run();
    }

    public function testRejectsAnUnboundedCatalog(): void
    {
        $f = $this->fixture;
        $f->catalog = static fn(): array => array_fill(0, 100, $f->releases['v0.2.0']);
        $this->expectExceptionMessage('verification limit');
        $f->run();
    }

    public function testRejectsAmbiguousHighestBuildOnlyVersions(): void
    {
        $f = $this->fixture;
        $f->releases['build'] = [...$f->releases['v0.2.0'], 'id' => 3, 'tag_name' => 'v0.2.0+build.1'];
        $this->expectExceptionMessage('Build-only');
        $f->run();
    }

    public function testRejectsRepositoryIdentityMismatch(): void
    {
        $this->fixture->repository = 'foreign/library';
        $this->expectExceptionMessage('repository identity');
        $this->fixture->run();
    }

    public function testRejectsMalformedExactRefs(): void
    {
        $f = $this->fixture;
        $f->referenceOverride = static fn(): array => ['ref' => 'refs/tags/foreign',
            'object' => ['type' => 'commit', 'sha' => $f->commits['v0.2.0']]];
        $this->expectExceptionMessage('Malformed exact');
        $f->run();
    }

    public function testActualGitCasRejectsAnInterleavedCreatorAndPreservesTheWinner(): void
    {
        $f = $this->fixture;
        $f->beforeMutation = static function () use ($f): void {
            $f->git(['update-ref', 'refs/heads/v0', $f->commits['v0.2.0']]);
        };
        try {
            $f->run();
            self::fail('A concurrent creation must fail the zero-OID lease.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('conditional channel update was rejected', $error->getMessage());
        }
        self::assertCount(0, $f->mutations);
        self::assertSame($f->commits['v0.2.0'], $f->git(['rev-parse', 'refs/heads/v0']));
        self::assertSame('current', $f->run()['state']);
    }

    /** Proves a refused write retains the absent ref and exposes only fixed, recognized diagnostic categories. */
    public function testReportsOnlyRecognizedGraphqlErrorTypesWithoutServerMessages(): void
    {
        $f = $this->fixture;
        $f->mutationErrors = [
            ['type' => 'FORBIDDEN', 'message' => 'synthetic-secret-do-not-print'],
            ['type' => 'FORBIDDEN'],
            ['type' => 'synthetic-secret-do-not-print'],
        ];

        try {
            $f->run();
            self::fail('A rejected mutation must fail.');
        } catch (RuntimeException $error) {
            self::assertSame(
                'The conditional channel update was rejected (FORBIDDEN); verify token permissions and the ref before retrying.',
                $error->getMessage(),
            );
        }

        self::assertCount(0, $f->mutations);
        self::assertNull($f->git(['show-ref', '--verify', '--hash', 'refs/heads/v0'], true));
    }

    public function testRecoversAnUncertainAcceptedMutationWithoutAnotherWrite(): void
    {
        $f = $this->fixture;
        $f->loseResponse = true;
        try {
            $f->run();
            self::fail('The uncertain response must remain a failure.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('HTTP 500', $error->getMessage());
        }
        $f->loseResponse = false;
        self::assertSame('current', $f->run()['state']);
        self::assertCount(1, $f->mutations);
    }
}
