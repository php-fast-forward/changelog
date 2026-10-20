<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation\VersionPullRequest;

use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestInputFactory;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestInputFactoryInterface;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestException;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestInput;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestResult;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestService;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryCodecInterface;
use FastForward\Changelog\Release\Factory\ReleasePlanFactoryInterface;
use FastForward\Changelog\Release\PendingPublicationException;
use FastForward\Changelog\Release\ReceiptCodecInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use FastForward\Changelog\Release\ReleaseReceipt;
use FastForward\Changelog\Template\TemplateResolverInterface;
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
#[UsesClass(VersionPullRequestInputFactory::class)]
#[UsesClass(VersionPullRequestResult::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(PendingPublicationException::class)]
#[UsesClass(ReleasePlan::class)]
#[UsesClass(ReleaseReceipt::class)]
final class VersionPullRequestServiceTest extends TestCase
{
    use PolicyFixtureTrait;

    private array $calls = [];
    private array $policyCalls = [];
    private array $plannerCalls = [];
    private array $pullRequestSnapshots = [];
    private array $referencePolicies = [];

    /** Pending publication explains the recovery boundary; unrelated planner failures remain redacted before writes. */
    #[TestWith([true])]
    #[TestWith([false])]
    public function testPlanningFailurePreservesOnlyTheControlledPendingPublicationDiagnosis(bool $pending): void
    {
        $message = 'Maintained release 1.7.1 is awaiting its reachable stable Git tag; complete its approved publication or reviewed recovery before planning another version. Latest reachable stable version: 1.7.0.';
        $result = $this->synchronizeFixture([
            'planning_failure' => $pending
                ? new PendingPublicationException($message)
                : new RuntimeException('synthetic-super-secret'),
        ]);

        self::assertSame('refused', $result->status);
        self::assertNull($result->version);
        self::assertSame([], $this->writes());
        self::assertSame(
            [
                $pending ? $message : 'Version PR synchronization could not be verified; inspect remote state before retrying. Response details were withheld.',
            ],
            $result->diagnostics,
        );
    }

    /** Changing discovery replaces only a provably owned transaction and proves its replacement under the selected policy. */
    #[TestWith([true])]
    #[TestWith([false])]
    public function testReferencePolicyChangeReplacesOnlyTheVerifiedOwnedPendingHead(bool $enabled): void
    {
        $result = $this->synchronizeFixture(
            ['existing' => true, 'resolve_references' => $enabled, 'legacy_reference_policy' => true],
        );

        self::assertSame('updated', $result->status);
        self::assertSame([$enabled, !$enabled, $enabled], $this->referencePolicies);
        self::assertStringContainsString(
            'Changelog-Options: ' . new ReleaseOptions(
                '/consumer',
                repository: 'owner/project',
                resolvePullRequests: $enabled,
            )->evidenceHash(),
            $this->writes('commits')[0][2]['message'],
        );
        self::assertSame(['sha' => str_repeat('c', 40), 'force' => false], $this->writes('refs/heads')[0][2]);
    }

    /** Switching either direction cannot adopt an unowned branch. */
    #[TestWith([true])]
    #[TestWith([false])]
    public function testReferencePolicyChangeCannotAdoptAnUnownedBranch(bool $enabled): void
    {
        $result = $this->synchronizeFixture(['existing' => true, 'resolve_references' => $enabled, 'unowned' => true]);

        self::assertSame('refused', $result->status);
        self::assertSame([$enabled, !$enabled], $this->referencePolicies);
        self::assertSame([], $this->writes('commits'));
        self::assertSame([], $this->writes('refs/heads'));
    }

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
        self::assertSame([
            'ref' => 'refs/heads/changelog/version', 'sha' => str_repeat('c', 40)],
            $this->writes('refs')[0][2],
        );
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
        self::assertStringContainsString('Prepare release 1.0.1.', $this->writes('pulls/7')[0][2]['body']);
        self::assertSame(['inspectHead', str_repeat('a', 40)], $this->policyCalls[0]);
    }

    /** A successful PATCH can expose the old head while an independent read confirms the exact new transaction. */
    #[Test]
    public function successfulStalePatchResponseIsConfirmedThroughTheSamePrWithoutAnotherWrite(): void
    {
        $result = $this->synchronizeFixture(['existing' => true, 'stale_patch_response' => true]);
        self::assertSame('updated', $result->status);
        self::assertSame(7, $result->prNumber);
        self::assertSame(str_repeat('c', 40), $result->headSha);
        self::assertCount(1, $this->writes('pulls'));
        self::assertSame('PATCH', $this->writes('pulls')[0][0]);
        self::assertSame(
            [['GET', '/repos/owner/project/pulls/7', null]],
            array_values(array_filter(
                $this->calls,
                static fn(array $call): bool => 'GET' === $call[0] && str_contains($call[1], '/pulls/'),
            )),
        );
        self::assertSame(['GET', '/repos/owner/project/pulls/7', null], array_last($this->calls));
    }

    /** A lost PATCH response cannot confirm a generated body that GitHub never applied to the current branch head. */
    #[Test]
    public function rejectsUnappliedBodyAfterAnUncertainPrUpdate(): void
    {
        $previousBody = 'Previous release checklist retained before the failed write.';
        $result = $this->synchronizeFixture([
            'existing' => true,
            'pr_update_failed_before_apply' => true,
            'pr_body' => $previousBody,
        ]);

        self::assertSame('conflict', $result->status);
        self::assertSame(str_repeat('c', 40), $result->headSha);
        self::assertNotEmpty($result->diagnostics);
        self::assertStringNotContainsString('super-secret', implode(' ', $result->diagnostics));
        self::assertCount(1, $this->writes('pulls'));
        self::assertSame('PATCH', $this->writes('pulls')[0][0]);
        self::assertSame($previousBody, array_last($this->pullRequestSnapshots)['body']);
        self::assertSame(['GET', '/repos/owner/project/pulls/7', null], array_last($this->calls));
    }

    /** Fresh confirmation never authorizes changed PR identity, presentation, ownership, branches or closed state. */
    #[Test]
    #[TestWith(['stale_head'])]
    #[TestWith(['wrong_head'])]
    #[TestWith(['foreign_creator'])]
    #[TestWith(['foreign_creator_id'])]
    #[TestWith(['wrong_number'])]
    #[TestWith(['wrong_repository'])]
    #[TestWith(['wrong_branch'])]
    #[TestWith(['wrong_base_branch'])]
    #[TestWith(['closed'])]
    #[TestWith(['wrong_title'])]
    #[TestWith(['wrong_body'])]
    #[TestWith(['missing_body'])]
    #[TestWith(['missing'])]
    #[TestWith(['read_failure'])]
    public function invalidFreshPrConfirmationFailsWithoutRepeatingTheWrite(string $confirmation): void
    {
        $result = $this->synchronizeFixture(['existing' => true, 'pr_confirmation' => $confirmation]);
        self::assertSame('conflict', $result->status);
        self::assertSame(str_repeat('c', 40), $result->headSha);
        self::assertNotEmpty($result->diagnostics);
        self::assertStringNotContainsString('super-secret', implode(' ', $result->diagnostics));
        self::assertCount(1, $this->writes('pulls'));
        self::assertSame('PATCH', $this->writes('pulls')[0][0]);
        self::assertSame(
            [['GET', '/repos/owner/project/pulls/7', null]],
            array_values(array_filter(
                $this->calls,
                static fn(array $call): bool => 'GET' === $call[0] && str_contains($call[1], '/pulls/'),
            )),
        );
    }

    #[Test]
    public function samePlanDoesNotGenerateEndlessCommitsOrAnotherPr(): void
    {
        $result = $this->synchronizeFixture(['existing' => true, 'same_plan' => true]);
        self::assertSame('unchanged', $result->status);
        self::assertSame([], $this->writes());
    }

    /** Presentation expands repository and version placeholders once; other text retains its bytes. */
    #[Test]
    #[TestWith([
        'chore(release): {repository_name} {current_version} → {next_version}',
        'chore(release): project 1.0.0 → 1.0.1',
    ])]
    #[TestWith(['{repository_name} / {repository_name}', 'project / project'])]
    #[TestWith(['{repository} / {repository_name}', 'owner/project / project'])]
    #[TestWith([
        'chore(release): fast-forward/project v{current_version} → v{next_version}',
        'chore(release): fast-forward/project v1.0.0 → v1.0.1',
    ])]
    #[TestWith(['${{ secrets.TOKEN }} $(id) {repository_name}', '${{ secrets.TOKEN }} $(id) project'])]
    #[TestWith(['chore(release): {current_version} → {next_version}', 'chore(release): 1.0.0 → 1.0.1'])]
    #[TestWith(['Release {next_version} from {current_version}', 'Release 1.0.1 from 1.0.0'])]
    #[TestWith(['Update history {custom}', 'Update history {custom}'])]
    #[TestWith(['chore: update changelog', 'chore: update changelog'])]
    public function expandsVersionTitleBeforeCreatingTheCommitAndPr(string $template, string $expected): void
    {
        $result = $this->synchronizeFixture(input: new VersionPullRequestInput(title: $template));

        self::assertSame('created', $result->status);
        self::assertSame($expected, $this->writes('pulls')[0][2]['title']);
        self::assertStringStartsWith($expected . "\n\nChangelog-Plan: ", $this->writes('commits')[0][2]['message']);
    }

    /** Repository substitutions obey the final byte limit before remote writes in apply and preview modes. */
    #[Test]
    #[TestWith(['repository_name', 'project', 249, false, 'created'])]
    #[TestWith(['repository_name', 'project', 249, true, 'dry-run'])]
    #[TestWith(['repository_name', 'project', 250, false, 'refused'])]
    #[TestWith(['repository_name', 'project', 250, true, 'refused'])]
    #[TestWith(['repository', 'owner/project', 243, false, 'created'])]
    #[TestWith(['repository', 'owner/project', 244, false, 'refused'])]
    public function boundsTheResolvedRepositoryTitle(
        string $placeholder,
        string $repository,
        int $literalBytes,
        bool $dryRun,
        string $status,
    ): void {
        $result = $this->synchronizeFixture(
            input: new VersionPullRequestInput(title: str_repeat(
                'a',
                $literalBytes,
            ) . '{' . $placeholder . '}', dryRun: $dryRun),
        );

        self::assertSame($status, $result->status);
        if ('created' === $status) {
            self::assertSame(str_repeat('a', $literalBytes) . $repository, $this->writes('pulls')[0][2]['title']);
        } else {
            self::assertSame([], $this->writes());
        }
    }

    /** First releases expose the real initial baseline; maintenance has no new version and repeats its current version. */
    #[Test]
    #[TestWith([['current_version' => '0.0.0', 'next_version' => '1.0.0'], '0.0.0 → 1.0.0'])]
    #[TestWith([['maintenance' => true], '1.0.0 → 1.0.0'])]
    public function titlesUseThePlanVersionsWithoutInventingARelease(array $settings, string $expected): void
    {
        $result = $this->synchronizeFixture(
            $settings,
            new VersionPullRequestInput(title: '{current_version} → {next_version}'),
        );

        self::assertSame('created', $result->status);
        self::assertSame($expected, $this->writes('pulls')[0][2]['title']);
    }

    /** A title-only rerun changes the owned PR presentation while keeping its signed transaction and branch head. */
    #[Test]
    public function samePlanUpdatesOnlyTheTitleWithoutAnotherCommit(): void
    {
        $result = $this->synchronizeFixture(
            ['existing' => true, 'same_plan' => true, 'pr_body' => 'Manual release checklist: verify deployment.'],
            new VersionPullRequestInput(title: 'chore(release): {current_version} → {next_version}'),
        );

        self::assertSame('updated', $result->status);
        self::assertSame(str_repeat('a', 40), $result->headSha);
        self::assertSame(str_repeat('c', 64), $result->planId);
        self::assertCount(1, $this->writes());
        self::assertSame('PATCH', $this->writes()[0][0]);
        self::assertSame('/repos/owner/project/pulls/7', $this->writes()[0][1]);
        self::assertSame('chore(release): 1.0.0 → 1.0.1', $this->writes()[0][2]['title']);
        self::assertArrayNotHasKey('body', $this->writes()[0][2]);
        self::assertSame(
            'Manual release checklist: verify deployment.',
            array_last($this->pullRequestSnapshots)['body'],
        );
    }

    /** A bounded template may exceed 256 raw bytes when its resolved title still fits, including previews and retitles. */
    #[Test]
    #[TestWith([[], false, 'created', 245])]
    #[TestWith([[], true, 'dry-run', 245])]
    #[TestWith([['existing' => true, 'same_plan' => true], false, 'updated', 245])]
    #[TestWith([[], false, 'created', 251])]
    public function acceptsTemplatesThatShrinkWithinTheFinalTitleLimit(
        array $settings,
        bool $dryRun,
        string $expected,
        int $literalBytes,
    ): void {
        $result = $this->synchronizeFixture(
            $settings,
            new VersionPullRequestInput(title: str_repeat('a', $literalBytes) . '{current_version}', dryRun: $dryRun),
        );

        self::assertSame($expected, $result->status, implode('; ', $result->diagnostics));
        if ($dryRun) {
            self::assertSame([], $this->writes());
        } else {
            self::assertSame(str_repeat('a', $literalBytes) . '1.0.0', $this->writes('pulls')[0][2]['title']);
        }
        if (isset($settings['existing'])) {
            self::assertSame(str_repeat('a', 40), $result->headSha);
            self::assertCount(1, $this->writes());
        }
    }

    /** Matching resolved titles are fully idempotent; previewing a different title performs no remote mutation. */
    #[Test]
    #[TestWith([false, 'chore(release): 1.0.0 → 1.0.1', 'unchanged'])]
    #[TestWith([true, 'Previous title', 'dry-run'])]
    public function samePlanTitleRerunsAreIdempotentOrReadOnly(bool $dryRun, string $title, string $expected): void
    {
        $result = $this->synchronizeFixture(
            ['existing' => true, 'same_plan' => true, 'pr_title' => $title],
            new VersionPullRequestInput(title: 'chore(release): {current_version} → {next_version}', dryRun: $dryRun),
        );

        self::assertSame($expected, $result->status);
        self::assertSame(str_repeat('a', 40), $result->headSha);
        self::assertSame([], $this->writes());
    }

    /** Expanded titles remain subject to the existing byte limit before any remote write. */
    #[Test]
    public function refusesTitleThatExceedsTheLimitAfterVersionExpansion(): void
    {
        $result = $this->synchronizeFixture(
            ['next_version' => '1.0.1+' . str_repeat('a', 30)],
            new VersionPullRequestInput(title: str_repeat('a', 240) . '{next_version}'),
        );

        self::assertSame('refused', $result->status);
        self::assertSame([], $this->writes());
        self::assertStringContainsString('256 bytes', implode('; ', $result->diagnostics));
    }

    /** Title-only refreshes retain the same fresh-base, immutable-head and Bot ownership requirements. */
    #[Test]
    #[TestWith(['base_race'])]
    #[TestWith(['head_race'])]
    #[TestWith(['local_race'])]
    #[TestWith(['unowned'])]
    public function titleOnlyUpdatesRefuseUntrustedOrMovedSnapshots(string $failure): void
    {
        $result = $this->synchronizeFixture(
            ['existing' => true, 'same_plan' => true, $failure => true],
            new VersionPullRequestInput(title: 'chore(release): {current_version} → {next_version}'),
        );

        self::assertSame('refused', $result->status);
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
        self::assertStringContainsString('Prepare release 1.0.1.', $this->writes()[0][2]['body']);
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
        if (in_array(
            $case,
            [
                'unowned',
                'wrong_bot_creator',
                'missing_bot_account',
                'wrong_bot_account',
                'unexpected_pr',
                'pr_head_mismatch',
                'duplicate_pr',
            ],
            true,
        )) {
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
    public function rejectedGeneratedCommitDiagnosticPreservesItsShaWithoutLeakingResponse(
        mixed $verified,
        mixed $reason,
        string $expectedVerified,
        string $expectedReason,
    ): void {
        $result = $this->synchronizeFixture(
            ['unsigned_new_commit' => true, 'commit_verification' => ['verified' => $verified, 'reason' => $reason, 'signature' => 'synthetic-super-secret', 'payload' => 'synthetic-super-secret']],
        );
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
        return array_values(
            array_filter($this->calls, static fn(array $call): bool => 'GET' !== $call[0] && str_contains(
                $call[1],
                $suffix,
            )),
        );
    }

    private function synchronizeFixture(
        array $settings = [],
        ?VersionPullRequestInput $input = null,
    ): VersionPullRequestResult {
        $input ??= new VersionPullRequestInput();
        $options = new ReleaseOptions(
            '/consumer',
            repository: isset($settings['missing_repo']) ? null : 'owner/project',
            resolvePullRequests: $settings['resolve_references'] ?? false,
        );
        $base = str_repeat('b', 40);
        $head = isset($settings['base_existing']) ? $base : (isset($settings['existing']) || isset($settings['orphan']) ? str_repeat(
            'a',
            40,
        ) : (isset($settings['base_orphan']) ? $base : null));
        $original = isset($settings['pending']) ? 'new' : 'old';
        $originalReceipt = isset($settings['pending']) || isset($settings['prepared_resume']) ? 'receipt' : null;
        $next = isset($settings['none']) || isset($settings['maintenance']) ? null : ($settings['next_version'] ?? '1.0.1');
        $plan = new ReleasePlan($options, str_repeat(
            'c',
            64,
        ), isset($settings['stale_plan']) || isset($settings['pending']) ? str_repeat(
            'f',
            40,
        ) : $base, $settings['current_version'] ?? '1.0.0', $next, null === $next ? null : 'patch', (isset($settings['pending']) && ! isset($settings['pending_consumed'])) || isset($settings['none']) || isset($settings['maintenance']) ? [] : ['/consumer/.changelog/feature.md' => hash(
            'sha256',
            'fragment',
        )], [], '/consumer/CHANGELOG.md', $original, isset($settings['none']) ? 'old' : 'new', 'notes', '/consumer/.changelog/release-plan.json', $originalReceipt, 'receipt', isset($settings['pending']) || isset($settings['prepared_resume']));
        $data = ['id' => isset($settings['wrong_receipt_id']) ? 'wrong' : $plan->id, 'base_sha' => $base, 'consumed' => isset($settings['scope_mismatch']) || [] === $plan->consumed ? [] : ['.changelog/feature.md' => hash(
            'sha256',
            'fragment',
        )]];
        $oldData = ['id' => isset($settings['same_plan']) ? $plan->id : str_repeat('d', 64)];
        $pr = isset($settings['existing']) || isset($settings['base_existing']) ? $this->pr(
            'github-actions[bot]',
            'Bot',
            'changelog/version',
        ) : null;
        if (null !== $pr) {
            $pr['title'] = $settings['pr_title'] ?? 'chore(release): owner/project v1.0.0 → v1.0.1';
            $pr['body'] = $settings['pr_body'] ?? 'Manually edited release checklist.';
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
        $github->method('request')->willReturnCallback(function (string $method, string $path, ?array $body = null) use (
            &$head,
            &$pr,
            &$baseReads,
            &$headReads,
            $base,
            $settings,
            $input,
            $oldData
        ): ?array {
            $this->calls[] = [$method, $path, $body];
            if (isset($settings['read_failure'])) {
                throw new RuntimeException('super-secret');
            }
            if ('GET' === $method && str_starts_with($path, '/users/')) {
                return isset($settings['missing_bot_account']) ? null : $this->account(
                    $input->automationActor,
                    'Bot',
                    isset($settings['wrong_bot_account']) ? 999 : 101,
                );
            }
            if (str_ends_with($path, '/git/ref/heads/main')) {
                ++$baseReads;
                if (isset($settings['missing_base'])) {
                    return null;
                }
                $sha = (isset($settings['base_race']) && $baseReads >= 2) || (isset($settings['late_base_race']) && $baseReads >= 3) || (isset($settings['post_update_race']) && $baseReads >= 4) ? str_repeat(
                    'f',
                    40,
                ) : $base;

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

                return isset($settings['bad_tree_response']) ? null : ['sha' => str_repeat(
                    isset($settings['empty_tree']) ? 'd' : 'e',
                    40,
                )];
            }
            if ('POST' === $method && str_ends_with($path, '/git/commits')) {
                return isset($settings['bad_commit_response']) ? null : ['sha' => str_repeat(
                    'c',
                    40,
                ), 'verification' => $settings['commit_verification'] ?? null, 'author' => ['email' => 'synthetic-super-secret']];
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
                    $confirmation = $pr;
                    switch ($settings['pr_confirmation'] ?? null) {
                        case 'stale_head':
                            $confirmation['head']['sha'] = str_repeat('a', 40);

                            break;
                        case 'wrong_head':
                            $confirmation['head']['sha'] = str_repeat('f', 40);

                            break;
                        case 'foreign_creator':
                            $confirmation['user'] = $this->account('contributor', 'User', 202);

                            break;
                        case 'foreign_creator_id':
                            $confirmation['user']['id'] = 999;

                            break;
                        case 'wrong_number':
                            $confirmation['number'] = 8;

                            break;
                        case 'wrong_repository':
                            $confirmation['head']['repo'] = ['id' => 2, 'full_name' => 'fork/project'];

                            break;
                        case 'wrong_branch':
                            $confirmation['head']['ref'] = 'other';

                            break;
                        case 'wrong_base_branch':
                            $confirmation['base']['ref'] = 'other';

                            break;
                        case 'closed':
                            $confirmation['state'] = 'closed';

                            break;
                        case 'wrong_title':
                            $confirmation['title'] = 'Unconfirmed title';

                            break;
                        case 'wrong_body':
                            $confirmation['body'] = 'Unconfirmed generated body';

                            break;
                        case 'missing_body':
                            unset($confirmation['body']);

                            break;
                        case 'missing':
                            return null;
                        case 'read_failure':
                            throw new RuntimeException('synthetic-super-secret');
                    }

                    $this->pullRequestSnapshots[] = $confirmation;

                    return $confirmation;
                }
                if ('PATCH' === $method && isset($settings['pr_update_failed_before_apply'])) {
                    $pr['head']['sha'] = $head;

                    throw new RuntimeException('synthetic-super-secret');
                }
                $previousBody = $pr['body'] ?? null;
                $pr = $this->pr($input->automationActor, 'Bot', $input->managedBranch);
                $pr['title'] = $body['title'];
                $pr['body'] = $body['body'] ?? $previousBody;
                $pr['head']['sha'] = $head;
                $pr['html_url'] = 'https://github.com/owner/project/pull/7';
                if (isset($settings['pr_lost_confirmed'])) {
                    throw new RuntimeException('super-secret');
                }
                $response = $pr;
                if (isset($settings['stale_patch_response']) && 'PATCH' === $method) {
                    $response['head']['sha'] = str_repeat('a', 40);
                }

                return isset($settings['pr_bad_response']) ? null : $response;
            }

            return null;
        });
        $git = $this->createStub(GitRepositoryInterface::class);
        $git->method('repositoryRoot')->willReturn(
            isset($settings['nested_project']) ? '/another/root' : $options->workingDirectory,
        );
        $git->method('resolveRef')->willReturnCallback(static function () use (&$localReads, $base, $settings): string {
            ++$localReads;

            return isset($settings['stale_local']) || (isset($settings['local_race']) && $localReads >= 2) ? str_repeat(
                'f',
                40,
            ) : $base;
        });
        $git->method('readFileAt')->willReturnCallback(
            static fn(string $directory, string $sha, string $path): ?string => match ($path) {
                'CHANGELOG.md' => isset($settings['dirty_document']) || isset($settings['dirty_receipt']) ? 'dirty' : $original,
                '.changelog/release-plan.json' => isset($settings['dirty_receipt']) ? 'dirty' : $originalReceipt,
                default => isset($settings['missing_fragment']) || isset($settings['pending_absent']) ? null : (isset($settings['changed_recovery_fragment']) ? 'changed' : 'fragment'),
            },
        );
        $planner = $this->createStub(ReleasePlannerInterface::class);
        $planner->method('plan')->willReturnCallback(function (ReleaseOptions $received, string $operation) use (
            $plan,
            $settings
        ): ReleasePlan {
            $this->plannerCalls[] = $operation;

            if (isset($settings['planning_failure'])) {
                throw $settings['planning_failure'];
            }

            return $plan;
        });
        $receipts = $this->createStub(ReceiptCodecInterface::class);
        $receipts->method('decode')->willReturnCallback(
            static fn(string $raw): ReleaseReceipt => new ReleaseReceipt('old receipt' === $raw ? $oldData : $data),
        );
        $paths = $this->createStub(PackagePathResolverInterface::class);
        $paths->method('relativePath')->willReturn('.changelog/feature.md');
        $policy = $this->createMock(PullRequestPolicyInterface::class);
        $policy->expects(self::never())->method('inspect');
        $policy->method('inspectHead')->willReturnCallback(function (ReleaseOptions $received, string $sha) use (
            $settings
        ): bool {
            $this->policyCalls[] = ['inspectHead', $sha];
            $this->referencePolicies[] = $received->resolvePullRequests;

            if (isset($settings['legacy_reference_policy']) && str_repeat('a', 40) === $sha
                && $received->resolvePullRequests === $settings['resolve_references']) {
                return false;
            }

            return ! isset($settings['unowned']) && ! (isset($settings['unsigned_new_commit']) && str_repeat(
                'c',
                40,
            ) === $sha);
        });
        $inputs = $this->createStub(VersionPullRequestInputFactoryInterface::class);
        if (isset($settings['input_failure'])) {
            $inputs->method('create')->willThrowException(new VersionPullRequestException('invalid input'));
        } else {
            $inputs = new VersionPullRequestInputFactory();
        }
        $inputEvidence = $this->createStub(ReleaseInputEvidenceValidatorInterface::class);
        $inputEvidence->method('validate')->willReturnCallback(function (ReleasePlan $received) use (
            $plan,
            $settings
        ): void {
            self::assertSame($plan, $received);
            self::assertSame([], $this->writes(), 'Source proof must run before any GitHub mutation.');
            if (isset($settings['missing_fragment']) || isset($settings['input_evidence_failure'])) {
                throw new RuntimeException('Uncommitted source evidence; synthetic-super-secret');
            }
        });

        return new VersionPullRequestService(
            $github,
            $git,
            $planner,
            $receipts,
            $paths,
            $policy,
            $inputs,
            $inputEvidence,
            $this->createStub(HistoryCodecInterface::class),
            $this->createStub(TemplateResolverInterface::class),
            $this->createStub(HistoryReleaseFactoryInterface::class),
            $this->createStub(ReleasePlanFactoryInterface::class),
        )->synchronize(
            $options,
            $input,
        );
    }
}
