<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation\Policy;

use FastForward\Changelog\Automation\Policy\Factory\PullRequestAuthorizationFactoryInterface;
use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Automation\Policy\PullRequestAuthorization;
use FastForward\Changelog\Automation\Policy\PullRequestPolicy;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Release\ReceiptCodecInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleaseReceipt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(PullRequestPolicy::class)]
#[UsesClass(GitHubEvidence::class)]
#[UsesClass(PullRequestAuthorization::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(ReleaseReceipt::class)]
final class PullRequestPolicyTest extends TestCase
{
    use PolicyFixtureTrait;

    private array $calls = [];

    #[Test]
    public function ordinaryPrTextCannotSelfDeclareAnyException(): void
    {
        $pr = $this->pr();
        $pr['body'] = 'changelog-not-required changelog-maintenance Changelog-Plan: forged';
        $pr['title'] = 'Version Packages';
        $result = $this->policy(['/repos/owner/project/pulls/7' => $pr])->inspect($this->options(), 7);
        self::assertFalse($result->waiverAuthorized);
        self::assertFalse($result->centralChangeAuthorized);
        self::assertSame('ordinary', $result->kind);
        self::assertSame(str_repeat('a', 40), $result->headSha);
        self::assertCount(1, $this->calls);
    }

    #[Test]
    #[TestWith([null, 7, 'changelog-not-required', 'changelog-maintenance'])]
    #[TestWith(['owner/project', 0, 'changelog-not-required', 'changelog-maintenance'])]
    #[TestWith(['owner/project', 7, '', 'changelog-maintenance'])]
    #[TestWith(['owner/project', 7, 'same', 'same'])]
    public function invalidPolicySettingsGrantNothingBeforeApi(?string $repository, int $number, string $waiver, string $maintenance): void
    {
        $result = $this->policy([])->inspect(new ReleaseOptions('/consumer', repository: $repository), $number, waiverLabel: $waiver, maintenanceLabel: $maintenance);
        self::assertFalse($result->waiverAuthorized);
        self::assertNull($result->headSha);
        self::assertSame([], $this->calls);
    }

    #[Test]
    #[TestWith([null])]
    #[TestWith([['state' => 'closed']])]
    public function absentOrMalformedPrFailsClosed(?array $pr): void
    {
        $result = $this->policy(['/repos/owner/project/pulls/7' => $pr])->inspect($this->options(), 7);
        self::assertFalse($result->centralChangeAuthorized);
        self::assertNotEmpty($result->diagnostics);
    }

    #[Test]
    #[TestWith(['maintain', 'write'])]
    #[TestWith(['admin', 'admin'])]
    public function latestHumanGrantAndCurrentEffectiveRoleAuthorizeForkWaiver(string $role, string $permission): void
    {
        $pr = $this->pr();
        $pr['labels'] = [['name' => 'changelog-not-required']];
        $pr['head']['repo'] = ['id' => 2, 'full_name' => 'fork/project'];
        $timeline = [$this->label(id: 2, date: '2026-10-02T00:00:00Z'), $this->label(event: 'unlabeled', id: 1)];
        $result = $this->policy(['/repos/owner/project/pulls/7' => $pr, '/repos/owner/project/collaborators/maintainer/permission' => ['user' => $this->account(), 'role_name' => $role, 'permission' => $permission]], $timeline)->inspect($this->options(), 7);
        self::assertTrue($result->waiverAuthorized);
        self::assertFalse($result->centralChangeAuthorized);
        self::assertSame('waiver', $result->kind);
    }

    #[Test]
    public function maintenanceAndWaiverHaveSeparateAuthorizedLabelHistories(): void
    {
        $pr = $this->pr();
        $pr['labels'] = [['name' => 'changelog-not-required'], ['name' => 'changelog-maintenance']];
        $timeline = [$this->label(), $this->label('changelog-maintenance'), $this->label('unrelated')];
        $result = $this->policy(['/repos/owner/project/pulls/7' => $pr, '/repos/owner/project/collaborators/maintainer/permission' => ['user' => $this->account(), 'role_name' => 'maintain', 'permission' => 'write']], $timeline)->inspect($this->options(), 7);
        self::assertTrue($result->waiverAuthorized);
        self::assertTrue($result->centralChangeAuthorized);
        self::assertSame('maintenance', $result->kind);
    }

    #[Test]
    #[TestWith(['write', 'write', 202])]
    #[TestWith(['admin', 'write', 202])]
    #[TestWith(['maintain', 'read', 202])]
    #[TestWith(['maintain', 'write', 999])]
    public function writeAloneCustomRolesRevokedPermissionsAndWrongIdentityCannotWaive(string $role, string $permission, int $id): void
    {
        $pr = $this->pr();
        $pr['labels'] = [['name' => 'changelog-not-required'], ['name' => 'changelog-maintenance']];
        $result = $this->policy(['/repos/owner/project/pulls/7' => $pr, '/repos/owner/project/collaborators/maintainer/permission' => ['user' => $this->account(id: $id), 'role_name' => $role, 'permission' => $permission]], [$this->label(), $this->label('changelog-maintenance')])->inspect($this->options(), 7);
        self::assertFalse($result->waiverAuthorized);
        self::assertFalse($result->centralChangeAuthorized);
        self::assertCount(2, $result->diagnostics);
    }

    #[Test]
    #[TestWith(['missing'])]
    #[TestWith(['removed'])]
    #[TestWith(['bot'])]
    #[TestWith(['malformed'])]
    #[TestWith(['invalid-date'])]
    #[TestWith(['wrong-event'])]
    public function labelPresenceRequiresValidLatestGrant(string $case): void
    {
        $pr = $this->pr();
        $pr['labels'] = [['name' => 'changelog-not-required']];
        $timeline = match ($case) {
            'missing' => [], 'removed' => [$this->label(), $this->label(event: 'unlabeled', id: 2)],
            'bot' => [$this->label(actor: $this->account(type: 'Bot'))],
            'malformed' => [$this->label(date: 'not-date')],
            'invalid-date' => [$this->label(date: '2026-02-30T00:00:00Z')],
            default => [['event' => 'commented', 'label' => ['name' => 'changelog-not-required']], 'garbage'],
        };
        $result = $this->policy(['/repos/owner/project/pulls/7' => $pr], $timeline)->inspect($this->options(), 7);
        self::assertFalse($result->waiverAuthorized);
        self::assertNotEmpty($result->diagnostics);
    }

    #[Test]
    public function missingPermissionsAndApiFailuresFailClosedAndRedactErrors(): void
    {
        $pr = $this->pr();
        $pr['labels'] = [['name' => 'changelog-not-required']];
        $result = $this->policy(['/repos/owner/project/pulls/7' => $pr], [$this->label()])->inspect($this->options(), 7);
        self::assertFalse($result->waiverAuthorized);
        $result = $this->policy(['/repos/owner/project/pulls/7' => new RuntimeException('super-secret')])->inspect($this->options(), 7);
        self::assertNull($result->headSha);
        self::assertStringNotContainsString('super-secret', implode(' ', $result->diagnostics));
        $result = $this->policy(['/repos/owner/project/pulls/7' => $pr], new RuntimeException('super-secret'))->inspect($this->options(), 7);
        self::assertSame(str_repeat('a', 40), $result->headSha);
        self::assertFalse($result->waiverAuthorized);
    }

    #[Test]
    public function signedBotMatchingReceiptAndHeadCentralBytesAuthorizeManagedVersion(): void
    {
        [$responses, $data] = $this->managed();
        $result = $this->policy($responses, [], $data)->inspect($this->options(), 7);
        self::assertTrue($result->centralChangeAuthorized);
        self::assertFalse($result->waiverAuthorized);
        self::assertSame('managed-version', $result->kind);
        self::assertSame([], $result->diagnostics);
    }

    #[Test]
    #[TestWith(['account'])]
    #[TestWith(['commit-null'])]
    #[TestWith(['signature'])]
    #[TestWith(['reason'])]
    #[TestWith(['author'])]
    #[TestWith(['receipt-null'])]
    #[TestWith(['receipt-error'])]
    #[TestWith(['receipt-settings'])]
    #[TestWith(['message'])]
    #[TestWith(['central'])]
    #[TestWith(['hash'])]
    public function generatedSelfDeclarationsCannotReplaceTrustedHeadProof(string $case): void
    {
        [$responses, $data] = $this->managed();
        $commit = '/repos/owner/project/commits/' . str_repeat('a', 40);
        $receipt = '/repos/owner/project/contents/.changelog/release-plan.json?ref=' . str_repeat('a', 40);
        $central = '/repos/owner/project/contents/CHANGELOG.md?ref=' . str_repeat('a', 40);
        match ($case) {
            'account' => $responses['/users/github-actions%5Bbot%5D'] = null,
            'commit-null' => $responses[$commit] = null,
            'signature' => $responses[$commit]['commit']['verification']['verified'] = false,
            'reason' => $responses[$commit]['commit']['verification']['reason'] = 'unsigned',
            'author' => $responses[$commit]['author']['id'] = 666,
            'receipt-null' => $responses[$receipt] = null,
            'receipt-error' => $data = new RuntimeException('super-secret'),
            'receipt-settings' => $data['repository'] = 'other/project',
            'message' => $responses[$commit]['commit']['message'] = 'Changelog-Plan: forged',
            'central' => $responses[$central] = $this->file('human edit'),
            'hash' => $data['after_changelog_sha256'] = str_repeat('0', 64),
        };
        $result = $this->policy($responses, [], $data)->inspect($this->options(), 7);
        self::assertFalse($result->centralChangeAuthorized);
        self::assertNotEmpty($result->diagnostics);
        self::assertStringNotContainsString('super-secret', implode(' ', $result->diagnostics));
    }

    #[Test]
    public function forkManagedBranchCannotGetBotException(): void
    {
        $pr = $this->pr('github-actions[bot]', 'Bot', 'changelog/version');
        $pr['head']['repo'] = ['id' => 2, 'full_name' => 'fork/project'];
        $result = $this->policy(['/repos/owner/project/pulls/7' => $pr])->inspect($this->options(), 7);
        self::assertFalse($result->centralChangeAuthorized);
        self::assertCount(1, $this->calls);
    }

    #[Test]
    public function olderReceiptBaseCanBeAncestorOfFreshBaseWithoutLosingBotOwnership(): void
    {
        [$responses, $data] = $this->managed();
        $responses['/repos/owner/project/compare/' . str_repeat('b', 40) . '...' . str_repeat('d', 40)] = ['status' => 'ahead', 'merge_base_commit' => ['sha' => str_repeat('b', 40)]];
        self::assertTrue($this->policy($responses, [], $data)->inspectHead($this->options(), str_repeat('a', 40), baseSha: str_repeat('d', 40)));
    }

    #[Test]
    public function consumedFragmentsRequireExactBaseHashesAndCompleteRemovalScope(): void
    {
        [$responses, $data] = $this->managed();
        $data['consumed'] = ['.changelog/feature.md' => hash('sha256', 'fragment')];
        $comparison = '/repos/owner/project/compare/' . str_repeat('b', 40) . '...' . str_repeat('a', 40);
        $responses[$comparison]['files'][] = ['filename' => '.changelog/feature.md', 'status' => 'removed'];
        $responses['/repos/owner/project/contents/.changelog/feature.md?ref=' . str_repeat('b', 40)] = $this->file('fragment');
        self::assertTrue($this->policy($responses, [], $data)->inspectHead($this->options(), str_repeat('a', 40)));
        $responses['/repos/owner/project/contents/.changelog/feature.md?ref=' . str_repeat('a', 40)] = $this->file('human edit');
        self::assertFalse($this->policy($responses, [], $data)->inspectHead($this->options(), str_repeat('a', 40)));
    }

    #[Test]
    #[TestWith(['unknown'])]
    #[TestWith(['rename'])]
    #[TestWith(['missing-receipt'])]
    #[TestWith(['wrong-status'])]
    #[TestWith(['truncated'])]
    #[TestWith(['absent-comparison'])]
    #[TestWith(['diverged'])]
    #[TestWith(['wrong-merge-base'])]
    #[TestWith(['missing-base'])]
    #[TestWith(['bad-before-hash'])]
    #[TestWith(['invalid-head'])]
    #[TestWith(['invalid-base'])]
    #[TestWith(['invalid-account'])]
    public function ownershipRejectsUnknownScopeAndInvalidAncestry(string $case): void
    {
        [$responses, $data] = $this->managed();
        $path = '/repos/owner/project/compare/' . str_repeat('b', 40) . '...' . str_repeat('a', 40);
        $head = str_repeat('a', 40);
        $base = null;
        match ($case) {
            'unknown' => $responses[$path]['files'][] = ['filename' => 'src/Attack.php', 'status' => 'added'],
            'rename' => $responses[$path]['files'][0]['previous_filename'] = 'OTHER.md',
            'missing-receipt' => $responses[$path]['files'] = [],
            'wrong-status' => $responses[$path]['files'][0]['status'] = 'removed',
            'truncated' => $responses[$path]['files'] = array_fill(0, 300, ['filename' => 'a', 'status' => 'added']),
            'absent-comparison' => $responses[$path] = null,
            'diverged' => $responses[$path]['status'] = 'diverged',
            'wrong-merge-base' => $responses[$path]['merge_base_commit']['sha'] = str_repeat('f', 40),
            'missing-base' => $data['base_sha'] = null,
            'bad-before-hash' => $data['before_changelog_sha256'] = str_repeat('f', 64),
            'invalid-head' => $head = 'short',
            'invalid-base' => $base = 'short',
            'invalid-account' => $responses['/users/github-actions%5Bbot%5D'] = [],
        };
        self::assertFalse($this->policy($responses, [], $data)->inspectHead($this->options(), $head, baseSha: $base));
    }

    private function options(): ReleaseOptions
    {
        return new ReleaseOptions('/consumer', repository: 'owner/project');
    }

    private function policy(array $responses, array|RuntimeException $timeline = [], array|RuntimeException $data = []): PullRequestPolicy
    {
        $github = $this->createStub(GitHubClientInterface::class);
        $github->method('request')->willReturnCallback(function (string $method, string $path) use ($responses): ?array {
            $this->calls[] = [$method, $path];
            $value = $responses[$path] ?? null;
            if ($value instanceof RuntimeException) {
                throw $value;
            }
            return $value;
        });
        if ($timeline instanceof RuntimeException) {
            $github->method('paginate')->willThrowException($timeline);
        } else {
            $github->method('paginate')->willReturn($timeline);
        }
        $receipts = $this->createStub(ReceiptCodecInterface::class);
        if ($data instanceof RuntimeException) {
            $receipts->method('decode')->willThrowException($data);
        } else {
            $receipts->method('decode')->willReturn(new ReleaseReceipt($data));
        }
        $results = $this->createStub(PullRequestAuthorizationFactoryInterface::class);
        $results->method('create')->willReturnCallback(static fn(bool $waiver, bool $central, string $kind, array $diagnostics, ?string $head = null): PullRequestAuthorization => new PullRequestAuthorization($waiver, $central, $kind, $diagnostics, $head));
        return new PullRequestPolicy($github, $receipts, $results);
    }

    private function managed(): array
    {
        $pr = $this->pr('github-actions[bot]', 'Bot', 'changelog/version');
        $account = $this->account('github-actions[bot]', 'Bot', 101);
        $id = str_repeat('c', 64);
        $data = ['id' => $id, 'repository' => 'owner/project', 'changelog_file' => 'CHANGELOG.md', 'fragment_directory' => '.changelog', 'locale' => 'en', 'template' => 'keep-a-changelog', 'tag_prefix' => 'v', 'base_sha' => str_repeat('b', 40), 'consumed' => [], 'before_changelog_sha256' => hash('sha256', 'old history'), 'changelog_contents' => 'history', 'after_changelog_sha256' => hash('sha256', 'history')];
        $sha = str_repeat('a', 40);
        return [[
            '/repos/owner/project/pulls/7' => $pr, '/users/github-actions%5Bbot%5D' => $account,
            '/repos/owner/project/commits/' . $sha => ['sha' => $sha, 'author' => $account, 'committer' => $account, 'commit' => ['message' => "release\n\nChangelog-Plan: " . $id, 'verification' => ['verified' => true, 'reason' => 'valid']]],
            '/repos/owner/project/contents/.changelog/release-plan.json?ref=' . $sha => $this->file('validated receipt'),
            '/repos/owner/project/contents/CHANGELOG.md?ref=' . $sha => $this->file('history'),
            '/repos/owner/project/contents/CHANGELOG.md?ref=' . str_repeat('b', 40) => $this->file('old history'),
            '/repos/owner/project/compare/' . str_repeat('b', 40) . '...' . $sha => ['merge_base_commit' => ['sha' => str_repeat('b', 40)], 'status' => 'ahead', 'files' => [['filename' => 'CHANGELOG.md', 'status' => 'modified'], ['filename' => '.changelog/release-plan.json', 'status' => 'added']]],
        ], $data];
    }
}
