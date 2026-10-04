<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation\VersionPullRequest;

use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestExceptionFactoryInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestInputFactoryInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestResultFactoryInterface;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestException;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestInput;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestResult;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestService;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Release\ReceiptCodecInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use FastForward\Changelog\Release\ReleaseReceipt;
use FastForward\Changelog\Tests\Automation\Policy\PolicyFixtureTrait;
use FastForward\Changelog\Validator\ReleaseInputEvidenceValidatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(VersionPullRequestService::class)]
#[UsesClass(GitHubEvidence::class)]
#[UsesClass(VersionPullRequestException::class)]
#[UsesClass(VersionPullRequestInput::class)]
#[UsesClass(VersionPullRequestResult::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(ReleasePlan::class)]
#[UsesClass(ReleaseReceipt::class)]
final class VersionPullRequestServiceTest extends TestCase
{
    use PolicyFixtureTrait;

    private array $calls = [];
    private array $policyCalls = [];
    private array $plannerCalls = [];

    #[Test]
    public function createsSignedVersionTransactionFromFreshBaseWithOnlyApprovedFileChanges(): void
    {
        $result = $this->synchronizeFixture();
        self::assertSame('created', $result->status);
        self::assertSame(7, $result->prNumber);
        self::assertSame(str_repeat('c', 40), $result->headSha);
        self::assertSame('1.0.1', $result->version);
        self::assertFalse($result->maintenance);
        self::assertSame(['version'], $this->plannerCalls);
        $trees = $this->writes('trees');
        self::assertSame(str_repeat('d', 40), $trees[0][2]['base_tree']);
        self::assertSame(['CHANGELOG.md', '.changelog/feature.md'], array_column($trees[0][2]['tree'], 'path'));
        self::assertNull($trees[0][2]['tree'][1]['sha']);
        $commit = $this->writes('commits')[0][2];
        self::assertSame([str_repeat('b', 40)], $commit['parents']);
        self::assertArrayNotHasKey('author', $commit);
        self::assertArrayNotHasKey('committer', $commit);
        self::assertArrayNotHasKey('signature', $commit);
        self::assertStringContainsString('Changelog-Plan: ' . str_repeat('c', 64), $commit['message']);
        self::assertSame(['ref' => 'refs/heads/changelog/version', 'sha' => str_repeat('c', 40)], $this->writes('refs')[0][2]);
        self::assertStringContainsString('does not publish', $this->writes('pulls')[0][2]['body']);
    }

    #[Test]
    public function updatesSameOwnedPrWithOldHeadAndNewBaseParentsWithoutForce(): void
    {
        $result = $this->synchronizeFixture(['existing' => true]);
        self::assertSame('updated', $result->status);
        self::assertSame(7, $result->prNumber);
        self::assertSame([str_repeat('a', 40), str_repeat('b', 40)], $this->writes('commits')[0][2]['parents']);
        self::assertSame(['sha' => str_repeat('c', 40), 'force' => false], $this->writes('refs/heads')[0][2]);
        self::assertSame('PATCH', $this->writes('pulls/7')[0][0]);
        self::assertSame(['inspectHead', str_repeat('a', 40)], $this->policyCalls[0]);
    }

    #[Test]
    public function samePlanDoesNotGenerateEndlessCommitsOrAnotherPr(): void
    {
        $result = $this->synchronizeFixture(['existing' => true, 'same_plan' => true]);
        self::assertSame('unchanged', $result->status);
        self::assertSame([], $this->writes());
    }

    #[Test]
    public function orphanSamePlanRecoversOnlyMissingPrAndKeepsTheCommit(): void
    {
        $result = $this->synchronizeFixture(['orphan' => true, 'same_plan' => true]);
        self::assertSame('created', $result->status);
        self::assertSame(str_repeat('a', 40), $result->headSha);
        self::assertCount(1, $this->writes());
        self::assertSame('POST', $this->writes()[0][0]);
        self::assertStringEndsWith('/pulls', $this->writes()[0][1]);
    }

    #[Test]
    #[TestWith([['orphan' => true]])]
    #[TestWith([['base_orphan' => true]])]
    #[TestWith([['base_existing' => true]])]
    public function recoversTrustedOrInitialOrphanBranchWithoutForce(array $settings): void
    {
        $result = $this->synchronizeFixture($settings);
        self::assertSame(isset($settings['base_existing']) ? 'updated' : 'created', $result->status);
        self::assertFalse($this->writes('refs/heads')[0][2]['force']);
        if (isset($settings['base_orphan'])) {
            self::assertSame([str_repeat('b', 40)], $this->writes('commits')[0][2]['parents']);
        }
    }

    #[Test]
    #[TestWith([[]])]
    #[TestWith([['existing' => true]])]
    #[TestWith([['orphan' => true, 'same_plan' => true]])]
    public function dryRunPerformsNoMutation(array $settings): void
    {
        $result = $this->synchronizeFixture($settings, new VersionPullRequestInput(dryRun: true));
        self::assertSame('dry-run', $result->status);
        self::assertSame([], $this->writes());
    }

    #[Test]
    #[TestWith([['none' => true]])]
    #[TestWith([['none' => true, 'existing' => true]])]
    public function noChangesCreateNoEmptyPrAndPreserveAnExistingOwnedPr(array $settings): void
    {
        $result = $this->synchronizeFixture($settings);
        self::assertSame('none', $result->status);
        self::assertSame([], $this->writes());
        if (isset($settings['existing'])) {
            self::assertNotEmpty($result->diagnostics);
        }
    }

    #[Test]
    public function identicalBaseTreeCreatesNoCommitOrEmptyPr(): void
    {
        $result = $this->synchronizeFixture(['empty_tree' => true]);
        self::assertSame('none', $result->status);
        self::assertCount(1, $this->writes());
        self::assertSame([], $this->writes('commits'));
    }

    #[Test]
    public function maintenanceHasNoNextVersionAndPendingMergedReleaseDoesNotDoubleBump(): void
    {
        $maintenance = $this->synchronizeFixture(['maintenance' => true]);
        self::assertTrue($maintenance->maintenance);
        self::assertNull($maintenance->version);
        self::assertStringContainsString('Maintain existing', $this->writes('pulls')[0][2]['body']);
        self::assertSame(['CHANGELOG.md'], array_column($this->writes('trees')[0][2]['tree'], 'path'));
        $this->calls = [];
        $pending = $this->synchronizeFixture(['pending' => true]);
        self::assertSame('refused', $pending->status);
        self::assertSame('1.0.1', $pending->version);
        self::assertSame([], $this->writes());
    }

    #[Test]
    public function pendingPublicationRequiresEveryConsumedFragmentAlreadyAbsentFromBase(): void
    {
        $pending = $this->synchronizeFixture(['pending' => true, 'pending_consumed' => true, 'pending_absent' => true]);
        self::assertSame('refused', $pending->status);
        self::assertSame([], $this->writes());
        $partial = $this->synchronizeFixture(['pending' => true, 'pending_consumed' => true]);
        self::assertSame('refused', $partial->status);
        self::assertStringContainsString('planned base is stale', implode(' ', $partial->diagnostics));
        self::assertSame([], $this->writes());
    }

    /** A prepared recovery plan must retain the earlier consumed-blob proof even when the fresh validator skips recovery. */
    #[Test]
    #[TestWith(['missing_fragment'])]
    #[TestWith(['changed_recovery_fragment'])]
    public function preparedRecoveryCannotDeleteAFragmentDifferentFromItsCommittedBase(string $case): void
    {
        $result = $this->synchronizeFixture(['prepared_resume' => true, $case => true]);
        self::assertSame('refused', $result->status);
        self::assertStringContainsString('recovery fragment differs', implode(' ', $result->diagnostics));
        self::assertSame([], $this->writes());
    }

    /** A matching prepared journal can keep its exact plan while using the same guarded mutation path. */
    #[Test]
    public function preparedRecoveryWithUnchangedBaseFragmentsRemainsApplicable(): void
    {
        self::assertSame('created', $this->synchronizeFixture(['prepared_resume' => true])->status);
        self::assertCount(1, $this->writes('trees'));
    }

    #[Test]
    #[TestWith(['input_failure'])]
    #[TestWith(['missing_repo'])]
    #[TestWith(['missing_base'])]
    #[TestWith(['bad_ref'])]
    #[TestWith(['stale_local'])]
    #[TestWith(['stale_plan'])]
    #[TestWith(['dirty_document'])]
    #[TestWith(['dirty_receipt'])]
    #[TestWith(['missing_fragment'])]
    #[TestWith(['scope_mismatch'])]
    #[TestWith(['duplicate_pr'])]
    #[TestWith(['unexpected_pr'])]
    #[TestWith(['pr_head_mismatch'])]
    #[TestWith(['unowned'])]
    #[TestWith(['wrong_bot_creator'])]
    #[TestWith(['missing_bot_account'])]
    #[TestWith(['wrong_bot_account'])]
    #[TestWith(['unowned'])]
    #[TestWith(['wrong_receipt_id'])]
    #[TestWith(['read_failure'])]
    #[TestWith(['nested_project'])]
    #[TestWith(['input_evidence_failure'])]
    public function invalidOrUntrustedInputsFailBeforeMutation(string $case): void
    {
        $settings = [$case => true];
        if (in_array($case, ['unowned', 'wrong_bot_creator', 'missing_bot_account', 'wrong_bot_account', 'unexpected_pr', 'pr_head_mismatch', 'duplicate_pr'], true)) {
            $settings['existing'] = true;
        }
        $result = $this->synchronizeFixture($settings);
        self::assertSame('refused', $result->status);
        self::assertNotEmpty($result->diagnostics);
        self::assertSame([], $this->writes());
        self::assertStringNotContainsString('super-secret', implode(' ', $result->diagnostics));
    }

    #[Test]
    #[TestWith(['base_race'])]
    #[TestWith(['head_race'])]
    #[TestWith(['local_race'])]
    public function preflightRaceDoesNotOverwriteBranch(string $case): void
    {
        $result = $this->synchronizeFixture([$case => true]);
        self::assertSame('refused', $result->status);
        self::assertSame([], $this->writes());
    }

    #[Test]
    #[TestWith(['missing_tree'])]
    #[TestWith(['tree_failure'])]
    #[TestWith(['bad_tree_response'])]
    #[TestWith(['bad_commit_response'])]
    #[TestWith(['unsigned_new_commit'])]
    #[TestWith(['late_base_race'])]
    #[TestWith(['ref_lost_unconfirmed'])]
    #[TestWith(['ref_success_unconfirmed'])]
    #[TestWith(['post_update_race'])]
    #[TestWith(['pr_bad_response'])]
    public function failureDuringTransactionNeverClaimsCompletedMutation(string $case): void
    {
        $result = $this->synchronizeFixture([$case => true]);
        self::assertSame('missing_tree' === $case ? 'refused' : 'conflict', $result->status);
        self::assertNotEmpty($result->diagnostics);
        self::assertStringNotContainsString('super-secret', implode(' ', $result->diagnostics));
        if (in_array($case, ['unsigned_new_commit', 'late_base_race'], true)) {
            self::assertSame([], $this->writes('refs'));
        }
    }

    /** Failed ownership proof exposes recoverable public identity and allowlisted scalars, never raw API details. */
    #[Test]
    #[TestWith([true, 'valid', 'true', 'valid'])]
    #[TestWith([false, 'unsigned', 'false', 'unsigned'])]
    #[TestWith([null, null, 'unavailable', 'unavailable'])]
    #[TestWith(['true', 'synthetic-super-secret', 'unavailable', 'unavailable'])]
    #[TestWith([1, ['synthetic-super-secret'], 'unavailable', 'unavailable'])]
    public function rejectedGeneratedCommitDiagnosticPreservesItsShaWithoutLeakingResponse(mixed $verified, mixed $reason, string $expectedVerified, string $expectedReason): void
    {
        $result = $this->synchronizeFixture(['unsigned_new_commit' => true, 'commit_verification' => ['verified' => $verified, 'reason' => $reason, 'signature' => 'synthetic-super-secret', 'payload' => 'synthetic-super-secret']]);
        self::assertSame('conflict', $result->status);
        self::assertStringContainsString('generated-sha=' . str_repeat('c', 40), $result->diagnostics[0]);
        self::assertStringContainsString('create-verification=' . $expectedVerified, $result->diagnostics[0]);
        self::assertStringContainsString('create-reason=' . $expectedReason, $result->diagnostics[0]);
        self::assertStringNotContainsString('synthetic-super-secret', $result->diagnostics[0]);
        self::assertSame([], $this->writes('refs'));
        self::assertSame([], $this->writes('pulls'));
    }

    #[Test]
    #[TestWith([['ref_lost_confirmed' => true]])]
    #[TestWith([['pr_lost_confirmed' => true]])]
    #[TestWith([['existing' => true, 'pr_lost_confirmed' => true]])]
    public function confirmsLostRefOrPrResponsesThroughReadOnlyState(array $settings): void
    {
        $result = $this->synchronizeFixture($settings);
        self::assertSame(isset($settings['existing']) ? 'updated' : 'created', $result->status);
        self::assertSame(7, $result->prNumber);
        self::assertCount(1, $this->writes('pulls'));
    }

    private function writes(string $suffix = ''): array
    {
        return array_values(array_filter($this->calls, static fn(array $call): bool => 'GET' !== $call[0] && str_contains($call[1], $suffix)));
    }

    private function synchronizeFixture(array $settings = [], ?VersionPullRequestInput $input = null): VersionPullRequestResult
    {
        $input ??= new VersionPullRequestInput();
        $options = new ReleaseOptions('/consumer', repository: isset($settings['missing_repo']) ? null : 'owner/project');
        $base = str_repeat('b', 40);
        $head = isset($settings['base_existing']) ? $base : (isset($settings['existing']) || isset($settings['orphan']) ? str_repeat('a', 40) : (isset($settings['base_orphan']) ? $base : null));
        $original = isset($settings['pending']) ? 'new' : 'old';
        $originalReceipt = isset($settings['pending']) || isset($settings['prepared_resume']) ? 'receipt' : null;
        $next = isset($settings['none']) || isset($settings['maintenance']) ? null : '1.0.1';
        $plan = new ReleasePlan($options, str_repeat('c', 64), isset($settings['stale_plan']) || isset($settings['pending']) ? str_repeat('f', 40) : $base, '1.0.0', $next, null === $next ? null : 'patch', (isset($settings['pending']) && ! isset($settings['pending_consumed'])) || isset($settings['none']) || isset($settings['maintenance']) ? [] : ['/consumer/.changelog/feature.md' => hash('sha256', 'fragment')], [], '/consumer/CHANGELOG.md', $original, isset($settings['none']) ? 'old' : 'new', 'notes', '/consumer/.changelog/release-plan.json', $originalReceipt, 'receipt', isset($settings['pending']) || isset($settings['prepared_resume']));
        $data = ['id' => isset($settings['wrong_receipt_id']) ? 'wrong' : $plan->id, 'base_sha' => $base, 'consumed' => isset($settings['scope_mismatch']) || [] === $plan->consumed ? [] : ['.changelog/feature.md' => hash('sha256', 'fragment')]];
        $oldData = ['id' => isset($settings['same_plan']) ? $plan->id : str_repeat('d', 64)];
        $pr = isset($settings['existing']) || isset($settings['base_existing']) ? $this->pr('github-actions[bot]', 'Bot', 'changelog/version') : null;
        if (null !== $pr) {
            $pr['html_url'] = 'https://github.com/owner/project/pull/7';
            $pr['head']['sha'] = $head;
            if (isset($settings['wrong_bot_creator'])) {
                $pr['user']['id'] = 999;
            }
            if (isset($settings['unexpected_pr'])) {
                $pr['base']['ref'] = 'other';
            }
            if (isset($settings['pr_head_mismatch'])) {
                $pr['head']['sha'] = str_repeat('f', 40);
            }
        }
        $baseReads = $headReads = $localReads = 0;
        $github = $this->createStub(GitHubClientInterface::class);
        $github->method('paginate')->willReturnCallback(static function (string $path) use (&$pr, $settings): array {
            return null === $pr ? [] : (isset($settings['duplicate_pr']) ? [$pr, $pr] : [$pr]);
        });
        $github->method('request')->willReturnCallback(function (string $method, string $path, ?array $body = null) use (&$head, &$pr, &$baseReads, &$headReads, $base, $settings, $input, $oldData): ?array {
            $this->calls[] = [$method, $path, $body];
            if (isset($settings['read_failure'])) {
                throw new RuntimeException('super-secret');
            }
            if ('GET' === $method && str_starts_with($path, '/users/')) {
                return isset($settings['missing_bot_account']) ? null : $this->account($input->automationActor, 'Bot', isset($settings['wrong_bot_account']) ? 999 : 101);
            }
            if (str_ends_with($path, '/git/ref/heads/main')) {
                ++$baseReads;
                if (isset($settings['missing_base'])) {
                    return null;
                }
                $sha = (isset($settings['base_race']) && $baseReads >= 2) || (isset($settings['late_base_race']) && $baseReads >= 3) || (isset($settings['post_update_race']) && $baseReads >= 4) ? str_repeat('f', 40) : $base;
                return ['object' => ['type' => isset($settings['bad_ref']) ? 'tag' : 'commit', 'sha' => $sha]];
            }
            if (str_ends_with($path, '/git/ref/heads/changelog%2Fversion')) {
                ++$headReads;
                $sha = isset($settings['head_race']) && $headReads >= 2 ? str_repeat('f', 40) : $head;
                return null === $sha ? null : ['object' => ['type' => 'commit', 'sha' => $sha]];
            }
            if ('GET' === $method && str_contains($path, '/commits/') && ! str_contains($path, '/git/')) {
                return ['commit' => ['message' => 'Changelog-Plan: ' . $oldData['id']]];
            }
            if (str_contains($path, '/contents/')) {
                return isset($settings['unowned']) ? null : $this->file('old receipt');
            }
            if ('GET' === $method && str_contains($path, '/git/commits/')) {
                return isset($settings['missing_tree']) ? null : ['tree' => ['sha' => str_repeat('d', 40)]];
            }
            if ('POST' === $method && str_ends_with($path, '/git/trees')) {
                if (isset($settings['tree_failure'])) {
                    throw new RuntimeException('super-secret');
                }
                return isset($settings['bad_tree_response']) ? null : ['sha' => str_repeat(isset($settings['empty_tree']) ? 'd' : 'e', 40)];
            }
            if ('POST' === $method && str_ends_with($path, '/git/commits')) {
                return isset($settings['bad_commit_response']) ? null : ['sha' => str_repeat('c', 40), 'verification' => $settings['commit_verification'] ?? null, 'author' => ['email' => 'synthetic-super-secret']];
            }
            if ('GET' !== $method && str_contains($path, '/git/refs')) {
                if (! isset($settings['ref_lost_unconfirmed']) && ! isset($settings['ref_success_unconfirmed'])) {
                    $head = $body['sha'];
                }
                if (isset($settings['ref_lost_confirmed']) || isset($settings['ref_lost_unconfirmed'])) {
                    throw new RuntimeException('super-secret');
                }
                return ['object' => ['type' => 'commit', 'sha' => $head]];
            }
            if (str_contains($path, '/pulls')) {
                if ('GET' === $method) {
                    return $pr;
                }
                $pr = $this->pr($input->automationActor, 'Bot', $input->managedBranch);
                $pr['head']['sha'] = $head;
                $pr['html_url'] = 'https://github.com/owner/project/pull/7';
                if (isset($settings['pr_lost_confirmed'])) {
                    throw new RuntimeException('super-secret');
                }
                return isset($settings['pr_bad_response']) ? null : $pr;
            }
            return null;
        });
        $git = $this->createStub(GitRepositoryInterface::class);
        $git->method('repositoryRoot')->willReturn(isset($settings['nested_project']) ? '/another/root' : $options->workingDirectory);
        $git->method('resolveRef')->willReturnCallback(static function () use (&$localReads, $base, $settings): string {
            ++$localReads;
            return isset($settings['stale_local']) || (isset($settings['local_race']) && $localReads >= 2) ? str_repeat('f', 40) : $base;
        });
        $git->method('readFileAt')->willReturnCallback(static fn(string $directory, string $sha, string $path): ?string => match ($path) {
            'CHANGELOG.md' => isset($settings['dirty_document']) || isset($settings['dirty_receipt']) ? 'dirty' : $original,
            '.changelog/release-plan.json' => isset($settings['dirty_receipt']) ? 'dirty' : $originalReceipt,
            default => isset($settings['missing_fragment']) || isset($settings['pending_absent']) ? null : (isset($settings['changed_recovery_fragment']) ? 'changed' : 'fragment'),
        });
        $planner = $this->createStub(ReleasePlannerInterface::class);
        $planner->method('plan')->willReturnCallback(function (ReleaseOptions $received, string $operation) use ($plan): ReleasePlan {
            $this->plannerCalls[] = $operation;
            return $plan;
        });
        $receipts = $this->createStub(ReceiptCodecInterface::class);
        $receipts->method('decode')->willReturnCallback(static fn(string $raw): ReleaseReceipt => new ReleaseReceipt('old receipt' === $raw ? $oldData : $data));
        $paths = $this->createStub(PackagePathResolverInterface::class);
        $paths->method('relativePath')->willReturn('.changelog/feature.md');
        $policy = $this->createMock(PullRequestPolicyInterface::class);
        $policy->expects(self::never())->method('inspect');
        $policy->method('inspectHead')->willReturnCallback(function (ReleaseOptions $received, string $sha) use ($settings): bool {
            $this->policyCalls[] = ['inspectHead', $sha];
            return ! isset($settings['unowned']) && ! (isset($settings['unsigned_new_commit']) && str_repeat('c', 40) === $sha);
        });
        $inputs = $this->createStub(VersionPullRequestInputFactoryInterface::class);
        if (isset($settings['input_failure'])) {
            $inputs->method('create')->willThrowException(new VersionPullRequestException('invalid input'));
        } else {
            $inputs->method('create')->willReturn($input);
        }
        $results = $this->createStub(VersionPullRequestResultFactoryInterface::class);
        $results->method('create')->willReturnCallback(static fn(string $status, ?int $number = null, ?string $url = null, ?string $sha = null, ?string $id = null, ?string $version = null, bool $maintenance = false, array $diagnostics = []): VersionPullRequestResult => new VersionPullRequestResult($status, $number, $url, $sha, $id, $version, $maintenance, $diagnostics));
        $exceptions = $this->createStub(VersionPullRequestExceptionFactoryInterface::class);
        $exceptions->method('create')->willReturnCallback(static fn(string $message): VersionPullRequestException => new VersionPullRequestException($message));
        $inputEvidence = $this->createStub(ReleaseInputEvidenceValidatorInterface::class);
        $inputEvidence->method('validate')->willReturnCallback(function (ReleasePlan $received) use ($plan, $settings): void {
            self::assertSame($plan, $received);
            self::assertSame([], $this->writes(), 'Source proof must run before any GitHub mutation.');
            if (isset($settings['missing_fragment']) || isset($settings['input_evidence_failure'])) {
                throw new RuntimeException('Uncommitted source evidence; synthetic-super-secret');
            }
        });
        return new VersionPullRequestService($github, $git, $planner, $receipts, $paths, $policy, $inputs, $results, $exceptions, $inputEvidence)->synchronize($options, $input);
    }
}
