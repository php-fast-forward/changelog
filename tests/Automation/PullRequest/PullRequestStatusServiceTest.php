<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation\PullRequest;

use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Automation\Policy\PullRequestAuthorization;
use FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface;
use FastForward\Changelog\Automation\PullRequest\PullRequestStatusService;
use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Validation\CheckServiceInterface;
use FastForward\Changelog\Validation\ValidationReport;
use FastForward\Changelog\Version\VersionImpact;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(PullRequestStatusService::class)]
#[UsesClass(GitHubEvidence::class)]
#[UsesClass(PullRequestAuthorization::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(ValidationReport::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(Category::class)]
#[UsesClass(VersionImpact::class)]
final class PullRequestStatusServiceTest extends TestCase
{
    /** Feedback counts the PR delta, escapes untrusted text and publishes against the exact head. */
    public function testSuccessIsBoundToTheCommittedSnapshot(): void
    {
        $writes = [];
        $result = $this->inspect([], $writes);
        self::assertSame('valid', $result['status']);
        self::assertSame(1, $result['fragments']);
        self::assertSame(['pending', 'success'], array_column(array_column($writes, 'body'), 'state'));
        self::assertSame('/repos/owner/project/statuses/' . str_repeat('a', 40), $writes[1]['path']);
        self::assertStringContainsString('new', $result['comment_body']);
        self::assertStringNotContainsString('inherited', $result['comment_body']);
        self::assertStringNotContainsString('@everyone', $result['comment_body']);
        self::assertStringNotContainsString('<script>', $result['comment_body']);
        self::assertStringNotContainsString('https://', $result['comment_body']);
        self::assertStringContainsString('minor', $result['comment_body']);
    }

    /** A schema/contribution failure still has feedback and records failure rather than swallowing the gate. */
    public function testFailureHasActionableFeedback(): void
    {
        $writes = [];
        $result = $this->inspect(['errors' => ['@contribution' => ['A new fragment is required.']]], $writes);
        self::assertSame('invalid', $result['status']);
        self::assertStringContainsString('Contribution check failed', $result['comment_body']);
        self::assertStringContainsString('A new fragment is required', $result['comment_body']);
        self::assertStringContainsString('Ordinary contributions', $result['comment_body']);
        self::assertSame(['pending', 'failure'], array_column(array_column($writes, 'body'), 'state'));
    }

    /** Read-only clients receive the same data without any status mutation. */
    public function testReadOnlyModeAndVerifiedWaiverAreReported(): void
    {
        $writes = [];
        $result = $this->inspect(
            ['publish' => false, 'kind' => 'waiver', 'diagnostics' => ['Verified waiver.']],
            $writes,
        );
        self::assertSame([], $writes);
        self::assertSame('waiver', $result['kind']);
        self::assertStringContainsString('Verified waiver', $result['comment_body']);
    }

    /** Bounds previews without losing the actual count or the complete machine diagnostics. */
    public function testLargeContributionsHaveBoundedComments(): void
    {
        $writes = [];
        $result = $this->inspect(['many' => true, 'diagnostics' => array_fill(0, 22, str_repeat('x', 1500))], $writes);
        self::assertSame(22, $result['fragments']);
        self::assertStringContainsString('preview is limited', $result['comment_body']);
        self::assertLessThan(60000, strlen($result['comment_body']));
    }

    /** Byte limits must not split Unicode after HTML expansion or require optional mbstring functions. */
    public function testExpandedUnicodeAtTheByteBoundaryRemainsJsonSerializable(): void
    {
        $writes = [];
        $result = $this->inspect(['description' => str_repeat('&', 159) . 'abc' . "\u{1F680}"], $writes);
        self::assertSame(1, preg_match('//u', $result['comment_body']));
        self::assertStringContainsString('abc', $result['comment_body']);
        self::assertIsString(json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** HTML expansion remains bounded and Markdown escaping never makes entities visible as literal noise. */
    public function testCommentEscapingRetainsReadablePunctuationAndBoundsExpandedText(): void
    {
        $writes = [];
        $result = $this->inspect(['description' => "Developer's file.md > value & <script>"], $writes);
        self::assertStringNotContainsString('&\\#', $result['comment_body']);
        self::assertStringNotContainsString('\\&gt;', $result['comment_body']);
        self::assertStringContainsString(
            'Developer&#039;s file&#46;md &gt; value &amp; &lt;script&gt;',
            $result['comment_body'],
        );
        $result = $this->inspect(['many' => true, 'description' => str_repeat('"', 1500),
            'diagnostics' => array_fill(0, 22, str_repeat('"', 1500))], $writes);
        self::assertLessThan(60000, strlen($result['comment_body']));
    }

    /** Unsafe execution contexts fail before any status write. */
    #[DataProvider('unsafeContexts')]
    public function testUnsafeContextsDoNotPublish(array $settings): void
    {
        $writes = [];
        try {
            $this->inspect($settings, $writes);
            self::fail('An unsafe context must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('exact head', $error->getMessage());
            self::assertSame([], $writes);
        }
    }

    /** Supplies independent malformed identities and executable-template requests. */
    public static function unsafeContexts(): array
    {
        return [[['repository' => 'wrong']], [['number' => 0]], [['head' => 'short']], [['base' => 'short']],
            [['branch' => '']], [['template' => 'custom.php']], [['base_ref' => 'main']],
            [['root' => '/parent']], [['checkout' => str_repeat('d', 40)]]];
    }

    /** Live identity mismatch and later movement never produce a final green result. */
    #[DataProvider('races')]
    public function testLiveSnapshotRacesFailClosed(array $settings, int $pendingWrites): void
    {
        $writes = [];
        try {
            $this->inspect($settings, $writes);
            self::fail('A changed PR must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('snapshot is stale', $error->getMessage());
            self::assertCount($pendingWrites, $writes);
        }
    }

    /** Includes a fork, closed PR, wrong base, wrong line and a race after validation. */
    public static function races(): array
    {
        return [[['pr' => null], 0], [['pr_state' => 'closed'], 0], [['pr_head' => str_repeat('c', 40)], 0],
            [['pr_base' => str_repeat('c', 40)], 0], [['pr_branch' => 'stable'], 0], [['race' => true], 1]];
    }

    /** Policy and committed-data uncertainty leave a blocking pending result instead of overwriting it with success. */
    #[DataProvider('uncertainEvidence')]
    public function testUncertainEvidenceDoesNotPublishSuccess(array $settings, string $message): void
    {
        $writes = [];
        try {
            $this->inspect($settings, $writes);
            self::fail('Unavailable evidence must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
            self::assertSame(['pending'], array_column(array_column($writes, 'body'), 'state'));
        }
    }

    /** Supplies authority, symlink, missing blob, edited-byte and API-confirmation failures. */
    public static function uncertainEvidence(): array
    {
        return [[['authorization' => str_repeat('c', 40)], 'authorization'],
            [['mode' => '120000'], 'committed regular blobs'], [['blob' => null], 'committed regular blobs'],
            [['hash' => 'edited'], 'committed regular blobs'], [['unconfirmed' => true], 'did not confirm']];
    }

    /** A fork is valid read-only input and copy/rename deltas count only new destinations. */
    public function testForkAndRenameInputsRetainContributionSemantics(): void
    {
        $writes = [];
        $result = $this->inspect(['fork' => true, 'rename' => true, 'mode' => '100755'], $writes);
        self::assertSame('valid', $result['status']);
        self::assertSame(1, $result['fragments']);
    }

    /** Builds only injected doubles; no filesystem, network, process or real environment is consulted. */
    private function inspect(array $settings, array &$writes): array
    {
        $head = str_repeat('a', 40);
        $base = str_repeat('b', 40);
        $reads = 0;
        $github = $this->createStub(GitHubClientInterface::class);
        $github->method('request')->willReturnCallback(
            static function (string $method, string $path, ?array $body = null) use (
                $settings,
                &$writes,
                &$reads,
                $head,
                $base
            ): ?array {
                if ('POST' === $method) {
                    $writes[] = compact('path', 'body');

                    return isset($settings['unconfirmed']) ? null : $body;
                }
                ++$reads;
                if (array_key_exists('pr', $settings)) {
                    return $settings['pr'];
                }

                return ['number' => 7, 'state' => $settings['pr_state'] ?? 'open',
                    'head' => ['sha' => ($settings['race'] ?? false) && $reads > 1 ? str_repeat(
                        'c',
                        40,
                    ) : ($settings['pr_head'] ?? $head),
                        'ref' => 'feature', 'repo' => ['id' => isset($settings['fork']) ? 2 : 1, 'full_name' => 'owner/project']],
                    'base' => ['sha' => $settings['pr_base'] ?? $base, 'ref' => $settings['pr_branch'] ?? 'main',
                        'repo' => ['id' => 1, 'full_name' => 'owner/project']]];
            },
        );
        $git = $this->createStub(GitRepositoryInterface::class);
        $git->method('repositoryRoot')->willReturn($settings['root'] ?? '/consumer');
        $git->method('resolveRef')->willReturn($settings['checkout'] ?? $head);
        $git->method('filesAt')->willReturn([['path' => '.changelog/AGENTS.md', 'mode' => '100644'],
            ['path' => '.changelog/README.txt', 'mode' => '100644'],
            ['path' => '.changelog/new.md', 'mode' => $settings['mode'] ?? '100644']]);
        $git->method('readFileAt')->willReturn(array_key_exists('blob', $settings) ? $settings['blob'] : 'accepted');
        $changes = [['status' => 'A', 'path' => '.changelog/new.md', 'previous' => null],
            ['status' => 'M', 'path' => '.changelog/inherited.md', 'previous' => null],
            ['status' => 'R100', 'path' => '.changelog/old.md', 'previous' => '.changelog/older.md']];
        if (isset($settings['rename'])) {
            $changes[0] = ['status' => 'R100', 'path' => '.changelog/new.md', 'previous' => 'outside.md'];
        }
        $fragments = [
            new Changeset(
                'new.md',
                Category::Added,
                null,
                null,
                null,
                $settings['description'] ?? '<script>@everyone https://example.invalid</script>',
            ),
            new Changeset('inherited.md', Category::Fixed, null, null, null, 'Existing note.'),
        ];
        if (isset($settings['many'])) {
            for ($index = 1; $index <= 21; ++$index) {
                $changes[] = ['status' => 'C100', 'path' => '.changelog/new-' . $index . '.md', 'previous' => null];
                $fragments[] = new Changeset('new-' . $index . '.md', Category::Fixed, null, null, null, str_repeat(
                    'x',
                    1500,
                ));
            }
        }
        $git->method('changesSince')->willReturn($changes);
        $policy = $this->createStub(PullRequestPolicyInterface::class);
        $policy->method('inspect')->willReturn(
            new PullRequestAuthorization(false, false, $settings['kind'] ?? 'ordinary',
                $settings['diagnostics'] ?? [], $settings['authorization'] ?? $head),
        );
        $checks = $this->createStub(CheckServiceInterface::class);
        $checks->method('check')->willReturn(new ValidationReport($fragments, $settings['errors'] ?? [], false,
            ['/consumer/.changelog/new.md' => $settings['hash'] ?? hash('sha256', 'accepted')]));

        return new PullRequestStatusService($github, $git, $policy, $checks)->inspect(
            new ReleaseOptions('/consumer', template: $settings['template'] ?? 'keep-a-changelog',
                baseRef: $settings['base_ref'] ?? 'HEAD', repository: $settings['repository'] ?? 'owner/project'),
            $settings['number'] ?? 7, $settings['head'] ?? $head, $settings['base'] ?? $base,
            $settings['branch'] ?? 'main', 'changelog/version', 'github-actions[bot]',
            'changelog-not-required', 'changelog-maintenance', $settings['publish'] ?? true,
        );
    }
}
