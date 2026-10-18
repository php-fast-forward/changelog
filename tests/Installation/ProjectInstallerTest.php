<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Installation;

use FastForward\Changelog\Installation\InstallationFilesystemInterface;
use FastForward\Changelog\Installation\ProjectInstaller;
use FastForward\Changelog\Installation\WorkflowReleaseResolverInterface;
use FastForward\Changelog\Skill\SkillInstallerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(ProjectInstaller::class)]
final class ProjectInstallerTest extends TestCase
{
    private const string CALLER = <<<'YAML'
name: Changelog
on:
  pull_request:
    branches: [main]
    types: [opened, synchronize]
  pull_request_target:
    branches: [main]
    types: [opened, closed]
  push:
    branches: [main]
  workflow_dispatch:
permissions: {}
jobs:
  changelog:
    permissions:
      contents: write
      pull-requests: write
    uses: php-fast-forward/changelog/.github/workflows/changelog.yml@v0
    with:
      source: tags
      publish: false
YAML;

    /** An absent integration and unrelated CI are safe adoption candidates without release discovery. */
    public function testInspectRecognizesAbsenceAndUnrelatedCi(): void
    {
        $filesystem = $this->createMock(InstallationFilesystemInterface::class);
        $filesystem->expects(self::once())->method('inspect')->with('/project')->willReturn($this->snapshot([
            '.github/workflows/ci.yaml' => "jobs:\n  tests:\n    runs-on: ubuntu-latest\n    steps:\n      - run: composer test\n",
        ]));
        $releases = $this->createMock(WorkflowReleaseResolverInterface::class);
        $releases->expects(self::never())->method('resolve');
        $installer = new ProjectInstaller($filesystem, $releases, $this->createStub(SkillInstallerInterface::class));
        $result = $installer->inspect('/project');

        self::assertSame('absent', $result['state']);
        self::assertSame('unrelated', $result['workflows'][0]['kind']);
        self::assertSame('main', $result['base_branch']);
    }

    /** A custom caller keeps its exact bytes and pin; settings and a requested newer ref do not trigger replacement. */
    public function testExistingCallerSkipsReleaseDiscoveryAndPreservesSettings(): void
    {
        $filesystem = $this->createMock(InstallationFilesystemInterface::class);
        $filesystem->method('inspect')->willReturn($this->snapshot(['.github/workflows/custom.yml' => self::CALLER]));
        $filesystem->expects(self::never())->method('create');
        $releases = $this->createMock(WorkflowReleaseResolverInterface::class);
        $releases->expects(self::never())->method('resolve');
        $skills = $this->createMock(SkillInstallerInterface::class);
        $skills->expects(self::never())->method('run');
        $result = new ProjectInstaller($filesystem, $releases, $skills)->run(
            '/project',
            'v9.0.0',
            ['unknown' => true],
            false,
        );

        self::assertSame('existing', $result['state']);
        self::assertSame('.github/workflows/custom.yml', $result['workflow']['path']);
        self::assertSame('v0', $result['workflow']['reference']);
        self::assertNull($result['workflow']['content']);
    }

    /** Same-version components remain an existing integration while an absent native skill can be installed independently. */
    #[DataProvider('modularIntegrations')]
    public function testExistingModularIntegrationPreservesEveryWorkflowAndCanInstallSkill(array $files): void
    {
        $filesystem = $this->createMock(InstallationFilesystemInterface::class);
        $filesystem->method('inspect')->willReturn($this->snapshot($files));
        $filesystem->expects(self::never())->method('create');
        $filesystem->expects(self::once())->method('read')->with('/project', 'skills-lock.json')->willReturn(null);
        $releases = $this->createMock(WorkflowReleaseResolverInterface::class);
        $releases->expects(self::never())->method('resolve');
        $skills = $this->createMock(SkillInstallerInterface::class);
        $skills->expects(self::exactly(2))->method('run')->willReturnMap([
            ['/project', '.agents/skills', false, 'install', true, ['state' => 'absent']],
            ['/project', '.agents/skills', false, 'install', false, ['state' => 'installed']],
        ]);
        $installer = new ProjectInstaller($filesystem, $releases, $skills);
        $inspection = $installer->inspect('/project');
        self::assertSame('existing', $inspection['state']);
        $result = $installer->run('/project', 'v9.0.0', ['unknown' => true]);

        self::assertSame('existing', $result['state']);
        self::assertSame('installed', $result['skill']['state']);
        self::assertNull($result['workflow']['content']);
        foreach ($inspection['workflows'] as $workflow) {
            if ('changelog' === $workflow['kind']) {
                self::assertStringContainsString($workflow['path'], implode("\n", $result['diagnostics']));
            }
        }
    }

    /** Provides independent reusable modules, individual Actions and a full caller with supporting components. */
    public static function modularIntegrations(): iterable
    {
        $modules = [
            '.github/workflows/check.yml' => "jobs:\n  check:\n    uses: php-fast-forward/changelog/.github/workflows/changelog-check.yml@v0\n",
            '.github/workflows/version.yml' => "jobs:\n  version:\n    uses: php-fast-forward/changelog/.github/workflows/changelog-version.yml@v0\n",
            '.github/workflows/publish.yml' => "jobs:\n  publish:\n    uses: php-fast-forward/changelog/.github/workflows/changelog-publish.yml@v0\n",
            '.github/workflows/ci.yml' => "jobs:\n  tests:\n    steps:\n      - run: composer test\n",
        ];
        yield 'individual reusable workflows' => [$modules];
        yield 'individual Actions' => [array_map(
            static fn(string $yaml): string => str_replace(
                ['    uses:', '/workflows/changelog-', '.yml@v0'],
                ["    steps:\n      - uses:", '/actions/', '@v0'],
                $yaml,
            ),
            array_filter(
                $modules,
                static fn(string $path): bool => ! str_ends_with($path, '/ci.yml'),
                ARRAY_FILTER_USE_KEY,
            ),
        )];
        yield 'full caller with supporting modules' => [['.github/workflows/lifecycle.yml' => self::CALLER, ...$modules]];
    }

    /** Competing, malformed or ambiguous integrations block before network, skill preview and filesystem mutation. */
    #[DataProvider('conflicts')]
    public function testConflictingIntegrationsAreNeverMigratedImplicitly(array $files): void
    {
        $filesystem = $this->createMock(InstallationFilesystemInterface::class);
        $filesystem->method('inspect')->willReturn($this->snapshot($files));
        $filesystem->expects(self::never())->method('create');
        $filesystem->expects(self::never())->method('read');
        $releases = $this->createMock(WorkflowReleaseResolverInterface::class);
        $releases->expects(self::never())->method('resolve');
        $skills = $this->createMock(SkillInstallerInterface::class);
        $skills->expects(self::never())->method('run');
        $installer = new ProjectInstaller($filesystem, $releases, $skills);

        self::assertSame('conflict', $installer->inspect('/project')['state']);
        $result = $installer->run('/project', null, []);
        self::assertSame('blocked', $result['state']);
        self::assertNotEmpty($result['diagnostics']);
    }

    /** Provides semantic collisions, including independent components using different product revisions. */
    public static function conflicts(): iterable
    {
        foreach (['false', 'jobs: []', 'jobs: [test]', 'jobs: {test: 3}', 'jobs: [',
            "jobs:\n  release:\n    uses: changesets/action@v1",
            "jobs:\n  release:\n    steps:\n      - run: npx changeset version",
            "jobs:\n  release:\n    steps:\n      - uses: softprops/action-gh-release@v2",
            "jobs:\n  release:\n    uses: php-fast-forward/changelog/.github/workflows/changelog.yml@\${{ inputs.ref }}",
        ] as $yaml) {
            yield [['.github/workflows/release.yml' => $yaml]];
        }
        yield 'occupied destination' => [['.github/workflows/changelog.yml' => "jobs:\n  test:\n    steps: []"]];
        yield 'multiple callers' => [['.github/workflows/one.yml' => self::CALLER, '.github/workflows/two.yaml' => self::CALLER]];
        yield 'multiple callers in one workflow' => [['.github/workflows/one.yml' => self::CALLER . "\n  second:\n    uses: php-fast-forward/changelog/.github/workflows/changelog.yml@v0\n"]];
        yield 'mixed revisions' => [['.github/workflows/one.yml' => self::CALLER . "\n  other:\n    uses: php-fast-forward/changelog/.github/actions/check@v9\n"]];
        yield 'mixed modular revisions' => [[
            '.github/workflows/check.yml' => "jobs:\n  check:\n    uses: php-fast-forward/changelog/.github/workflows/changelog-check.yml@v0\n",
            '.github/workflows/publish.yml' => "jobs:\n  publish:\n    steps:\n      - uses: php-fast-forward/changelog/.github/actions/publish@v9\n",
        ]];
        yield 'mixed migration' => [['.github/workflows/one.yml' => self::CALLER . "\n  other:\n    uses: changesets/action@v1\n"]];
    }

    /** Dry runs produce exact selected-version callers, omit defaults and never install the previewed skill. */
    public function testDryRunUsesSelectedContractAndPreservesEventAndPermissionStructure(): void
    {
        $filesystem = $this->createMock(InstallationFilesystemInterface::class);
        $filesystem->method('inspect')->willReturn($this->snapshot());
        $filesystem->expects(self::once())->method('read')->with('/project', 'skills-lock.json')->willReturn(null);
        $filesystem->expects(self::never())->method('create');
        $releases = $this->createMock(WorkflowReleaseResolverInterface::class);
        $releases->expects(self::once())->method('resolve')->with('v0.2.0')->willReturn($this->release());
        $skills = $this->createMock(SkillInstallerInterface::class);
        $skills->expects(self::once())->method('run')->with(
            '/project',
            '.agents/skills',
            false,
            'install',
            true,
        )->willReturn(
            ['state' => 'absent'],
        );
        $result = new ProjectInstaller($filesystem, $releases, $skills)->run('/project', 'v0.2.0', [
            'base-branch' => 'maintenance/1.x', 'publish' => true, 'source' => 'auto', 'count' => 4, 'ratio' => 0.5,
        ], dryRun: true);
        $content = $result['workflow']['content'];
        $document = Yaml::parse($content);

        self::assertSame('planned', $result['state']);
        self::assertSame('absent', $result['skill']['state']);
        self::assertSame(
            'php-fast-forward/changelog/.github/workflows/changelog.yml@v0.2.0',
            $document['jobs']['changelog']['uses'],
        );
        self::assertSame([
            'base-branch' => 'maintenance/1.x', 'publish' => true, 'ratio' => 0.5],
            $document['jobs']['changelog']['with'],
        );
        self::assertSame(['maintenance/1.x'], $document['on']['push']['branches']);
        self::assertSame(['opened', 'closed'], $document['on']['pull_request_target']['types']);
        self::assertSame([], $document['permissions']);
        self::assertStringContainsString("\non:\n", $content);
        self::assertStringNotContainsString('---', $content);
    }

    /** Complete installation validates the skill first, creates only one caller and then installs the absent payload. */
    public function testInstallsCallerAndSkillInExplicitOrder(): void
    {
        $filesystem = $this->createMock(InstallationFilesystemInterface::class);
        $filesystem->method('inspect')->willReturn($this->snapshot());
        $filesystem->method('read')->willReturn(null);
        $events = [];
        $filesystem->expects(self::once())->method('create')->willReturnCallback(
            function (string $root, string $path, string $bytes, array $expected) use (&$events): void {
                self::assertSame('/project', $root);
                self::assertSame('.github/workflows/changelog.yml', $path);
                self::assertSame([], $expected);
                self::assertSame(['preview'], $events);
                self::assertArrayNotHasKey('with', Yaml::parse($bytes)['jobs']['changelog']);
                $events[] = 'workflow';
            },
        );
        $releases = $this->createStub(WorkflowReleaseResolverInterface::class);
        $release = $this->release();
        $release['inputs']['base-branch']['default'] = 'main';
        $releases->method('resolve')->willReturn($release);
        $skills = $this->createMock(SkillInstallerInterface::class);
        $skills->expects(self::exactly(2))->method('run')->willReturnCallback(
            static function (
                string $cwd,
                string $target = '.agents/skills',
                bool $global = false,
                string $mode = 'install',
                bool $dryRun = false,
            ) use (
                &$events
            ): array {
                $events[] = $dryRun ? 'preview' : 'skill';

                return ['state' => $dryRun ? 'absent' : 'installed'];
            },
        );
        $result = new ProjectInstaller($filesystem, $releases, $skills)->run('/project', null, []);

        self::assertSame(['preview', 'workflow', 'skill'], $events);
        self::assertSame('installed', $result['state']);
        self::assertSame('installed', $result['skill']['state']);
    }

    /** Confirmation binds the exact previewed caller; matching content installs without an unreviewed replacement. */
    public function testConfirmedWorkflowHashMustMatchBeforeAnyWrite(): void
    {
        $filesystem = $this->createMock(InstallationFilesystemInterface::class);
        $filesystem->method('inspect')->willReturn($this->snapshot());
        $filesystem->expects(self::once())->method('create');
        $releases = $this->createStub(WorkflowReleaseResolverInterface::class);
        $releases->method('resolve')->willReturn($this->release());
        $skills = $this->createMock(SkillInstallerInterface::class);
        $skills->expects(self::never())->method('run');
        $installer = new ProjectInstaller($filesystem, $releases, $skills);
        $preview = $installer->run('/project', null, [], false, true);
        $rejected = $installer->run('/project', null, [], false, false, str_repeat('f', 64));
        self::assertSame('blocked', $rejected['state']);
        self::assertStringContainsString('changed after confirmation', $rejected['diagnostics'][0]);
        $accepted = $installer->run(
            '/project',
            null,
            [],
            false,
            false,
            hash('sha256', json_encode($preview['workflow'], JSON_THROW_ON_ERROR)),
        );

        self::assertSame('installed', $accepted['state']);
    }

    /** A native install can refuse without throwing when another installer changes ownership during the operation. */
    #[DataProvider('skillFailureStates')]
    public function testSkillRefusalReportsPartialOrBlockedState(bool $existing): void
    {
        $filesystem = $this->createStub(InstallationFilesystemInterface::class);
        $filesystem->method('inspect')->willReturn(
            $this->snapshot($existing ? ['.github/workflows/custom.yml' => self::CALLER] : []),
        );
        $filesystem->method('read')->willReturn(null);
        $releases = $this->createStub(WorkflowReleaseResolverInterface::class);
        $releases->method('resolve')->willReturn($this->release());
        $skills = $this->createMock(SkillInstallerInterface::class);
        $skills->expects(self::exactly(2))->method('run')->willReturnOnConsecutiveCalls(
            ['state' => 'absent'],
            ['state' => 'unmanaged'],
        );
        $result = new ProjectInstaller($filesystem, $releases, $skills)->run('/project', null, []);

        self::assertSame($existing ? 'blocked' : 'partial', $result['state']);
        self::assertSame('unmanaged', $result['skill']['state']);
        self::assertStringContainsString('ownership changed', $result['diagnostics'][0]);
    }

    /** Existing native copies remain untouched, including copies whose donor version has advanced. */
    #[DataProvider('nativeStates')]
    public function testExistingSkillIsPreserved(string $state): void
    {
        $filesystem = $this->createStub(InstallationFilesystemInterface::class);
        $filesystem->method('inspect')->willReturn($this->snapshot(['.github/workflows/custom.yml' => self::CALLER]));
        $filesystem->method('read')->willReturn('{"version":1,"skills":{"other":{}}}');
        $skills = $this->createMock(SkillInstallerInterface::class);
        $skills->expects(self::once())->method('run')->with(
            '/project',
            '.agents/skills',
            false,
            'install',
            true,
        )->willReturn(
            ['state' => $state],
        );
        $result = new ProjectInstaller($filesystem, $this->createStub(
            WorkflowReleaseResolverInterface::class,
        ), $skills)->run(
            '/project',
            null,
            [],
        );

        self::assertSame('existing', $result['state']);
        self::assertSame($state, $result['skill']['state']);
        self::assertCount('current' === $state ? 0 : 1, $result['diagnostics']);
    }

    /** Provides safe native ownership states whose installation bytes must never be upgraded incidentally. */
    public static function nativeStates(): iterable
    {
        yield ['current'];
        yield ['outdated'];
        yield ['different'];
    }

    /** Third-party lock ownership is honored even if the skill directory is absent; invalid lock formats are preserved. */
    #[DataProvider('foreignLocks')]
    public function testForeignSkillLockBlocksBeforeAnyWrite(string $lock): void
    {
        $filesystem = $this->createMock(InstallationFilesystemInterface::class);
        $filesystem->method('inspect')->willReturn($this->snapshot());
        $filesystem->method('read')->willReturn($lock);
        $filesystem->expects(self::never())->method('create');
        $releases = $this->createMock(WorkflowReleaseResolverInterface::class);
        $releases->expects(self::never())->method('resolve');
        $skills = $this->createMock(SkillInstallerInterface::class);
        $skills->expects(self::never())->method('run');
        $result = new ProjectInstaller($filesystem, $releases, $skills)->run('/project', null, []);

        self::assertSame('blocked', $result['state']);
        self::assertSame('unmanaged', $result['skill']['state']);
        self::assertNotEmpty($result['diagnostics']);
    }

    /** Provides current third-party ownership, malformed JSON and unsupported lock schemas. */
    public static function foreignLocks(): iterable
    {
        yield ['{"version":1,"skills":{"fast-forward-changelog":{}}}'];
        yield ['{'];
        yield ['null'];
        yield ['{}'];
    }

    /** Diverged native ownership prevents adoption rather than leaving a partially configured project. */
    public function testNativeConflictBlocksBeforeReleaseDiscovery(): void
    {
        $filesystem = $this->createMock(InstallationFilesystemInterface::class);
        $filesystem->method('inspect')->willReturn($this->snapshot());
        $filesystem->method('read')->willReturn(null);
        $filesystem->expects(self::never())->method('create');
        $skills = $this->createStub(SkillInstallerInterface::class);
        $skills->method('run')->willReturn(['state' => 'diverged']);
        $releases = $this->createMock(WorkflowReleaseResolverInterface::class);
        $releases->expects(self::never())->method('resolve');

        self::assertSame(
            'blocked',
            new ProjectInstaller($filesystem, $releases, $skills)->run('/project', null, [])['state'],
        );
    }

    /** A later skill failure retains the installed workflow and reports partial success instead of claiming rollback. */
    #[DataProvider('skillFailureStates')]
    public function testSkillFailureReportsActualWorkflowState(bool $existing): void
    {
        $filesystem = $this->createStub(InstallationFilesystemInterface::class);
        $filesystem->method('inspect')->willReturn(
            $this->snapshot($existing ? ['.github/workflows/custom.yml' => self::CALLER] : []),
        );
        $filesystem->method('read')->willReturn(null);
        $releases = $this->createStub(WorkflowReleaseResolverInterface::class);
        $releases->method('resolve')->willReturn($this->release());
        $skills = $this->createMock(SkillInstallerInterface::class);
        $skills->expects(self::exactly(2))->method('run')->willReturnCallback(
            static function (
                string $cwd,
                string $target = '.agents/skills',
                bool $global = false,
                string $mode = 'install',
                bool $dryRun = false,
            ): array {
                if (! $dryRun) {
                    throw new RuntimeException('disk full');
                }

                return ['state' => 'absent'];
            },
        );
        $result = new ProjectInstaller($filesystem, $releases, $skills)->run('/project', null, []);

        self::assertSame($existing ? 'blocked' : 'partial', $result['state']);
        self::assertStringContainsString('disk full', $result['diagnostics'][0]);
    }

    /** Exercises both post-write partial success and an untouched existing workflow on skill failure. */
    public static function skillFailureStates(): iterable
    {
        yield [false];
        yield [true];
    }

    /** Input types and expressions are rejected before a caller can reach filesystem creation. */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreRejected(array $settings, array $additionalInputs = []): void
    {
        $release = $this->release();
        $release['inputs'] = array_replace($release['inputs'], $additionalInputs);
        $this->expectException(InvalidArgumentException::class);
        $this->plan($settings, $release);
    }

    /** Provides invalid names/types, injected expressions and unfulfilled selected-version requirements. */
    public static function invalidSettings(): iterable
    {
        yield [['unknown' => true]];
        yield [['publish' => 'true']];
        yield [['source' => '${{ secrets.TOKEN }}']];
        yield [['source' => "bad\0value"]];
        yield [['source' => 4]];
        yield [['count' => INF]];
        yield [['ratio' => NAN]];
        yield [['count' => '3']];
        yield [['source' => 'tags'], ['source' => ['type' => 'object']]];
        yield [[], ['required-field' => ['type' => 'string', 'required' => true]]];
    }

    /** Workflow branch filters must contain one literal Git branch rather than a pattern or traversal expression. */
    #[DataProvider('invalidBranches')]
    public function testInvalidBranchesAreRejected(string $branch): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->plan(['base-branch' => $branch], $this->release());
    }

    /** Provides branch syntax that could filter the wrong release line or fail Git reference validation. */
    public static function invalidBranches(): iterable
    {
        foreach ([
            '',
            '-main',
            'main.',
            'main.lock',
            'one..two',
            'one@{two',
            'one two',
            '/main',
            'main/',
            'one//two',
            'one/.two',
            'm*',
            'm~',
            'm[',
        ] as $branch) {
            yield [$branch];
        }
    }

    /** A release without the supported consumer structure is never treated as an installable caller. */
    #[DataProvider('invalidCallers')]
    public function testUnsupportedCallerIsRejected(string $caller): void
    {
        $release = $this->release();
        $release['caller'] = $caller;
        $this->expectException(RuntimeException::class);
        $this->plan([], $release);
    }

    /** Provides absent jobs, absent event declarations and duplicate central reusable jobs. */
    public static function invalidCallers(): iterable
    {
        yield ['true'];
        yield ["jobs:\n  changelog:\n    uses: php-fast-forward/changelog/.github/workflows/changelog.yml@v0"];
        yield [self::CALLER . "\n  second:\n    uses: php-fast-forward/changelog/.github/workflows/changelog.yml@v0"];
    }

    /** Executes a read-only no-skill plan against injected local and network boundaries. */
    private function plan(array $settings, array $release): array
    {
        $filesystem = $this->createMock(InstallationFilesystemInterface::class);
        $filesystem->method('inspect')->willReturn($this->snapshot());
        $filesystem->expects(self::never())->method('create');
        $releases = $this->createStub(WorkflowReleaseResolverInterface::class);
        $releases->method('resolve')->willReturn($release);

        return new ProjectInstaller($filesystem, $releases, $this->createStub(SkillInstallerInterface::class))->run(
            '/project',
            null,
            $settings,
            false,
            true,
        );
    }

    /** Returns a deterministic local repository inventory without filesystem or Git access. */
    private function snapshot(array $files = []): array
    {
        return ['root' => '/project', 'base_branch' => 'main', 'files' => $files, 'diagnostics' => []];
    }

    /** Returns a synthetic published contract with differing literal defaults and one missing optional default. */
    private function release(): array
    {
        return ['ref' => 'v0.2.0', 'sha' => str_repeat('a', 40), 'caller' => self::CALLER,
            'inputs' => ['base-branch' => ['type' => 'string', 'default' => ''],
                'publish' => ['type' => 'boolean', 'default' => false],
                'source' => ['type' => 'string', 'default' => 'auto'],
                'count' => ['type' => 'number', 'default' => 4], 'ratio' => ['type' => 'number']]];
    }
}
