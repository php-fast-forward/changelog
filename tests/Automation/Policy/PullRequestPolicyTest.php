<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation\Policy;

use FastForward\Changelog\Automation\Policy\Factory\PullRequestAuthorizationFactoryInterface;
use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Automation\Policy\PullRequestAuthorization;
use FastForward\Changelog\Automation\Policy\PullRequestPolicy;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Release\ReleaseOptions;
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
    public function invalidPolicySettingsGrantNothingBeforeApi(
        ?string $repository,
        int $number,
        string $waiver,
        string $maintenance,
    ): void {
        $result = $this->policy([])->inspect(
            new ReleaseOptions('/consumer', repository: $repository),
            $number,
            waiverLabel: $waiver,
            maintenanceLabel: $maintenance,
        );
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
        $result = $this->policy(
            ['/repos/owner/project/pulls/7' => $pr, '/repos/owner/project/collaborators/maintainer/permission' => ['user' => $this->account(), 'role_name' => $role, 'permission' => $permission]],
            $timeline,
        )->inspect(
            $this->options(),
            7,
        );
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
        $result = $this->policy(
            ['/repos/owner/project/pulls/7' => $pr, '/repos/owner/project/collaborators/maintainer/permission' => ['user' => $this->account(), 'role_name' => 'maintain', 'permission' => 'write']],
            $timeline,
        )->inspect(
            $this->options(),
            7,
        );
        self::assertTrue($result->waiverAuthorized);
        self::assertTrue($result->centralChangeAuthorized);
        self::assertSame('maintenance', $result->kind);
    }

    #[Test]
    #[TestWith(['write', 'write', 202])]
    #[TestWith(['admin', 'write', 202])]
    #[TestWith(['maintain', 'read', 202])]
    #[TestWith(['maintain', 'write', 999])]
    public function writeAloneCustomRolesRevokedPermissionsAndWrongIdentityCannotWaive(
        string $role,
        string $permission,
        int $id,
    ): void {
        $pr = $this->pr();
        $pr['labels'] = [['name' => 'changelog-not-required'], ['name' => 'changelog-maintenance']];
        $result = $this->policy(
            ['/repos/owner/project/pulls/7' => $pr, '/repos/owner/project/collaborators/maintainer/permission' => ['user' => $this->account(
                id: $id,
            ), 'role_name' => $role, 'permission' => $permission]],
            [$this->label(), $this->label('changelog-maintenance')],
        )->inspect(
            $this->options(),
            7,
        );
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
        $result = $this->policy(['/repos/owner/project/pulls/7' => $pr], [$this->label()])->inspect(
            $this->options(),
            7,
        );
        self::assertFalse($result->waiverAuthorized);
        $result = $this->policy(['/repos/owner/project/pulls/7' => new RuntimeException('super-secret')])->inspect(
            $this->options(),
            7,
        );
        self::assertNull($result->headSha);
        self::assertStringNotContainsString('super-secret', implode(' ', $result->diagnostics));
        $result = $this->policy(['/repos/owner/project/pulls/7' => $pr], new RuntimeException('super-secret'))->inspect(
            $this->options(),
            7,
        );
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
        self::assertSame(
            [],
            array_filter(
                $this->calls,
                static fn(array $call): bool => '/graphql' === $call[1] || '/users/web-flow' === $call[1],
            ),
        );
    }

    /** GitHub's platform committer requires its exact account and a read-only signature proof at the same SHA. */
    #[Test]
    public function signedBotAuthorWithGitHubPlatformCommitterRetainsManagedOwnership(): void
    {
        [$responses, $data] = $this->platformManaged();
        $result = $this->policy($responses, [], $data)->inspect($this->options(), 7);
        self::assertTrue($result->centralChangeAuthorized);
        self::assertSame('managed-version', $result->kind);
        $queries = array_values(array_filter($this->calls, static fn(array $call): bool => '/graphql' === $call[1]));
        self::assertCount(1, $queries);
        self::assertSame('POST', $queries[0][0]);
        self::assertStringStartsWith('query ChangelogPlatformSignature(', $queries[0][2]['query']);
        self::assertStringNotContainsString('mutation', $queries[0][2]['query']);
        self::assertSame([
            'owner' => 'owner', 'name' => 'project', 'oid' => str_repeat('a', 40)],
            $queries[0][2]['variables'],
        );
    }

    /** Platform signing cannot replace the configured Bot identity, canonical account or transaction scope. */
    #[Test]
    #[TestWith(['arbitrary-user'])]
    #[TestWith(['committer-id'])]
    #[TestWith(['committer-type'])]
    #[TestWith(['platform-null'])]
    #[TestWith(['platform-id'])]
    #[TestWith(['platform-login'])]
    #[TestWith(['platform-type'])]
    #[TestWith(['graphql-null'])]
    #[TestWith(['graphql-error'])]
    #[TestWith(['graphql-error-null'])]
    #[TestWith(['graphql-exception'])]
    #[TestWith(['wrong-oid'])]
    #[TestWith(['invalid-signature'])]
    #[TestWith(['invalid-state'])]
    #[TestWith(['other-signing-key'])]
    #[TestWith(['missing-object'])]
    #[TestWith(['missing-signature'])]
    #[TestWith(['string-valid'])]
    #[TestWith(['string-platform-key'])]
    #[TestWith(['author-id'])]
    #[TestWith(['rest-unsigned'])]
    #[TestWith(['rest-reason'])]
    #[TestWith(['receipt-settings'])]
    #[TestWith(['unknown-scope'])]
    #[TestWith(['diverged'])]
    public function platformCommitterProofFailsClosedForIncompleteOrUntrustedEvidence(string $case): void
    {
        [$responses, $data] = $this->platformManaged();
        $commit = '/repos/owner/project/commits/' . str_repeat('a', 40);
        $comparison = '/repos/owner/project/compare/' . str_repeat('b', 40) . '...' . str_repeat('a', 40);
        match ($case) {
            'arbitrary-user' => $responses[$commit]['committer']['login'] = 'maintainer',
            'committer-id' => $responses[$commit]['committer']['id'] = 666,
            'committer-type' => $responses[$commit]['committer']['type'] = 'Bot',
            'platform-null' => $responses['/users/web-flow'] = null,
            'platform-id' => $responses['/users/web-flow']['id'] = 666,
            'platform-login' => $responses['/users/web-flow']['login'] = 'maintainer',
            'platform-type' => $responses['/users/web-flow']['type'] = 'Bot',
            'graphql-null' => $responses['/graphql'] = null,
            'graphql-error' => $responses['/graphql']['errors'] = [['message' => 'synthetic-super-secret']],
            'graphql-error-null' => $responses['/graphql']['errors'] = null,
            'graphql-exception' => $responses['/graphql'] = new RuntimeException('synthetic-super-secret'),
            'wrong-oid' => $responses['/graphql']['data']['repository']['object']['oid'] = str_repeat('f', 40),
            'invalid-signature' => $responses['/graphql']['data']['repository']['object']['signature']['isValid'] = false,
            'invalid-state' => $responses['/graphql']['data']['repository']['object']['signature']['state'] = 'UNKNOWN_KEY',
            'other-signing-key' => $responses['/graphql']['data']['repository']['object']['signature']['wasSignedByGitHub'] = false,
            'missing-object' => $responses['/graphql']['data']['repository']['object'] = null,
            'missing-signature' => $responses['/graphql']['data']['repository']['object']['signature'] = null,
            'string-valid' => $responses['/graphql']['data']['repository']['object']['signature']['isValid'] = 'true',
            'string-platform-key' => $responses['/graphql']['data']['repository']['object']['signature']['wasSignedByGitHub'] = 'true',
            'author-id' => $responses[$commit]['author']['id'] = 666,
            'rest-unsigned' => $responses[$commit]['commit']['verification']['verified'] = false,
            'rest-reason' => $responses[$commit]['commit']['verification']['reason'] = 'unsigned',
            'receipt-settings' => $data['repository'] = 'other/project',
            'unknown-scope' => $responses[$comparison]['files'][] = ['filename' => 'src/Attack.php', 'status' => 'added'],
            'diverged' => $responses[$comparison]['status'] = 'diverged',
        };
        $result = $this->policy($responses, [], $data)->inspect($this->options(), 7);
        self::assertFalse($result->centralChangeAuthorized);
        self::assertNotEmpty($result->diagnostics);
        self::assertStringNotContainsString('synthetic-super-secret', implode(' ', $result->diagnostics));
        foreach ($this->calls as $call) {
            self::assertTrue(
                'GET' === $call[0] || ('/graphql' === $call[1] && str_starts_with($call[2]['query'], 'query ')),
            );
        }
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
            'receipt-null' => $responses[$commit]['commit']['message'] = 'missing metadata',
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
    public function olderGeneratedBaseCanBeAncestorOfFreshBaseWithoutLosingBotOwnership(): void
    {
        [$responses, $data] = $this->managed();
        $responses['/repos/owner/project/compare/' . str_repeat('b', 40) . '...' . str_repeat(
            'd',
            40,
        )] = ['status' => 'ahead', 'merge_base_commit' => ['sha' => str_repeat(
            'b',
            40,
        )]];
        self::assertTrue(
            $this->policy($responses, [], $data)->inspectHead($this->options(), str_repeat(
                'a',
                40,
            ), baseSha: str_repeat(
                'd',
                40,
            )),
        );
    }

    /** An older owned head may be safely updated but cannot authorize a stale consolidation for merge. */
    #[Test]
    public function managedMergeAuthorityRequiresTheLatestLiveBase(): void
    {
        [$responses, $data] = $this->managed();
        $responses['/repos/owner/project/pulls/7']['base']['sha'] = str_repeat('d', 40);
        $responses['/repos/owner/project/compare/' . str_repeat('b', 40) . '...' . str_repeat(
            'd',
            40,
        )] = ['status' => 'ahead', 'merge_base_commit' => ['sha' => str_repeat(
            'b',
            40,
        )]];
        $policy = $this->policy($responses, [], $data);
        self::assertTrue($policy->inspectHead($this->options(), str_repeat('a', 40), baseSha: str_repeat('d', 40)));
        $result = $policy->inspect($this->options(), 7);
        self::assertFalse($result->centralChangeAuthorized);
        self::assertSame('ordinary', $result->kind);
        self::assertStringContainsString('resynchronize', implode(' ', $result->diagnostics));
    }

    #[Test]
    public function consumedFragmentsRequireExactBaseHashesAndCompleteRemovalScope(): void
    {
        [$responses, $data] = $this->managed();
        $data['consumed'] = ['.changelog/feature.md' => hash('sha256', 'fragment')];
        $comparison = '/repos/owner/project/compare/' . str_repeat('b', 40) . '...' . str_repeat('a', 40);
        $responses[$comparison]['files'][] = ['filename' => '.changelog/feature.md', 'status' => 'removed'];
        $responses['/repos/owner/project/contents/.changelog/feature.md?ref=' . str_repeat('b', 40)] = $this->file(
            'fragment',
        );
        self::assertTrue($this->policy($responses, [], $data)->inspectHead($this->options(), str_repeat('a', 40)));
        $responses['/repos/owner/project/contents/.changelog/feature.md?ref=' . str_repeat('a', 40)] = $this->file(
            'human edit',
        );
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
            'bad-before-hash' => $data['consumed'] = ['.changelog/unconsumed.md' => hash('sha256', 'fragment')],
            'invalid-head' => $head = 'short',
            'invalid-base' => $base = 'short',
            'invalid-account' => $responses['/users/github-actions%5Bbot%5D'] = [],
        };
        self::assertFalse($this->policy($responses, [], $data)->inspectHead($this->options(), $head, baseSha: $base));
    }

    /** A malformed duplicate cannot hide beside the valid signed commit trailer. */
    #[Test]
    #[TestWith(['Plan'])]
    #[TestWith(['Base'])]
    #[TestWith(['Output'])]
    #[TestWith(['Options'])]
    public function malformedDuplicateTrailerPrefixesCannotAuthorizeTheManagedHead(string $key): void
    {
        [$responses, $data] = $this->managed();
        $message = 'release' . "\n\nChangelog-Plan: " . $data['id'] . "\nChangelog-Base: " . $data['base_sha']
            . "\nChangelog-Output: " . $data['after_changelog_sha256'] . "\nChangelog-Options: " . $this->options()->evidenceHash();
        $responses['/repos/owner/project/commits/' . str_repeat('a', 40)]['commit']['message'] = $message;
        self::assertTrue($this->policy($responses)->inspectHead($this->options(), str_repeat('a', 40)));
        $responses['/repos/owner/project/commits/' . str_repeat(
            'a',
            40,
        )]['commit']['message'] .= "\nChangelog-" . $key . ':invalid';
        self::assertFalse($this->policy($responses)->inspectHead($this->options(), str_repeat('a', 40)));
    }

    /** Source inventories fail closed when listing shape or canonical flat fragment evidence is incomplete. */
    #[Test]
    #[TestWith(['missing-message'])]
    #[TestWith(['missing-directory'])]
    #[TestWith(['object-directory'])]
    #[TestWith(['truncated-directory'])]
    #[TestWith(['missing-path'])]
    #[TestWith(['symlink'])]
    #[TestWith(['nested'])]
    #[TestWith(['outside'])]
    #[TestWith(['missing-deletion'])]
    public function sourceInventoryAndMessageRequireCompleteCanonicalEvidence(string $case): void
    {
        [$responses, $data] = $this->managed();
        $directory = '/repos/owner/project/contents/.changelog?ref=' . str_repeat('b', 40);
        $responses[$directory] = [];
        match ($case) {
            'missing-message' => $responses['/repos/owner/project/commits/' . str_repeat(
                'a',
                40,
            )]['commit']['message'] = null,
            'missing-directory' => $responses[$directory] = null,
            'object-directory' => $responses[$directory] = ['type' => 'dir'],
            'truncated-directory' => $responses[$directory] = array_fill(
                0,
                1000,
                ['path' => '.changelog/a.md', 'type' => 'file'],
            ),
            'missing-path' => $responses[$directory] = [['type' => 'file']],
            'symlink' => $responses[$directory] = [['path' => '.changelog/a.md', 'type' => 'symlink']],
            'nested' => $responses[$directory] = [['path' => '.changelog/nested/a.md', 'type' => 'file']],
            'outside' => $responses[$directory] = [['path' => '.another/a.md', 'type' => 'file']],
            'missing-deletion' => $responses[$directory] = [['path' => '.changelog/a.md', 'type' => 'file']],
        };
        $allowed = 'missing-directory' === $case;
        self::assertSame(
            $allowed,
            $this->policy($responses, [], $data)->inspectHead($this->options(), str_repeat('a', 40)),
        );
    }

    /** A fragment directory's durable instruction file and unrelated non-Markdown data are never consumed. */
    #[Test]
    public function ignoresInstructionsAndNonFragmentEntriesInSourceInventory(): void
    {
        [$responses, $data] = $this->managed();
        $responses['/repos/owner/project/contents/.changelog?ref=' . str_repeat('b', 40)] = [
            ['path' => '.changelog/AGENTS.md', 'type' => 'file'], ['path' => '.changelog/template.php', 'type' => 'file'],
        ];
        self::assertTrue($this->policy($responses, [], $data)->inspectHead($this->options(), str_repeat('a', 40)));
    }

    private function options(): ReleaseOptions
    {
        return new ReleaseOptions('/consumer', repository: 'owner/project');
    }

    private function policy(
        array $responses,
        array|RuntimeException $timeline = [],
        array|RuntimeException $data = [],
    ): PullRequestPolicy {
        $commitPath = '/repos/owner/project/commits/' . str_repeat('a', 40);
        if ($data instanceof RuntimeException) {
            $responses[$commitPath] = $data;
        } elseif ([] !== $data && is_array($responses[$commitPath] ?? null)) {
            $message = $responses[$commitPath]['commit']['message'] ?? '';
            if (is_string($message) && str_contains($message, 'Changelog-Plan: ' . str_repeat('c', 64))) {
                $selected = new ReleaseOptions(
                    '/consumer',
                    fragmentDirectory: $data['fragment_directory'],
                    changelogFile: $data['changelog_file'],
                    locale: $data['locale'],
                    template: $data['template'],
                    tagPrefix: $data['tag_prefix'],
                    repository: $data['repository'],
                );
                $responses[$commitPath]['commit']['message'] = 'release' . "\n\nChangelog-Plan: " . $data['id']
                    . "\nChangelog-Base: " . ($data['base_sha'] ?? '') . "\nChangelog-Output: " . $data['after_changelog_sha256']
                    . "\nChangelog-Options: " . $selected->evidenceHash();
            }
            $directory = '/repos/owner/project/contents/.changelog?ref=' . ($data['base_sha'] ?? '');
            if (! array_key_exists($directory, $responses)) {
                $responses[$directory] = array_map(
                    static fn(string $path): array => ['path' => $path, 'type' => 'file'],
                    array_keys($data['consumed']),
                );
            }
        }
        $github = $this->createStub(GitHubClientInterface::class);
        $github->method('request')->willReturnCallback(function (string $method, string $path, ?array $body = null) use (
            $responses
        ): ?array {
            $this->calls[] = [$method, $path, $body];
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
        $results = $this->createStub(PullRequestAuthorizationFactoryInterface::class);
        $results->method('create')->willReturnCallback(
            static fn(bool $waiver, bool $central, string $kind, array $diagnostics, ?string $head = null): PullRequestAuthorization => new PullRequestAuthorization(
                $waiver,
                $central,
                $kind,
                $diagnostics,
                $head,
            ),
        );

        return new PullRequestPolicy($github, $results);
    }

    /** Models GitHub's signed App/Bot commits with its canonical web-flow committer. */
    private function platformManaged(): array
    {
        [$responses, $data] = $this->managed();
        $platform = $this->account('web-flow', 'User', 19864447);
        $responses['/users/web-flow'] = $platform;
        $responses['/repos/owner/project/commits/' . str_repeat('a', 40)]['committer'] = $platform;
        $responses['/graphql'] = ['data' => ['repository' => ['object' => ['oid' => str_repeat(
            'a',
            40,
        ), 'signature' => ['isValid' => true, 'state' => 'VALID', 'wasSignedByGitHub' => true]]]]];

        return [$responses, $data];
    }

    private function managed(): array
    {
        $pr = $this->pr('github-actions[bot]', 'Bot', 'changelog/version');
        $account = $this->account('github-actions[bot]', 'Bot', 101);
        $id = str_repeat('c', 64);
        $data = ['id' => $id, 'repository' => 'owner/project', 'changelog_file' => 'CHANGELOG.md', 'fragment_directory' => '.changelog', 'locale' => 'en', 'template' => 'keep-a-changelog', 'tag_prefix' => 'v', 'base_sha' => str_repeat(
            'b',
            40,
        ), 'consumed' => [], 'before_changelog_sha256' => hash(
            'sha256',
            'old history',
        ), 'changelog_contents' => 'history', 'after_changelog_sha256' => hash(
            'sha256',
            'history',
        )];
        $sha = str_repeat('a', 40);

        return [[
            '/repos/owner/project/pulls/7' => $pr, '/users/github-actions%5Bbot%5D' => $account,
            '/repos/owner/project/commits/' . $sha => ['sha' => $sha, 'author' => $account, 'committer' => $account, 'commit' => ['message' => "release\n\nChangelog-Plan: " . $id, 'verification' => ['verified' => true, 'reason' => 'valid']]],
            '/repos/owner/project/contents/.changelog/release-plan.json?ref=' . $sha => $this->file(
                'validated receipt',
            ),
            '/repos/owner/project/contents/CHANGELOG.md?ref=' . $sha => $this->file('history'),
            '/repos/owner/project/contents/CHANGELOG.md?ref=' . str_repeat('b', 40) => $this->file('old history'),
            '/repos/owner/project/compare/' . str_repeat(
                'b',
                40,
            ) . '...' . $sha => ['merge_base_commit' => ['sha' => str_repeat(
                'b',
                40,
            )], 'status' => 'ahead', 'files' => [['filename' => 'CHANGELOG.md', 'status' => 'modified']]],
        ], $data];
    }
}
