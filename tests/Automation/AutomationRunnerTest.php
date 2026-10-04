<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation;

use FastForward\Changelog\Automation\AutomationRunner;
use FastForward\Changelog\Automation\Dependabot\DependabotFragmentResult;
use FastForward\Changelog\Automation\Dependabot\DependabotFragmentServiceInterface;
use FastForward\Changelog\Automation\Dependabot\DependabotInput;
use FastForward\Changelog\Automation\Dependabot\Factory\DependabotInputFactoryInterface;
use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Automation\Policy\PullRequestAuthorization;
use FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestInputFactoryInterface;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestInput;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestResult;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestServiceInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Publication\PublicationResult;
use FastForward\Changelog\Publication\PublicationServiceInterface;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Release\ReleaseApplierInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use FastForward\Changelog\Validation\CheckService;
use FastForward\Changelog\Validation\CheckServiceInterface;
use FastForward\Changelog\Validation\Factory\ValidationReportFactoryInterface;
use FastForward\Changelog\Validation\ValidationReport;
use FastForward\Changelog\Validator\ChangesetValidatorInterface;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

#[CoversClass(AutomationRunner::class)]
#[UsesClass(GitHubEvidence::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(ReleasePlan::class)]
#[UsesClass(ValidationReport::class)]
#[UsesClass(CheckService::class)]
#[UsesClass(PullRequestAuthorization::class)]
#[UsesClass(DependabotInput::class)]
#[UsesClass(DependabotFragmentResult::class)]
#[UsesClass(VersionPullRequestInput::class)]
#[UsesClass(VersionPullRequestResult::class)]
#[UsesClass(PublicationResult::class)]
final class AutomationRunnerTest extends TestCase
{
    #[Test]
    #[TestWith(['unknown', []])]
    #[TestWith(['version', ['waiver-label' => 'forged']])]
    #[TestWith(['check', ['central-change-authorized' => 'true']])]
    #[TestWith(['check', ['authorization-kind' => 'managed-version']])]
    public function unknownOperationsAndInputsStopBeforeOptionsOrIo(string $operation, array $inputs): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown');
        $this->runner([], false)->run($operation, $inputs);
    }

    #[Test]
    public function commonInputsMapToSharedSettingsWithoutReadingEnvironment(): void
    {
        $inputs = ['working-directory' => '/consumer', 'fragment-directory' => '.changes', 'changelog-file' => 'HISTORY.md', 'locale' => 'pt_BR', 'template' => 'compact', 'base-ref' => 'main', 'tag-prefix' => 'release/', 'repository' => 'owner/project', 'source' => 'tags'];
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->with(['workingDirectory' => '/consumer', 'fragmentDirectory' => '.changes', 'changelogFile' => 'HISTORY.md', 'locale' => 'pt_BR', 'template' => 'compact', 'baseRef' => 'main', 'tagPrefix' => 'release/', 'repository' => 'owner/project', 'source' => 'tags'])->willReturn($this->options());
        $checks = $this->createMock(CheckServiceInterface::class);
        $checks->expects(self::once())->method('check')->with($this->options(), 'BASE', false, false, 'ordinary')->willReturn(new ValidationReport([], [], false));
        self::assertSame(['status' => 'valid', 'fragments' => 0, 'waived' => false, 'kind' => 'ordinary', 'diagnostics' => []], $this->runner(['options' => $factory, 'checks' => $checks])->run('check', [...$inputs, 'since' => 'BASE']));
    }

    #[Test]
    #[TestWith([42])]
    #[TestWith([true])]
    #[TestWith([[]])]
    #[TestWith([null])]
    #[TestWith(["en\0forged"])]
    public function transportTextRejectsAmbiguousTypesBeforeOptions(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a string without NUL');
        $this->runner([], false)->run('check', ['locale' => $value, 'since' => 'BASE']);
    }

    #[Test]
    #[TestWith(['dependabot', []])]
    #[TestWith(['version', []])]
    #[TestWith(['check', ['since' => 'BASE', 'pull-request' => '7']])]
    #[TestWith(['publish', ['pull-request' => '7']])]
    public function githubOperationsRejectNestedWorkingDirectoryBeforeRemoteServices(string $operation, array $inputs): void
    {
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::once())->method('repositoryRoot')->with('/consumer')->willReturn('/parent');
        $git->expects(self::never())->method('resolveRef');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('repository root as working-directory');
        $this->runner(compact('git'))->run($operation, $inputs);
    }

    #[Test]
    #[TestWith([''])]
    public function checkRequiresAnExplicitContributionBaseline(string $since): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires since');
        $this->runner()->run('check', ['since' => $since]);
    }

    #[Test]
    #[TestWith(['0'])]
    #[TestWith(['01'])]
    #[TestWith(['+7'])]
    #[TestWith([' 7'])]
    #[TestWith(['92233720368547758070'])]
    public function pullRequestNumbersRequireCanonicalSafePositiveIntegers(string $number): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a positive integer');
        $this->runner()->run('check', ['since' => 'BASE', 'pull-request' => $number]);
    }

    #[Test]
    public function policyPermissionsAreBoundToExactCheckoutAndForwardAllDiagnostics(): void
    {
        $policy = $this->createMock(PullRequestPolicyInterface::class);
        $policy->expects(self::once())->method('inspect')->with($this->options(), 7, 'managed', 'app[bot]', 'waiver', 'maintenance')->willReturn(new PullRequestAuthorization(true, true, 'maintenance', ['trusted grant'], $this->sha()));
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::once())->method('repositoryRoot')->with('/consumer')->willReturn('/consumer');
        $git->expects(self::once())->method('resolveRef')->with('/consumer')->willReturn($this->sha());
        $checks = $this->createMock(CheckServiceInterface::class);
        $checks->expects(self::once())->method('check')->with($this->options(), 'BASE', true, true, 'maintenance')->willReturn(new ValidationReport(['fixture'], [], true));
        self::assertSame(['status' => 'valid', 'fragments' => 1, 'waived' => true, 'kind' => 'maintenance', 'diagnostics' => ['trusted grant']], $this->runner(compact('policy', 'git', 'checks'))->run('check', ['since' => 'BASE', 'pull-request' => '7', 'managed-branch' => 'managed', 'automation-actor' => 'app[bot]', 'waiver-label' => 'waiver', 'maintenance-label' => 'maintenance']));
    }

    #[Test]
    #[TestWith(['ordinary', false, false, 'M', '.changelog/release-plan.json', '@receipt'])]
    #[TestWith(['waiver', false, true, 'A', '.changelog/release-plan.json', '@receipt'])]
    #[TestWith(['maintenance', true, true, 'D', '.changelog/release-plan.json', '@receipt'])]
    #[TestWith(['maintenance', true, false, 'D', '.changelog/pending.md', '.changelog/pending.md'])]
    #[TestWith(['managed-version', false, true, 'M', '.changelog/release-plan.json', '@receipt'])]
    #[TestWith(['managed-version', true, false, 'M', '.changelog/release-plan.json', null])]
    #[TestWith(['managed-version', true, false, 'D', '.changelog/pending.md', null])]
    #[TestWith(['maintenance', true, false, 'M', 'CHANGELOG.md', null])]
    public function realCheckerEnforcesTheExactVerifiedPolicyKind(
        string $kind,
        bool $central,
        bool $waiver,
        string $status,
        string $path,
        ?string $error,
    ): void {
        $policy = $this->createMock(PullRequestPolicyInterface::class);
        $policy->expects(self::once())->method('inspect')->with($this->options(), 7, 'changelog/version', 'github-actions[bot]', 'changelog-not-required', 'changelog-maintenance')->willReturn(new PullRequestAuthorization($waiver, $central, $kind, [], $this->sha()));
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::once())->method('repositoryRoot')->with('/consumer')->willReturn('/consumer');
        $git->expects(self::once())->method('resolveRef')->with('/consumer')->willReturn($this->sha());
        $git->expects(self::once())->method('changesSince')->with('/consumer', 'BASE')->willReturn([['status' => $status, 'path' => $path, 'previous' => null]]);
        $validator = $this->createMock(ChangesetValidatorInterface::class);
        $validator->expects(self::once())->method('validate')->with('/consumer/.changelog', false, false)->willReturn(new ValidationReport([], [], false));
        $validator->expects(self::once())->method('validatePaths')->with('/consumer/.changelog', [], false, false)->willReturn(new ValidationReport([], [], false));
        $reports = $this->createMock(ValidationReportFactoryInterface::class);
        $reports->expects(self::once())->method('create')->willReturnCallback(static fn(array $changesets, array $errors, bool $waived, array $hashes): ValidationReport => new ValidationReport($changesets, $errors, $waived, $hashes));
        $checks = new CheckService($validator, $git, $reports);

        if (null !== $error) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('"' . $error . '"');
        }

        $result = $this->runner(compact('policy', 'git', 'checks'))->run('check', ['since' => 'BASE', 'pull-request' => '7']);

        self::assertSame(['status' => 'valid', 'fragments' => 0, 'waived' => $waiver, 'kind' => $kind, 'diagnostics' => []], $result);
    }

    #[Test]
    #[TestWith([null])]
    #[TestWith(['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'])]
    public function absentOrDifferentPolicyHeadCannotAuthorizeValidation(?string $head): void
    {
        $policy = $this->createMock(PullRequestPolicyInterface::class);
        $policy->expects(self::once())->method('inspect')->with($this->options(), 7, 'changelog/version', 'github-actions[bot]', 'changelog-not-required', 'changelog-maintenance')->willReturn(new PullRequestAuthorization(true, true, 'waiver', [], $head));
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::once())->method('repositoryRoot')->with('/consumer')->willReturn('/consumer');
        $git->expects(null === $head ? self::never() : self::once())->method('resolveRef')->willReturn($this->sha());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different checkout');
        $this->runner(compact('policy', 'git'))->run('check', ['since' => 'BASE', 'pull-request' => '7']);
    }

    #[Test]
    public function invalidReportReturnsAllKeyedDiagnosticsAsWorkflowFailure(): void
    {
        $checks = $this->createMock(CheckServiceInterface::class);
        $checks->expects(self::once())->method('check')->with($this->options(), 'BASE', false, false, 'ordinary')->willReturn(new ValidationReport([], ['one.md' => ['unknown type'], 'two.md' => ['empty message']], false));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Changelog check failed: {"one.md":["unknown type"],"two.md":["empty message"]}');
        $this->runner(compact('checks'))->run('check', ['since' => 'BASE']);
    }

    #[Test]
    #[TestWith(['created', 'false', 'true', false, true])]
    #[TestWith(['filtered', true, false, true, false])]
    #[TestWith(['unchanged', null, null, true, true])]
    public function dependabotParsesOnlyExplicitTrustedMetadata(string $status, mixed $dev, mixed $actions, bool $expectedDev, bool $expectedActions): void
    {
        $input = new DependabotInput(7, $this->sha(), ['vendor/one', 'vendor/two'], 'direct:production', 'composer', [2, 3], $expectedDev, $expectedActions);
        $dependabotInputs = $this->createMock(DependabotInputFactoryInterface::class);
        $dependabotInputs->expects(self::once())->method('create')->with(7, $this->sha(), ['vendor/one', 'vendor/two'], 'direct:production', 'composer', [2, 3], $expectedDev, $expectedActions)->willReturn($input);
        $dependabot = $this->createMock(DependabotFragmentServiceInterface::class);
        $dependabot->expects(self::once())->method('synchronize')->with($this->options(), $input)->willReturn(new DependabotFragmentResult($status, '.changelog/dependabot-7.md', $this->sha(), ['safe detail']));
        $inputs = ['pull-request' => '7', 'expected-head-sha' => $this->sha(), 'dependency-names' => ' vendor/one, vendor/two ', 'dependency-type' => 'direct:production', 'ecosystem' => 'composer', 'security-alert-numbers' => '[2,3]'];
        if (null !== $dev) {
            $inputs['include-dev'] = $dev;
            $inputs['include-actions'] = $actions;
        }
        self::assertSame(['status' => $status, 'path' => '.changelog/dependabot-7.md', 'head_sha' => $this->sha(), 'diagnostics' => ['safe detail']], $this->runner(compact('dependabotInputs', 'dependabot'))->run('dependabot', $inputs));
    }

    #[Test]
    #[TestWith(['{}'])]
    #[TestWith(['null'])]
    #[TestWith(['{"key":1}'])]
    public function alertMetadataRequiresAJsonList(string $alerts): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a JSON array');
        $this->runner()->run('dependabot', ['security-alert-numbers' => $alerts]);
    }

    #[Test]
    public function malformedJsonFailsBeforeDependabotFactoryOrNetwork(): void
    {
        $this->expectException(JsonException::class);
        $this->runner()->run('dependabot', ['security-alert-numbers' => '{']);
    }

    #[Test]
    #[TestWith(['refused'])]
    #[TestWith(['conflict'])]
    public function refusedOrConflictedWritesCannotReportWorkflowSuccess(string $status): void
    {
        $input = new VersionPullRequestInput();
        $versionInputs = $this->createMock(VersionPullRequestInputFactoryInterface::class);
        $versionInputs->expects(self::once())->method('create')->with('main', 'changelog/version', 'github-actions[bot]', 'chore: update changelog', false)->willReturn($input);
        $versions = $this->createMock(VersionPullRequestServiceInterface::class);
        $versions->expects(self::once())->method('synchronize')->with($this->options(), $input)->willReturn(new VersionPullRequestResult($status, diagnostics: ['head changed']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Automation ' . $status . ': head changed');
        $this->runner(compact('versionInputs', 'versions'))->run('version', []);
    }

    #[Test]
    #[TestWith([true])]
    #[TestWith(['true'])]
    #[TestWith([false])]
    #[TestWith(['false'])]
    public function versionUsesSharedServiceAndPreservesTransactionSummary(mixed $dry): void
    {
        $input = new VersionPullRequestInput('stable', 'managed', 'app[bot]', 'Version packages', true === $dry || 'true' === $dry);
        $versionInputs = $this->createMock(VersionPullRequestInputFactoryInterface::class);
        $versionInputs->expects(self::once())->method('create')->with('stable', 'managed', 'app[bot]', 'Version packages', $input->dryRun)->willReturn($input);
        $versions = $this->createMock(VersionPullRequestServiceInterface::class);
        $versions->expects(self::once())->method('synchronize')->with($this->options(), $input)->willReturn(new VersionPullRequestResult('updated', 7, 'https://example.test/7', $this->sha(), 'plan', '1.2.3', true, ['maintenance']));
        self::assertSame(['status' => 'updated', 'pull_request' => 7, 'url' => 'https://example.test/7', 'head_sha' => $this->sha(), 'plan_id' => 'plan', 'version' => '1.2.3', 'maintenance' => true, 'diagnostics' => ['maintenance']], $this->runner(compact('versionInputs', 'versions'))->run('version', ['base-branch' => 'stable', 'managed-branch' => 'managed', 'automation-actor' => 'app[bot]', 'title' => 'Version packages', 'dry-run' => $dry]));
    }

    #[Test]
    #[TestWith(['TRUE'])]
    #[TestWith(['0'])]
    #[TestWith([1])]
    #[TestWith([[]])]
    #[TestWith([null])]
    public function booleanInputsRejectCoercionsBeforeAnyService(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be true or false');
        $this->runner()->run('version', ['dry-run' => $value]);
    }

    #[Test]
    #[TestWith([''])]
    #[TestWith(['HEAD'])]
    #[TestWith(['abc123'])]
    public function publicationRequiresCompleteApprovedTargetBeforeNetwork(string $sha): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('complete approved target-sha');
        $this->runner()->run('publish', ['target-sha' => $sha]);
    }

    #[Test]
    public function expectedHeadWithoutPrCannotPretendToCarryMergeEvidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected-head-sha requires pull-request');
        $this->runner()->run('publish', ['target-sha' => $this->sha(), 'expected-head-sha' => $this->sha('b')]);
    }

    #[Test]
    public function directPublicationDelegatesApprovedIdentityAndPreviewWithoutGithubRequests(): void
    {
        $result = new PublicationResult('dry-run', '1.2.3', 'v1.2.3', $this->sha(), null, ['create_tag', 'create_release']);
        $publication = $this->createMock(PublicationServiceInterface::class);
        $publication->expects(self::once())->method('publish')->with($this->options(), $this->sha(), true)->willReturn($result);
        self::assertSame($result->summary(), $this->runner(compact('publication'))->run('publish', ['target-sha' => $this->sha(), 'dry-run' => 'true']));
    }

    #[Test]
    public function mergedPublicationRequiresAccountIdentityAndSignedHeadBoundToMerge(): void
    {
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::exactly(2))->method('request')->willReturnCallback(function (string $method, string $path): array {
            self::assertSame('GET', $method);
            return match ($path) {
                '/repos/owner/project/pulls/7' => $this->mergedPr(),
                '/users/app%5Bbot%5D' => $this->mergedPr()['user'],
            };
        });
        $policy = $this->createMock(PullRequestPolicyInterface::class);
        $policy->expects(self::once())->method('inspectHead')->with($this->options(), $this->sha('b'), 'app[bot]', $this->sha())->willReturn(true);
        $publication = $this->createMock(PublicationServiceInterface::class);
        $result = new PublicationResult('published', '1.2.3', 'v1.2.3', $this->sha(), 'https://example.test/release', ['create_release']);
        $publication->expects(self::once())->method('publish')->with($this->options(), $this->sha(), false)->willReturn($result);
        self::assertSame($result->summary(), $this->runner(compact('github', 'policy', 'publication'))->run('publish', $this->publishInputs()));
    }

    #[Test]
    #[DataProvider('invalidMergeEvidence')]
    public function incompleteOrChangedMergeEvidenceStopsPublication(array $replacement): void
    {
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::once())->method('request')->with('GET', '/repos/owner/project/pulls/7')->willReturn(array_replace_recursive($this->mergedPr(), $replacement));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable head/merge identities');
        $this->runner(compact('github'))->run('publish', $this->publishInputs());
    }

    public static function invalidMergeEvidence(): iterable
    {
        yield 'different PR' => [['number' => 8]];
        yield 'open PR' => [['state' => 'open']];
        yield 'unmerged PR' => [['merged' => false]];
        yield 'different merge' => [['merge_commit_sha' => str_repeat('c', 40)]];
        yield 'different head' => [['head' => ['sha' => str_repeat('c', 40)]]];
        yield 'fork head' => [['head' => ['repo' => ['full_name' => 'fork/project']]]];
        yield 'different base repository' => [['base' => ['repo' => ['full_name' => 'other/project']]]];
        yield 'unmanaged head branch' => [['head' => ['ref' => 'human-branch']]];
        yield 'different base branch' => [['base' => ['ref' => 'develop']]];
        yield 'human PR author' => [['user' => ['type' => 'User']]];
    }

    #[Test]
    #[TestWith([null])]
    #[TestWith([['login' => 'app[bot]', 'type' => 'Bot', 'id' => 999]])]
    public function missingOrDifferentCurrentAccountCannotOwnMergedPr(?array $account): void
    {
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::exactly(2))->method('request')->willReturnOnConsecutiveCalls($this->mergedPr(), $account);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('signed automation ownership');
        $this->runner(compact('github'))->run('publish', $this->publishInputs());
    }

    #[Test]
    public function unsignedHeadFailsEvenAfterExactMergeAndAccountEvidence(): void
    {
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::exactly(2))->method('request')->willReturnOnConsecutiveCalls($this->mergedPr(), $this->mergedPr()['user']);
        $policy = $this->createMock(PullRequestPolicyInterface::class);
        $policy->expects(self::once())->method('inspectHead')->with($this->options(), $this->sha('b'), 'app[bot]', $this->sha())->willReturn(false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('signed automation ownership');
        $this->runner(compact('github', 'policy'))->run('publish', $this->publishInputs());
    }

    #[Test]
    public function absentRemotePrCannotBePublished(): void
    {
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::once())->method('request')->willReturn(null);
        $this->expectException(RuntimeException::class);
        $this->runner(compact('github'))->run('publish', $this->publishInputs());
    }

    #[Test]
    #[TestWith(['backfill', 'true', 'false', false, 'dry-run'])]
    #[TestWith(['format', 'false', 'true', false, 'verified'])]
    #[TestWith(['backfill', 'false', 'false', true, 'applied'])]
    #[TestWith(['format', 'false', 'false', false, 'unchanged'])]
    public function historyUsesOnePlanAndKeepsPreviewAndCheckReadOnly(string $operation, string $dry, string $check, bool $applied, string $status): void
    {
        $plan = $this->plan();
        $planner = $this->createMock(ReleasePlannerInterface::class);
        $planner->expects(self::once())->method('plan')->with($this->options(), $operation)->willReturn($plan);
        $applier = $this->createMock(ReleaseApplierInterface::class);
        $applier->expects('true' === $check ? self::once() : self::never())->method('isApplied')->with($plan)->willReturn(true);
        $applier->expects('false' === $dry && 'false' === $check ? self::once() : self::never())->method('apply')->with($plan)->willReturn($applied);
        self::assertSame([...$plan->summary(), 'status' => $status], $this->runner(compact('planner', 'applier'))->run('history', ['operation' => $operation, 'dry-run' => $dry, 'check' => $check]));
    }

    #[Test]
    #[TestWith([['operation' => 'version']])]
    #[TestWith([['dry-run' => 'true', 'check' => 'true']])]
    public function contradictoryOrUnsupportedHistorySettingsCannotPlan(array $inputs): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mutually exclusive');
        $this->runner()->run('history', $inputs);
    }

    #[Test]
    public function historicalCheckFailsOnDifferenceWithoutMutating(): void
    {
        $plan = $this->plan();
        $planner = $this->createMock(ReleasePlannerInterface::class);
        $planner->expects(self::once())->method('plan')->with($this->options(), 'backfill')->willReturn($plan);
        $applier = $this->createMock(ReleaseApplierInterface::class);
        $applier->expects(self::once())->method('isApplied')->with($plan)->willReturn(false);
        $applier->expects(self::never())->method('apply');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differs from');
        $this->runner(compact('planner', 'applier'))->run('history', ['check' => 'true']);
    }

    private function runner(array $services = [], bool $createOptions = true): AutomationRunner
    {
        $options = $services['options'] ?? $this->createMock(ReleaseOptionsFactoryInterface::class);
        if (! isset($services['options'])) {
            $options->expects($createOptions ? self::once() : self::never())->method('create')->with([])->willReturn($this->options());
        }
        $exceptions = $this->createStub(ReleaseExceptionFactoryInterface::class);
        $exceptions->method('invalid')->willReturnCallback(static fn(string $message): InvalidArgumentException => new InvalidArgumentException($message));
        $exceptions->method('failure')->willReturnCallback(static fn(string $message): RuntimeException => new RuntimeException($message));
        return new AutomationRunner(
            $options,
            $services['git'] ?? $this->noCalls(GitRepositoryInterface::class),
            $services['policy'] ?? $this->noCalls(PullRequestPolicyInterface::class),
            $services['checks'] ?? $this->noCalls(CheckServiceInterface::class),
            $services['dependabotInputs'] ?? $this->noCalls(DependabotInputFactoryInterface::class),
            $services['dependabot'] ?? $this->noCalls(DependabotFragmentServiceInterface::class),
            $services['versionInputs'] ?? $this->noCalls(VersionPullRequestInputFactoryInterface::class),
            $services['versions'] ?? $this->noCalls(VersionPullRequestServiceInterface::class),
            $services['publication'] ?? $this->noCalls(PublicationServiceInterface::class),
            $services['planner'] ?? $this->noCalls(ReleasePlannerInterface::class),
            $services['applier'] ?? $this->noCalls(ReleaseApplierInterface::class),
            $services['github'] ?? $this->noCalls(GitHubClientInterface::class),
            $exceptions,
        );
    }

    private function noCalls(string $interface): object
    {
        $mock = $this->createMock($interface);
        foreach (new ReflectionClass($interface)->getMethods() as $method) {
            if (GitRepositoryInterface::class === $interface && 'repositoryRoot' === $method->getName()) {
                $mock->method('repositoryRoot')->willReturn('/consumer');
                continue;
            }
            $mock->expects(self::never())->method($method->getName());
        }
        return $mock;
    }

    private function options(): ReleaseOptions
    {
        return new ReleaseOptions('/consumer', repository: 'owner/project');
    }

    private function sha(string $character = 'a'): string
    {
        return str_repeat($character, 40);
    }

    private function mergedPr(): array
    {
        return ['number' => 7, 'state' => 'closed', 'merged' => true, 'merge_commit_sha' => $this->sha(), 'head' => ['sha' => $this->sha('b'), 'ref' => 'managed', 'repo' => ['full_name' => 'owner/project']], 'base' => ['ref' => 'stable', 'repo' => ['full_name' => 'owner/project']], 'user' => ['id' => 101, 'login' => 'app[bot]', 'type' => 'Bot']];
    }

    private function publishInputs(): array
    {
        return ['target-sha' => $this->sha(), 'pull-request' => '7', 'expected-head-sha' => $this->sha('b'), 'managed-branch' => 'managed', 'base-branch' => 'stable', 'automation-actor' => 'app[bot]'];
    }

    private function plan(): ReleasePlan
    {
        return new ReleasePlan($this->options(), 'plan-id', $this->sha(), '1.0.0', null, null, [], [], '/consumer/CHANGELOG.md', 'original', 'changed', 'exact notes', '/consumer/.changelog/release-plan.json', null, '{}');
    }
}
