<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation\Dependabot;

use FastForward\Changelog\Automation\Dependabot\DependabotFragmentResult;
use FastForward\Changelog\Automation\Dependabot\DependabotFragmentService;
use FastForward\Changelog\Automation\Dependabot\DependabotInput;
use FastForward\Changelog\Automation\Dependabot\Factory\DependabotInputFactoryInterface;
use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\Renderer\ChangesetRenderer;
use FastForward\Changelog\Changeset\Renderer\ChangesetRendererInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Tests\Automation\Policy\PolicyFixtureTrait;
use FastForward\Changelog\Version\VersionImpact;
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
#[UsesClass(ChangesetRenderer::class)]
final class DependabotFragmentServiceTest extends TestCase
{
    use PolicyFixtureTrait;

    private const string LEGACY_FRAGMENT = <<<'MARKDOWN'
    ---
    category: changed
    type: patch
    pull_request: 7
    author: "dependabot[bot]"
    ---

    Update dependencies: `package/name`.

    MARKDOWN;

    private array $calls = [];
    private array $changes = [];
    private array $descriptions = [];

    #[Test]
    public function createsOneChangedPatchUsingCanonicalRenderingAndCreateOnlyContentsApi(): void
    {
        $input = $this->input();
        $result = $this->service($input)->synchronize($this->options(), $input);
        self::assertSame('created', $result->status, implode(' ', $result->diagnostics));
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
                self::LEGACY_FRAGMENT,
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
        $result = $this->service($input, existing: $this->file(str_replace(
            'Update dependencies:',
            'Atualizar dependências:',
            self::LEGACY_FRAGMENT,
        )))->synchronize(
            $this->options('pt-BR'),
            $input,
        );
        self::assertSame('unchanged', $result->status);
        self::assertNull($result->commitSha);
        self::assertSame('Atualizar dependências: `package/name`.', $this->changes[5]);
        self::assertSame([], $this->writes());
    }

    /** Retains the complete verified PR title, including versions and directory, as useful release text. */
    #[Test]
    public function usesThePullRequestTitleWithVersionsAndDirectoryWithoutInventingMetadata(): void
    {
        $input = new DependabotInput(7, str_repeat('a', 40), [
            'shivammathur/setup-php',
        ], 'direct:production', 'github-actions');
        $pr = $this->pr('dependabot[bot]', 'Bot', 'dependabot/github_actions/setup-php');
        $pr['title'] = 'build(deps): bump shivammathur/setup-php from 2.36.0 to 2.37.1 in /.github/workflows';

        $result = $this->service($input, prs: [$pr, $pr])->synchronize($this->options(), $input);

        self::assertSame('created', $result->status);
        self::assertSame(
            $pr['title'] . "\n\nUpdate dependencies: `shivammathur/setup-php`.",
            $this->changes[5],
        );
        self::assertSame(Category::Changed, $this->changes[1]);
        self::assertSame(VersionImpact::Patch, $this->changes[6]);
        self::assertSame('.changelog/dependabot-7.md', $result->path);
    }

    /** Groups retain the collected package list even when their title does not contain individual versions. */
    #[Test]
    public function describesGroupedPackagesUsingOnlyTheTrustedMetadata(): void
    {
        $input = new DependabotInput(7, str_repeat('a', 40), [
            'vendor/one',
            'vendor/two',
        ], 'direct:production', 'composer');
        $pr = $this->pr('dependabot[bot]', 'Bot', 'dependabot/composer/production');
        $pr['title'] = 'build(deps): bump the production group with 2 updates in /apps/api';

        $result = $this->service($input, prs: [$pr, $pr])->synchronize($this->options(), $input);

        self::assertSame('created', $result->status);
        self::assertSame(
            $pr['title'] . "\n\nUpdate dependencies: `vendor/one`, `vendor/two`.",
            $this->changes[5],
        );
    }

    /** Untrusted title formatting cannot create Markdown blocks, HTML, mentions or external autolinks. */
    #[Test]
    #[TestWith([
        "# release\n\n- [click](javascript:evil) <img src=x> @team **major** `code` ~~gone~~",
        '\# release - \[click\](javascript:evil) &lt;img src=x&gt; &#64;team \*\*major\*\* \`code\` \~\~gone\~\~',
    ])]
    #[TestWith([
        "\t build(deps):  bump\r\npackage\t2.0.0\u{2028}\u{00a0}/app\u{2029}",
        'build(deps): bump package 2.0.0 /app',
    ])]
    #[TestWith(['1. update', '1\. update'])]
    #[TestWith(['1) update', '1\) update'])]
    #[TestWith(['- update', '\- update'])]
    #[TestWith(['+ update', '\+ update'])]
    #[TestWith(['---', '\---'])]
    #[TestWith(['> quote', '&gt; quote'])]
    #[TestWith([
        'Update https://example.test and www.example.test @bot & <x>',
        'Update https&#58;//example.test and www&#46;example.test &#64;bot &amp; &lt;x&gt;',
    ])]
    #[TestWith(['literal &lt;tag&gt; and $math$', 'literal &amp;lt;tag&amp;gt; and \$math\$'])]
    public function rendersTitlesAsOneLiteralParagraph(string $title, string $expected): void
    {
        $input = $this->input();
        $pr = $this->pr('dependabot[bot]', 'Bot', 'dependabot/composer/package-1');
        $pr['title'] = $title;

        $result = $this->service($input, prs: [$pr, $pr])->synchronize($this->options(), $input);

        self::assertSame('created', $result->status);
        self::assertSame($expected . "\n\nUpdate dependencies: `package/name`.", $this->changes[5]);
    }

    /** Missing, malformed or unbounded titles retain the localized package-based description. */
    #[Test]
    #[TestWith([null, 'en', 'Update dependencies: `package/name`.'])]
    #[TestWith([123, 'pt-BR', 'Atualizar dependências: `package/name`.'])]
    #[TestWith([[], 'en', 'Update dependencies: `package/name`.'])]
    #[TestWith(['', 'pt-BR', 'Atualizar dependências: `package/name`.'])]
    #[TestWith(["\0 \r\n\t", 'en', 'Update dependencies: `package/name`.'])]
    #[TestWith(["\xff", 'pt-BR', 'Atualizar dependências: `package/name`.'])]
    public function unusableTitlesFallBackWithoutLosingPackageNames(
        mixed $title,
        string $locale,
        string $expected,
    ): void {
        $input = $this->input();
        $pr = $this->pr('dependabot[bot]', 'Bot', 'dependabot/composer/package-1');
        $pr['title'] = $title;

        $result = $this->service($input, prs: [$pr, $pr])->synchronize($this->options($locale), $input);

        self::assertSame('created', $result->status);
        self::assertSame($expected, $this->changes[5]);
    }

    /** A title exceeding the bounded display budget cannot inject an unbounded generated description. */
    #[Test]
    public function oversizedTitlesUseTheLocalizedFallback(): void
    {
        $input = $this->input();
        $pr = $this->pr('dependabot[bot]', 'Bot', 'dependabot/composer/package-1');
        $pr['title'] = str_repeat('x', 1025);

        $result = $this->service($input, prs: [$pr, $pr])->synchronize($this->options('pt-BR'), $input);

        self::assertSame('created', $result->status);
        self::assertSame('Atualizar dependências: `package/name`.', $this->changes[5]);
    }

    /** Titles are display text, and cannot grant security classification, version impact or paths. */
    #[Test]
    public function aSecurityOrBreakingTitleCannotChangeTrustedReleaseMetadata(): void
    {
        $input = $this->input();
        $pr = $this->pr('dependabot[bot]', 'Bot', 'dependabot/composer/package-1');
        $pr['title'] = 'security: major breaking update ../outside';

        $result = $this->service($input, prs: [$pr, $pr])->synchronize($this->options(), $input);

        self::assertSame('created', $result->status);
        self::assertSame('.changelog/dependabot-7.md', $result->path);
        self::assertSame(Category::Changed, $this->changes[1]);
        self::assertSame(VersionImpact::Patch, $this->changes[6]);
        self::assertSame(7, $this->changes[3]);
        self::assertSame('dependabot[bot]', $this->changes[4]);
        self::assertCount(
            0,
            array_filter($this->calls, static fn(array $call): bool => str_contains($call[1], '/dependabot/alerts/')),
        );
    }

    /** Identical title and metadata render the same bytes and never create a second commit. */
    #[Test]
    public function anIdenticalDescriptiveFragmentRemainsIdempotent(): void
    {
        $input = $this->input();
        $pr = $this->pr('dependabot[bot]', 'Bot', 'dependabot/composer/package-1');
        $pr['title'] = 'build(deps): bump package/name from 1.0.0 to 1.1.0';
        $existing = str_replace(
            'Update dependencies:',
            $pr['title'] . "\n\nUpdate dependencies:",
            self::LEGACY_FRAGMENT,
        );

        $result = $this->service($input, existing: $this->file($existing), prs: [$pr])->synchronize(
            $this->options(),
            $input,
        );

        self::assertSame('unchanged', $result->status);
        self::assertNull($result->commitSha);
        self::assertSame([], $this->writes());
    }

    /** Only exact deterministic legacy bytes are accepted for backward-compatible reruns, without rewriting. */
    #[Test]
    public function aLegacyFragmentIsRecognizedWithoutReplacingItWithTheNewTitle(): void
    {
        $input = $this->input();
        $pr = $this->pr('dependabot[bot]', 'Bot', 'dependabot/composer/package-1');
        $pr['title'] = 'build(deps): bump package/name from 1.0.0 to 1.1.0';

        $result = $this->service($input, existing: $this->file(self::LEGACY_FRAGMENT), prs: [$pr])->synchronize(
            $this->options(),
            $input,
        );

        self::assertSame('unchanged', $result->status);
        self::assertSame([
            $pr['title'] . "\n\nUpdate dependencies: `package/name`.",
            'Update dependencies: `package/name`.',
        ], $this->descriptions);
        self::assertNull($result->commitSha);
        self::assertSame([], $this->writes());
    }

    /** Retitling a PR must not overwrite an existing generated description with different bytes. */
    #[Test]
    public function aChangedTitlePreservesThePreviouslyCommittedFragment(): void
    {
        $input = $this->input();
        $pr = $this->pr('dependabot[bot]', 'Bot', 'dependabot/composer/package-1');
        $pr['title'] = 'build(deps): bump package/name from 1.0.0 to 1.2.0';
        $existing = str_replace(
            'Update dependencies:',
            "build(deps): bump package/name from 1.0.0 to 1.1.0\n\nUpdate dependencies:",
            self::LEGACY_FRAGMENT,
        );

        $result = $this->service($input, existing: $this->file($existing), prs: [$pr])->synchronize(
            $this->options(),
            $input,
        );

        self::assertSame('refused', $result->status);
        self::assertNotEmpty($result->diagnostics);
        self::assertSame([], $this->writes());
    }

    /** Even a one-byte manual edit or changed identity is not deterministic legacy output. */
    #[Test]
    #[TestWith(['description'])]
    #[TestWith(['identity'])]
    #[TestWith(['whitespace'])]
    public function editedLegacyFragmentsAreNeverAdoptedOrOverwritten(string $case): void
    {
        $input = $this->input();
        $pr = $this->pr('dependabot[bot]', 'Bot', 'dependabot/composer/package-1');
        $pr['title'] = 'build(deps): update package/name';
        $existing = match ($case) {
            'description' => str_replace('Update dependencies:', 'Manual update:', self::LEGACY_FRAGMENT),
            'identity' => str_replace('pull_request: 7', 'pull_request: 8', self::LEGACY_FRAGMENT),
            'whitespace' => self::LEGACY_FRAGMENT . ' ',
        };

        $result = $this->service($input, existing: $this->file($existing), prs: [$pr])->synchronize(
            $this->options(),
            $input,
        );

        self::assertSame('refused', $result->status);
        self::assertSame([], $this->writes());
    }

    /** A deterministic fragment in another locale is not an exact match for this operation's legacy output. */
    #[Test]
    public function aLegacyFragmentFromAnotherLocaleIsPreservedWithoutAdoption(): void
    {
        $input = $this->input();
        $pr = $this->pr('dependabot[bot]', 'Bot', 'dependabot/composer/package-1');
        $pr['title'] = 'build(deps): update package/name';

        $result = $this->service($input, existing: $this->file(self::LEGACY_FRAGMENT), prs: [$pr])->synchronize(
            $this->options('pt-BR'),
            $input,
        );

        self::assertSame('refused', $result->status);
        self::assertSame([], $this->writes());
    }

    /** A retitle racing with creation changes the presentation snapshot even when the commit SHA stays fixed. */
    #[Test]
    public function aTitleEditedBeforeCreationCannotWriteStaleReleaseText(): void
    {
        $input = $this->input();
        $pr = $this->pr('dependabot[bot]', 'Bot', 'dependabot/composer/package-1');
        $pr['title'] = 'build(deps): bump package/name from 1.0.0 to 1.1.0';
        $fresh = $pr;
        $fresh['title'] = 'build(deps): bump package/name from 1.0.0 to 1.2.0';

        $result = $this->service($input, prs: [$pr, $fresh])->synchronize($this->options(), $input);

        self::assertSame('conflict', $result->status);
        self::assertNull($result->commitSha);
        self::assertStringContainsString('snapshot or metadata changed', implode(' ', $result->diagnostics));
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
        $canonicalRenderer = new ChangesetRenderer();
        $renderer = $this->createStub(ChangesetRendererInterface::class);
        $renderer->method('render')->willReturnCallback(function (Changeset $changeset) use (
            $canonicalRenderer
        ): string {
            $this->changes = [
                $changeset->id,
                $changeset->category,
                $changeset->issue,
                $changeset->pullRequest,
                $changeset->author,
                $changeset->description,
                $changeset->type,
            ];
            $this->descriptions[] = $changeset->description;

            return $canonicalRenderer->render($changeset);
        });
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

        return new DependabotFragmentService($github, $renderer, $inputs);
    }
}
