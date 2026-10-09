<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation\Dependabot;

use FastForward\Changelog\Automation\Dependabot\DependabotFragmentResult;
use FastForward\Changelog\Automation\Dependabot\DependabotFragmentService;
use FastForward\Changelog\Automation\Dependabot\DependabotInput;
use FastForward\Changelog\Automation\Dependabot\Factory\DependabotFragmentResultFactoryInterface;
use FastForward\Changelog\Automation\Dependabot\Factory\DependabotInputFactoryInterface;
use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\Factory\ChangesetFactoryInterface;
use FastForward\Changelog\Changeset\Renderer\ChangesetRendererInterface;
use FastForward\Changelog\Changeset\VersionImpact;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Tests\Automation\Policy\PolicyFixtureTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DependabotFragmentService::class)]
#[UsesClass(GitHubEvidence::class)]
#[UsesClass(DependabotInput::class)]
#[UsesClass(DependabotFragmentResult::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(Changeset::class)]
final class DependabotFragmentServiceTest extends TestCase
{
    use PolicyFixtureTrait;

    private array $calls = [];
    private array $changes = [];

    #[Test]
    public function createsOneChangedPatchUsingCoreFactoriesAndCreateOnlyContentsApi(): void
    {
        $input = $this->input();
        $result = $this->service($input)->synchronize($this->options(), $input);
        self::assertSame('created', $result->status);
        self::assertSame('.changelog/dependabot-7.md', $result->path);
        self::assertSame(str_repeat('c', 40), $result->commitSha);
        self::assertSame(
            [
                'dependabot-7.md',
                Category::Changed,
                null,
                7,
                'dependabot[bot]',
                'Update dependencies: `package/name`.',
                VersionImpact::Patch,
            ],
            $this->changes,
        );
        $writes = $this->writes();
        self::assertCount(1, $writes);
        self::assertSame('/repos/owner/project/contents/.changelog/dependabot-7.md', $writes[0][1]);
        self::assertSame([
            'message' => 'chore(changelog): add Dependabot fragment for #7', 'content' => base64_encode(
                'generated',
            ), 'branch' => 'dependabot/composer/package-1'],
            $writes[0][2],
        );
        self::assertArrayNotHasKey('sha', $writes[0][2]);
        self::assertCount(
            2,
            array_filter($this->calls, static fn(array $call): bool => '/repos/owner/project/pulls/7' === $call[1]),
        );
    }

    #[Test]
    public function identicalExistingContentIsIdempotentWithoutAnotherCommit(): void
    {
        $input = $this->input(type: 'direct:development', ecosystem: 'github-actions');
        $result = $this->service($input, existing: $this->file('generated'))->synchronize(
            $this->options('pt-BR'),
            $input,
        );
        self::assertSame('unchanged', $result->status);
        self::assertNull($result->commitSha);
        self::assertSame('Atualizar dependências: `package/name`.', $this->changes[5]);
        self::assertSame([], $this->writes());
    }

    #[Test]
    #[TestWith([['type' => 'file', 'encoding' => 'base64', 'content' => 'bWFudWFs']])]
    #[TestWith([['type' => 'dir']])]
    #[TestWith([['type' => 'file', 'encoding' => 'base64', 'content' => '!']])]
    public function unexpectedExistingFileIsPreservedEvenIfItClaimsGeneratedProvenance(array $existing): void
    {
        $input = $this->input();
        $result = $this->service($input, existing: $existing)->synchronize($this->options(), $input);
        self::assertSame('refused', $result->status);
        self::assertNotEmpty($result->diagnostics);
        self::assertSame([], $this->writes());
    }

    #[Test]
    #[TestWith(['direct:development', 'composer', false, true, 'filtered'])]
    #[TestWith(['direct:production', 'github-actions', true, false, 'filtered'])]
    #[TestWith(['indirect', 'composer', false, true, 'refused'])]
    public function explicitFiltersApplyWithoutReadingOrExecutingHead(
        string $type,
        string $ecosystem,
        bool $dev,
        bool $actions,
        string $status,
    ): void {
        $input = $this->input($type, $ecosystem, dev: $dev, actions: $actions);
        $result = $this->service($input)->synchronize($this->options(), $input);
        self::assertSame($status, $result->status);
        self::assertSame([], $this->calls);
        self::assertSame([], $this->changes);
    }

    #[Test]
    #[TestWith([null, '.changelog'])]
    #[TestWith(['owner/project', '/changes'])]
    #[TestWith(['owner/project', '.'])]
    #[TestWith(['owner/project', '.changelog/../outside'])]
    public function privilegedWritesAreConfinedToValidatedRepositoryAndChangelogPath(
        ?string $repository,
        string $path,
    ): void {
        $input = $this->input();
        $result = $this->service($input)->synchronize(
            new ReleaseOptions('/consumer', fragmentDirectory: $path, repository: $repository),
            $input,
        );
        self::assertSame('refused', $result->status);
        self::assertSame([], $this->calls);
    }

    /** Configured monorepo directories are repository-relative, not silently redirected to the root. */
    public function testNestedConfiguredFragmentDirectoryKeepsTheRepositoryRelativePath(): void
    {
        $input = $this->input();
        $result = $this->service($input)->synchronize(
            new ReleaseOptions('/consumer', fragmentDirectory: 'packages/lib/.changelog', repository: 'owner/project'),
            $input,
        );
        self::assertSame('created', $result->status);
        self::assertSame('packages/lib/.changelog/dependabot-7.md', $result->path);
        self::assertStringContainsString(
            '/contents/packages/lib/.changelog/dependabot-7.md',
            array_last($this->calls)[1],
        );
    }

    #[Test]
    public function inputValidationFailureCannotReachGitHub(): void
    {
        $input = $this->input();
        $result = $this->service($input, inputFailure: true)->synchronize($this->options(), $input);
        self::assertSame('refused', $result->status);
        self::assertSame([], $this->calls);
        self::assertStringNotContainsString('super-secret', implode(' ', $result->diagnostics));
    }

    #[Test]
    #[TestWith(['user'])]
    #[TestWith(['fork'])]
    #[TestWith(['stale'])]
    #[TestWith(['base-branch'])]
    #[TestWith(['closed'])]
    #[TestWith(['missing'])]
    public function privilegedWriteRequiresActualBotSameRepositoryAndExactHead(string $case): void
    {
        $input = $this->input();
        $pr = $this->pr('dependabot[bot]', 'Bot', 'dependabot/composer/package-1');
        match ($case) {
            'user' => $pr['user']['type'] = 'User',
            'fork' => $pr['head']['repo'] = ['id' => 2, 'full_name' => 'fork/project'],
            'stale' => $pr['head']['sha'] = str_repeat('d', 40),
            'base-branch' => $pr['head']['ref'] = 'main',
            'closed' => $pr['state'] = 'closed',
            'missing' => $pr = null,
        };
        $result = $this->service($input, prs: [$pr])->synchronize($this->options(), $input);
        self::assertSame('refused', $result->status);
        self::assertSame([], $this->writes());
    }

    #[Test]
    public function accountIdentityLookupCannotBeReplacedByMatchingLogin(): void
    {
        $input = $this->input();
        $result = $this->service($input, account: $this->account('dependabot[bot]', 'Bot', 666))->synchronize(
            $this->options(),
            $input,
        );
        self::assertSame('refused', $result->status);
        self::assertSame([], $this->writes());
    }

    #[Test]
    #[TestWith(['composer', 'composer'])]
    #[TestWith(['github-actions', 'actions'])]
    public function verifiedCurrentOpenSecurityAlertsGenerateSecurityPatch(
        string $ecosystem,
        string $apiEcosystem,
    ): void {
        $input = $this->input(ecosystem: $ecosystem, alerts: [9]);
        $alert = ['number' => 9, 'state' => 'open', 'dependency' => ['package' => ['ecosystem' => $apiEcosystem, 'name' => 'package/name']], 'security_advisory' => ['ghsa_id' => 'GHSA-abcd-1234-efgh']];
        $result = $this->service($input, alert: $alert)->synchronize($this->options(), $input);
        self::assertSame('created', $result->status);
        self::assertSame(Category::Security, $this->changes[1]);
        self::assertSame(VersionImpact::Patch, $this->changes[6]);
    }

    #[Test]
    #[TestWith(['closed'])]
    #[TestWith(['dismissed'])]
    #[TestWith(['fixed'])]
    #[TestWith(['package'])]
    #[TestWith(['ecosystem'])]
    #[TestWith(['advisory'])]
    #[TestWith(['number'])]
    #[TestWith(['missing'])]
    public function staleMismatchedOrUnreadableSecurityMetadataFailsClosed(string $case): void
    {
        $input = $this->input(alerts: [9]);
        $alert = ['number' => 9, 'state' => 'open', 'dependency' => ['package' => ['ecosystem' => 'composer', 'name' => 'package/name']], 'security_advisory' => ['ghsa_id' => 'GHSA-abcd-1234-efgh']];
        match ($case) {
            'closed', 'fixed', 'dismissed' => $alert['state'] = $case,
            'package' => $alert['dependency']['package']['name'] = 'other/package',
            'ecosystem' => $alert['dependency']['package']['ecosystem'] = 'npm',
            'advisory' => $alert['security_advisory']['ghsa_id'] = 'forged',
            'number' => $alert['number'] = 10,
            'missing' => $alert = null,
        };
        $result = $this->service($input, alert: $alert)->synchronize($this->options(), $input);
        self::assertSame('refused', $result->status);
        self::assertSame([], $this->changes);
        self::assertSame([], $this->writes());
    }

    #[Test]
    #[TestWith(['head'])]
    #[TestWith(['branch'])]
    #[TestWith(['creator'])]
    public function rechecksSnapshotBeforeCreationWithoutOverwritingOnRace(string $case): void
    {
        $input = $this->input();
        $pr = $this->pr('dependabot[bot]', 'Bot', 'dependabot/composer/package-1');
        $fresh = $pr;
        match ($case) {
            'head' => $fresh['head']['sha'] = str_repeat('d', 40),
            'branch' => $fresh['head']['ref'] = 'other/branch',
            'creator' => $fresh['user']['id'] = 666,
        };
        $result = $this->service($input, prs: [$pr, $fresh])->synchronize($this->options(), $input);
        self::assertSame('conflict', $result->status);
        self::assertNull($result->commitSha);
        self::assertSame([], $this->writes());
    }

    #[Test]
    #[TestWith(['unconfirmed'])]
    #[TestWith(['changed-parent'])]
    #[TestWith(['two-parents'])]
    #[TestWith(['transport'])]
    public function reportsUncertainOrChangedHeadMutationTruthfully(string $case): void
    {
        $input = $this->input();
        $response = ['commit' => ['sha' => str_repeat('c', 40), 'parents' => [['sha' => str_repeat('a', 40)]]]];
        match ($case) {
            'unconfirmed' => $response = null,
            'changed-parent' => $response['commit']['parents'][0]['sha'] = str_repeat('d', 40),
            'two-parents' => $response['commit']['parents'][] = ['sha' => str_repeat('e', 40)],
            'transport' => $response = new RuntimeException('super-secret'),
        };
        $result = $this->service($input, write: $response)->synchronize($this->options(), $input);
        self::assertSame('conflict', $result->status);
        self::assertCount(1, $this->writes());
        self::assertStringNotContainsString('super-secret', implode(' ', $result->diagnostics));
        self::assertSame(
            in_array($case, ['changed-parent', 'two-parents'], true) ? str_repeat('c', 40) : null,
            $result->commitSha,
        );
    }

    #[Test]
    public function networkPermissionFailureBeforeCreationCannotBecomeOrdinarySecurityFallback(): void
    {
        $input = $this->input(alerts: [9]);
        $result = $this->service($input, alert: new RuntimeException('super-secret'))->synchronize(
            $this->options(),
            $input,
        );
        self::assertSame('refused', $result->status);
        self::assertSame([], $this->writes());
        self::assertStringNotContainsString('super-secret', implode(' ', $result->diagnostics));
    }

    private function input(
        string $type = 'direct:production',
        string $ecosystem = 'composer',
        array $alerts = [],
        bool $dev = true,
        bool $actions = true,
    ): DependabotInput {
        return new DependabotInput(7, str_repeat('a', 40), [
            'package/name',
        ], $type, $ecosystem, $alerts, $dev, $actions);
    }

    private function options(string $locale = 'en'): ReleaseOptions
    {
        return new ReleaseOptions('/consumer', locale: $locale, repository: 'owner/project');
    }

    private function writes(): array
    {
        return array_values(array_filter($this->calls, static fn(array $call): bool => 'PUT' === $call[0]));
    }

    private function service(
        DependabotInput $input,
        ?array $existing = null,
        ?array $prs = null,
        ?array $account = null,
        array|RuntimeException|null $alert = null,
        array|RuntimeException|null $write = ['commit' => ['sha' => 'cccccccccccccccccccccccccccccccccccccccc', 'parents' => [['sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']]]],
        bool $inputFailure = false,
    ): DependabotFragmentService {
        $prs ??= [
            $this->pr('dependabot[bot]', 'Bot', 'dependabot/composer/package-1'),
            $this->pr('dependabot[bot]', 'Bot', 'dependabot/composer/package-1'),
        ];
        $account ??= $this->account('dependabot[bot]', 'Bot', 101);
        $github = $this->createStub(GitHubClientInterface::class);
        $github->method('request')->willReturnCallback(function (string $method, string $path, ?array $body = null) use (
            &$prs,
            $account,
            $existing,
            $alert,
            $write
        ): ?array {
            $this->calls[] = [$method, $path, $body];
            if ('PUT' === $method) {
                $value = $write;
            } elseif ('/repos/owner/project/pulls/7' === $path) {
                $value = array_shift($prs);
            } elseif ('/users/dependabot%5Bbot%5D' === $path) {
                $value = $account;
            } elseif (str_contains($path, '/dependabot/alerts/')) {
                $value = $alert;
            } else {
                $value = $existing;
            }
            if ($value instanceof RuntimeException) {
                throw $value;
            }

            return $value;
        });
        $changesets = $this->createStub(ChangesetFactoryInterface::class);
        $changesets->method('create')->willReturnCallback(
            function (
                string $id,
                Category $category,
                ?int $issue,
                ?int $pr,
                ?string $author,
                string $description,
                ?VersionImpact $type,
            ): Changeset {
                $this->changes = [$id, $category, $issue, $pr, $author, $description, $type];

                return new Changeset($id, $category, $issue, $pr, $author, $description, $type);
            },
        );
        $renderer = $this->createStub(ChangesetRendererInterface::class);
        $renderer->method('render')->willReturn('generated');
        $results = $this->createStub(DependabotFragmentResultFactoryInterface::class);
        $results->method('create')->willReturnCallback(
            static fn(string $status, string $path, ?string $commit = null, array $diagnostics = []): DependabotFragmentResult => new DependabotFragmentResult(
                $status,
                $path,
                $commit,
                $diagnostics,
            ),
        );
        $inputs = $this->createMock(DependabotInputFactoryInterface::class);
        $validation = $inputs->expects(self::once())->method('create')->with(
            $input->pullRequest,
            $input->expectedHeadSha,
            $input->packageNames,
            $input->dependencyType,
            $input->ecosystem,
            $input->securityAlertNumbers,
            $input->includeDev,
            $input->includeActions,
        );
        if ($inputFailure) {
            $validation->willThrowException(new RuntimeException('super-secret'));
        } else {
            $validation->willReturn($input);
        }

        return new DependabotFragmentService($github, $changesets, $renderer, $results, $inputs);
    }
}
