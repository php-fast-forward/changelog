<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Skill;

use ArrayIterator;
use Closure;
use FastForward\Changelog\Filesystem\Factory\FinderFactoryInterface;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolver;
use FastForward\Changelog\Skill\SkillInstaller;
use FastForward\Changelog\Version\PackageVersionResolverInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

#[CoversClass(SkillInstaller::class)]
#[UsesClass(PackagePathResolver::class)]
final class SkillInstallerTest extends TestCase
{
    private const string DEST = '/project/.agents/skills/fast-forward-changelog';
    private array $bytes = [];
    private array $directories = [];
    private array $links = [];
    private array $observedLinks = [];
    private array $scans = [];
    private string $version = '1.0.0';
    private int $writes = 0;
    private int $locks = 0;
    private int $releases = 0;
    private bool $acquire = true;
    private ?Closure $onScan = null;
    private ?Closure $onRename = null;
    private ?Closure $onRemove = null;
    private ?Closure $onWrite = null;
    private array $extraEntries = [];
    private ?string $missingRead = null;
    private SkillInstaller $installer;

    protected function setUp(): void
    {
        $this->directories = array_fill_keys(['/', '/project', '/payload', '/home', '/home/test'], true);
        $this->put('/payload/SKILL.md', "# Canonical skill\n");
        $this->put('/payload/LICENSE', "MIT\n");
        $this->put('/payload/references/guide.md', "Guide\n");
        $this->installer = $this->compose();
    }

    public function testInstallationCheckAndPortableReceiptHaveExactCompleteBytes(): void
    {
        $result = $this->installer->run();
        self::assertSame('installed', $result['state']);
        self::assertSame($result['source'], $result['installed_source']);
        self::assertSame('project', $result['scope']);
        self::assertSame('.agents/skills/fast-forward-changelog', $result['target']);
        $receipt = $this->receipt();
        self::assertSame('fast-forward/changelog', $receipt['owner']);
        self::assertSame(['LICENSE', 'SKILL.md', 'references/guide.md'], array_keys($receipt['files']));
        self::assertSame(str_repeat('a', 40), $receipt['source']['reference']);
        self::assertStringNotContainsString('/project', $this->bytes[self::DEST . '/' . SkillInstaller::RECEIPT]);
        self::assertSame($this->bytes['/payload/SKILL.md'], $this->bytes[self::DEST . '/SKILL.md']);
        $writes = $this->writes;
        self::assertSame('current', $this->installer->run(mode: 'check')['state']);
        self::assertSame('current', $this->installer->run()['state']);
        self::assertSame($writes, $this->writes);
        self::assertSame(2, $this->locks);
        self::assertSame(2, $this->releases);
    }

    public function testReadOnlyModesDoNotAcquireLocksOrCreateDirectories(): void
    {
        $before = [$this->directories, $this->bytes];
        self::assertSame('absent', $this->installer->run(mode: 'check')['state']);
        self::assertSame('install', $this->installer->run(dryRun: true)['action']);
        self::assertNull($this->installer->run(mode: 'update', dryRun: true)['action']);
        self::assertSame($before, [$this->directories, $this->bytes]);
        self::assertSame(0, $this->locks);
    }

    public function testScopeAndTargetAreExplicitAndPortableAfterMovingProject(): void
    {
        self::assertSame('installed', $this->installer->run(cwd: '/project', target: 'agent/custom')['state']);
        self::assertArrayHasKey('/project/agent/custom/fast-forward-changelog/SKILL.md', $this->bytes);
        self::assertSame('installed', $this->installer->run(global: true)['state']);
        $global = json_decode(
            $this->bytes['/home/test/.agents/skills/fast-forward-changelog/' . SkillInstaller::RECEIPT],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('global', $global['scope']);
        self::assertSame('.agents/skills/fast-forward-changelog', $global['target']);
        $this->installer->run();
        $this->move('/project', '/moved');
        self::assertSame('current', $this->installer->run('/moved', mode: 'check')['state']);
    }

    public function testIntactUpdateRemovesOldPayloadFileAndReplacesReceiptTogether(): void
    {
        $this->installer->run();
        unset($this->bytes['/payload/references/guide.md'], $this->directories['/payload/references']);
        $this->put('/payload/references/new.md', 'New');
        $this->version = '1.1.0';
        self::assertSame('outdated', $this->installer->run(mode: 'check')['state']);
        self::assertSame('updated', $this->installer->run(mode: 'update')['state']);
        self::assertArrayNotHasKey(self::DEST . '/references/guide.md', $this->bytes);
        self::assertSame('New', $this->bytes[self::DEST . '/references/new.md']);
        self::assertSame('1.1.0', $this->receipt()['source']['version']);
        self::assertSame('current', $this->installer->run(mode: 'check')['state']);
        self::assertFalse(
            array_any(array_keys($this->directories), static fn(string $path): bool => str_ends_with($path, '.backup')),
        );
    }

    public function testIdenticalPayloadAcrossVersionsDoesNotRewriteOrWarnAsOutdated(): void
    {
        $this->installer->run();
        $before = $this->bytes;
        $this->version = '2.0.0';
        self::assertSame('current', $this->installer->run(mode: 'update')['state']);
        self::assertSame($before, $this->bytes);
    }

    /** Reports recorded native provenance separately from this CLI without inventing a remote latest version. */
    public function testInstalledProvenanceTracksVerifiedPayloadAcrossPreviewAndUpdate(): void
    {
        self::assertNull($this->installer->run(mode: 'check')['installed_source']);
        self::assertNull($this->installer->run(dryRun: true)['installed_source']);
        $installed = $this->installer->run()['source'];
        $this->version = '2.0.0';
        $before = [$this->bytes, $this->writes, $this->locks];
        $current = $this->installer->run(mode: 'check');
        self::assertSame('current', $current['state']);
        self::assertSame($installed, $current['installed_source']);
        self::assertSame('2.0.0', $current['source']['version']);
        self::assertSame($before, [$this->bytes, $this->writes, $this->locks]);

        $this->bytes['/payload/SKILL.md'] .= 'New instructions';
        $before = [$this->bytes, $this->writes, $this->locks];
        $preview = $this->installer->run(mode: 'update', dryRun: true);
        self::assertSame('outdated', $preview['state']);
        self::assertSame($installed, $preview['installed_source']);
        self::assertSame($before, [$this->bytes, $this->writes, $this->locks]);

        $updated = $this->installer->run(mode: 'update');
        self::assertSame('updated', $updated['state']);
        self::assertSame($updated['source'], $updated['installed_source']);
        self::assertSame('2.0.0', $updated['installed_source']['version']);
        self::assertSame($updated['installed_source'], $this->receipt()['source']);
    }

    /** Exposes only validated receipt fields and preserves unknown commit identity as null. */
    public function testInstalledProvenanceDoesNotAdoptAdditionalReceiptClaims(): void
    {
        $this->installer = $this->compose(reference: null);
        $this->installer->run();
        $receipt = $this->receipt();
        $receipt['source']['latest'] = '999.0.0';
        $this->bytes[self::DEST . '/' . SkillInstaller::RECEIPT] = json_encode($receipt, JSON_THROW_ON_ERROR);
        $result = $this->installer->run(mode: 'check');

        self::assertSame('current', $result['state']);
        self::assertSame(
            ['package' => 'fast-forward/changelog', 'version' => '1.0.0', 'reference' => null],
            $result['installed_source'],
        );
    }

    #[DataProvider('ages')]
    public function testAgeNeverInventsRemoteLatestAndKnownDowngradesAreRefused(
        string $installed,
        string $running,
        string $state,
        ?string $action,
    ): void {
        $this->version = $installed;
        $this->installer->run();
        $this->version = $running;
        $this->bytes['/payload/SKILL.md'] .= 'Changed';
        $result = $this->installer->run(mode: 'update', dryRun: true);
        self::assertSame($state, $result['state']);
        self::assertSame($action, $result['action']);
        self::assertSame($installed, $result['installed_source']['version']);
        self::assertSame($running, $result['source']['version']);
    }

    public static function ages(): array
    {
        return [['v1.0.0', '1.1.0+build.1', 'outdated', 'update'], ['2.0.0', '1.0.0', 'different', null],
            ['dev-main', '1.0.0', 'different', 'update'], ['1.0.0', 'dev-main', 'different', 'update'],
            ['1.0.0', '1.0.0', 'different', 'update']];
    }

    public function testManualAndThirdPartyCopiesAreNeverTakenOver(): void
    {
        $this->put(self::DEST . '/SKILL.md', 'Human or NPX copy');
        $this->put('/project/skills-lock.json', 'foreign lock');
        $before = $this->bytes;
        $result = $this->installer->run(mode: 'update');
        self::assertSame('unmanaged', $result['state']);
        self::assertNull($result['installed_source']);
        self::assertSame($before, $this->bytes);
    }

    #[DataProvider('localChanges')]
    public function testLocalEditsAndExtraFilesArePreserved(string $path): void
    {
        $this->installer->run();
        $this->put(self::DEST . '/' . $path, 'Local edit');
        $before = $this->bytes;
        $result = $this->installer->run(mode: 'update');
        self::assertSame('diverged', $result['state']);
        self::assertNull($result['installed_source']);
        self::assertSame($before, $this->bytes);
    }

    public static function localChanges(): array
    {
        return [['SKILL.md'], ['custom.md'], ['.host-settings']];
    }

    #[DataProvider('invalidReceipts')]
    public function testInvalidReceiptsDoNotEstablishOwnership(string $kind): void
    {
        $this->installer->run();
        $data = $this->receipt();
        match ($kind) {
            'json' => null,
            'null' => $data = null,
            'schema' => $data['schema'] = '1',
            'owner' => $data['owner'] = 'npx/skills',
            'name' => $data['name'] = 'other',
            'scope' => $data['scope'] = 'global',
            'target' => $data['target'] = '/private/home',
            'package' => $data['source']['package'] = 'foreign',
            'version' => $data['source']['version'] = '',
            'reference-missing' => $data['source'] = ['package' => 'fast-forward/changelog', 'version' => '1.0.0'],
            'reference-type' => $data['source']['reference'] = 1,
            'reference-invalid' => $data['source']['reference'] = 'main',
            'files-empty' => $data['files'] = [],
            'files-list' => $data['files'] = ['invalid'],
            'path-int' => $data['files'][1] = str_repeat('a', 64),
            'hash-type' => $data['files']['SKILL.md'] = 5,
            'hash-invalid' => $data['files']['SKILL.md'] = 'wrong',
            'receipt-self' => $data['files'][SkillInstaller::RECEIPT] = str_repeat('a', 64),
            'path-escape' => $data['files']['../outside'] = str_repeat('a', 64),
            'path-backslash' => $data['files']['references\\guide.md'] = str_repeat('a', 64),
            'ordering' => $data['files'] = array_reverse($data['files'], true),
            'digest' => $data['payload_sha256'] = 'wrong',
        };
        $this->bytes[self::DEST . '/' . SkillInstaller::RECEIPT] = 'json' === $kind ? '{' : json_encode(
            $data,
            JSON_THROW_ON_ERROR,
        );
        $before = $this->bytes;
        $result = $this->installer->run(mode: 'update');
        self::assertSame('unmanaged', $result['state']);
        self::assertNull($result['installed_source']);
        self::assertSame($before, $this->bytes);
    }

    public static function invalidReceipts(): array
    {
        return array_map(
            static fn(string $value): array => [$value],
            ['json', 'null', 'schema', 'owner', 'name', 'scope', 'target', 'package', 'version',
                'reference-missing', 'reference-type', 'reference-invalid', 'files-empty', 'files-list', 'path-int', 'hash-type', 'hash-invalid', 'receipt-self',
                'path-escape', 'path-backslash', 'ordering', 'digest'],
        );
    }

    public function testUnknownSourceReferenceIsNotInvented(): void
    {
        $this->installer = $this->compose(reference: 'dev-main');
        $this->installer->run();
        self::assertNull($this->receipt()['source']['reference']);
        self::assertSame('current', $this->installer->run(mode: 'check')['state']);
    }

    public function testEmptyVersionCannotWriteAnUnverifiableReceipt(): void
    {
        $this->version = '';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('version must not be empty');
        try {
            $this->installer->run();
        } finally {
            self::assertSame(0, $this->writes);
            self::assertSame(1, $this->releases);
        }
    }

    #[DataProvider('unsafeTargets')]
    public function testLexicalEscapesAndAliasesAreRejected(string $target): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->installer->run(target: $target);
    }

    public static function unsafeTargets(): array
    {
        return [[
            ''],
            ['/outside'],
            ['../outside'],
            ['./skills'],
            ['skills//nested'],
            ["skills\0name"],
            ['C:\\outside'],
            ['\\\\server\\share'],
        ];
    }

    public function testInvalidModeAndUnconfiguredGlobalScopeFailBeforeWrites(): void
    {
        foreach (['mode', 'global'] as $case) {
            try {
                'mode' === $case ? $this->installer->run(mode: 'sync') : $this->compose(home: null)->run(global: true);
                self::fail('Expected invalid scope/mode.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $this->writes);
            }
        }
    }

    public function testRelativeDonorAndSymbolicAncestorAreRejected(): void
    {
        try {
            $this->compose(source: 'payload')->run();
            self::fail('Relative donor was accepted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('absolute scope', $error->getMessage());
        }
        $this->links['/project/.agents'] = '/outside';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symbolic links');
        $this->installer->run();
    }

    #[DataProvider('absoluteScopes')]
    public function testDriveAndUncGuardsStopAtTheirActualRoot(string $scope, string $unrelated, string $root): void
    {
        $this->directories[str_replace('\\', '/', $scope)] = true;
        $this->links[$unrelated] = '/outside-scope';
        self::assertSame('absent', $this->installer->run($scope, mode: 'check')['state']);
        self::assertContains($root, $this->observedLinks);
        self::assertNotContains($unrelated, $this->observedLinks);
    }

    public static function absoluteScopes(): array
    {
        return [['C:/project', 'C:', 'C:/'], ['C:\\project', 'C:', 'C:/'],
            ['//server/share/project', '//server', '//server/share'], [
                '\\\\server\\share\\project',
                '//server',
                '//server/share',
            ]];
    }

    #[DataProvider('symbolicRoots')]
    public function testNativeRootLinksAreRejectedBeforeInventoryOrWrites(string $scope, string $root): void
    {
        $this->directories[$scope] = true;
        $this->links[$root] = '/outside-scope';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symbolic links');
        try {
            $this->installer->run($scope, mode: 'check');
        } finally {
            self::assertSame([], $this->scans);
            self::assertSame(0, $this->writes);
        }
    }

    public static function symbolicRoots(): array
    {
        return [['C:/project', 'C:/'], ['//server/share/project', '//server/share'], ['/project', '/']];
    }

    #[DataProvider('nativeDonorRoots')]
    public function testNativeDonorRootSpellingDoesNotTraverseBeyondItsRoot(string $root, string $unrelated): void
    {
        $this->directories[$root] = true;
        $this->bytes[$root . '/SKILL.md'] = 'Canonical root payload';
        $this->bytes[$root . '/LICENSE'] = 'MIT';
        $this->links[$unrelated] = '/outside-scope';
        self::assertSame('absent', $this->compose(source: $root)->run(mode: 'check')['state']);
        self::assertContains($root, $this->observedLinks);
        self::assertNotContains($unrelated, $this->observedLinks);
    }

    public static function nativeDonorRoots(): array
    {
        return [['C:\\', 'C:'], ['\\\\server\\share', '//server']];
    }

    #[DataProvider('invalidDonors')]
    public function testIncompleteOrPreinstalledDonorFails(string $kind): void
    {
        if ('absent' === $kind) {
            unset($this->directories['/payload']);
        } elseif ('missing' === $kind) {
            unset($this->bytes['/payload/LICENSE']);
        } else {
            $this->put('/payload/' . SkillInstaller::RECEIPT, '{}');
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('complete canonical');
        $this->installer->run();
    }

    public static function invalidDonors(): array
    {
        return [['absent'], ['missing'], ['receipt']];
    }

    #[DataProvider('unsafeEntries')]
    public function testTreesRejectLinksSpecialFilesEmptyDirectoriesDuplicatesAndDisappearingFiles(string $kind): void
    {
        if ('empty' === $kind) {
            $this->directories['/payload/empty'] = true;
        } elseif ('missing' === $kind) {
            $this->missingRead = '/payload/SKILL.md';
        } else {
            $this->extraEntries['/payload'] = [['SKILL.md', $kind]];
        }
        $this->expectException(RuntimeException::class);
        $this->installer->run();
    }

    public static function unsafeEntries(): array
    {
        return [['link'], ['special'], ['duplicate'], ['empty'], ['missing']];
    }

    public function testLockRefusalHasNoWrites(): void
    {
        $this->acquire = false;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('installation lock');
        try {
            $this->installer->run();
        } finally {
            self::assertSame(0, $this->writes);
            self::assertSame(0, $this->releases);
        }
    }

    #[DataProvider('recoveryKinds')]
    public function testRecoveryLeftoversBlockAllTakeover(string $suffix): void
    {
        $path = $this->temporary($suffix);
        $this->put($path . '/preserve.md', 'Crash evidence');
        $before = $this->bytes;
        self::assertSame('recovery-required', $this->installer->run()['state']);
        self::assertSame($before, $this->bytes);
    }

    public static function recoveryKinds(): array
    {
        return [['stage'], ['backup']];
    }

    #[DataProvider('discoveryTargets')]
    public function testStageAndBackupStayOutsideEverySelectedDiscoveryRoot(string $target): void
    {
        $destination = '/project/' . $target . '/' . SkillInstaller::NAME;
        $prefix = '/project/.fast-forward-changelog-' . substr(
            hash('sha256', $target . '/' . SkillInstaller::NAME),
            0,
            16,
        );
        $this->installer->run(target: $target);
        $this->bytes['/payload/SKILL.md'] .= 'New payload';
        $this->version = '1.1.0';
        $this->onRename = static function (string $from): void {
            if (str_ends_with($from, '.stage') || str_ends_with($from, '.backup')) {
                throw new RuntimeException('Unavailable recovery rename');
            }
        };
        $this->expectException(RuntimeException::class);
        try {
            $this->installer->run(target: $target, mode: 'update');
        } finally {
            foreach (['stage', 'backup'] as $suffix) {
                self::assertArrayHasKey($prefix . '.' . $suffix . '/SKILL.md', $this->bytes);
                self::assertFalse(str_starts_with($prefix . '.' . $suffix, '/project/' . $target . '/'));
            }
            self::assertArrayNotHasKey($destination . '/SKILL.md', $this->bytes);
        }
    }

    public static function discoveryTargets(): array
    {
        return [['.agents/skills'], ['.agents/skills/fast-forward'], ['tools/deep/custom/skills']];
    }

    public function testRecoveryEvidenceRemainsVisibleAfterMovingTheProject(): void
    {
        $this->installer->run();
        $this->put(
            '/project/.fast-forward-changelog-' . substr(
                hash('sha256', '.agents/skills/' . SkillInstaller::NAME),
                0,
                16,
            ) . '.stage/preserve.md',
            'Recovery evidence',
        );
        $this->move('/project', '/moved');
        $before = $this->bytes;
        $result = $this->installer->run('/moved', mode: 'update');
        self::assertSame('recovery-required', $result['state']);
        self::assertNull($result['installed_source']);
        self::assertSame($before, $this->bytes);
    }

    #[DataProvider('rootScopes')]
    public function testRecoveryNamesStayInsidePosixDriveAndShareScopeRoots(string $scope): void
    {
        $this->directories[$scope] = true;
        $prefix = rtrim($scope, '/') . '/.fast-forward-changelog-' . substr(
            hash('sha256', '.agents/skills/' . SkillInstaller::NAME),
            0,
            16,
        );
        $this->put($prefix . '.stage/preserve.md', 'Recovery evidence');
        $this->links['//server'] = '/outside-share';
        self::assertSame('recovery-required', $this->installer->run($scope, mode: 'check')['state']);
        self::assertSame(0, $this->writes);
    }

    public static function rootScopes(): array
    {
        return [['/'], ['C:/'], ['//server/share']];
    }

    #[DataProvider('racePhases')]
    public function testConcurrentEditsBeforeSwapArePreserved(int $scan): void
    {
        $this->prepareUpdate();
        $this->onScan = function (string $path, int $count) use ($scan): void {
            if (self::DEST === $path && $count === $scan) {
                $this->bytes[self::DEST . '/SKILL.md'] = 'Concurrent human edit';
            }
        };
        $this->expectException(RuntimeException::class);
        try {
            $this->installer->run(mode: 'update');
        } finally {
            self::assertSame('Concurrent human edit', $this->bytes[self::DEST . '/SKILL.md']);
            self::assertSame(2, $this->releases);
        }
    }

    public static function racePhases(): array
    {
        return [[2], [3]];
    }

    public function testEditDuringBackupRenameIsRolledBackWithoutDeletingTheEdit(): void
    {
        $this->prepareUpdate();
        $this->onRename = function (string $from): void {
            if (self::DEST === $from) {
                $this->bytes[self::DEST . '/SKILL.md'] = 'Human edit during rename';
            }
        };
        $this->expectException(RuntimeException::class);
        try {
            $this->installer->run(mode: 'update');
        } finally {
            self::assertSame('Human edit during rename', $this->bytes[self::DEST . '/SKILL.md']);
            self::assertArrayHasKey($this->temporary('stage') . '/SKILL.md', $this->bytes);
        }
    }

    public function testPromotionFailureRestoresOldTreeAndRetainsStaging(): void
    {
        $this->prepareUpdate();
        $old = $this->bytes[self::DEST . '/SKILL.md'];
        $this->onRename = function (string $from): void {
            if (str_ends_with($from, '.stage')) {
                throw new RuntimeException('Synthetic promotion failure');
            }
        };
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('previous bytes were preserved');
        try {
            $this->installer->run(mode: 'update');
        } finally {
            self::assertSame($old, $this->bytes[self::DEST . '/SKILL.md']);
            self::assertArrayHasKey($this->temporary('stage') . '/SKILL.md', $this->bytes);
        }
    }

    public function testFailedFinalVerificationPreservesReplacementAndRestoresPreviousTree(): void
    {
        $this->prepareUpdate();
        $old = $this->bytes[self::DEST . '/SKILL.md'];
        $this->onScan = function (string $path, int $count): void {
            if (self::DEST === $path && 4 === $count) {
                $this->bytes[$path . '/SKILL.md'] = 'Changed after promotion';
            }
        };
        $this->expectException(RuntimeException::class);
        try {
            $this->installer->run(mode: 'update');
        } finally {
            self::assertSame($old, $this->bytes[self::DEST . '/SKILL.md']);
            self::assertSame('Changed after promotion', $this->bytes[$this->temporary('stage') . '/SKILL.md']);
        }
    }

    public function testRollbackFailureKeepsBothRecoveryTrees(): void
    {
        $this->prepareUpdate();
        $this->onRename = static function (string $from): void {
            if (str_ends_with($from, '.stage') || str_ends_with($from, '.backup')) {
                throw new RuntimeException('Unavailable rename');
            }
        };
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update and rollback failed');
        try {
            $this->installer->run(mode: 'update');
        } finally {
            self::assertArrayHasKey($this->temporary('stage') . '/SKILL.md', $this->bytes);
            self::assertArrayHasKey($this->temporary('backup') . '/SKILL.md', $this->bytes);
        }
    }

    public function testPartialAdapterRenameFailureCannotClaimSuccessfulRollback(): void
    {
        $this->prepareUpdate();
        $old = $this->bytes[self::DEST . '/SKILL.md'];
        $this->onRename = function (string $from, string $to): void {
            if (self::DEST === $from) {
                $this->move($from, $to);
                $this->put($from . '/SKILL.md', 'Partial original after adapter failure');

                throw new RuntimeException('Directory fallback partially completed');
            }
        };
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update and rollback failed');
        try {
            $this->installer->run(mode: 'update');
        } finally {
            self::assertSame($old, $this->bytes[$this->temporary('backup') . '/SKILL.md']);
            self::assertSame('Partial original after adapter failure', $this->bytes[self::DEST . '/SKILL.md']);
            self::assertArrayHasKey($this->temporary('stage') . '/SKILL.md', $this->bytes);
        }
    }

    public function testStagingWriteFailureDoesNotTouchOldInstallation(): void
    {
        $this->prepareUpdate();
        $old = $this->bytes[self::DEST . '/SKILL.md'];
        $this->onWrite = static function (): void {
            throw new RuntimeException('Disk full');
        };
        $this->expectException(RuntimeException::class);
        try {
            $this->installer->run(mode: 'update');
        } finally {
            self::assertSame($old, $this->bytes[self::DEST . '/SKILL.md']);
        }
    }

    #[DataProvider('cleanupFailures')]
    public function testPostCommitCleanupFailureIsHonestAndPreservesBackup(bool $edit): void
    {
        $this->prepareUpdate();
        if ($edit) {
            $this->onScan = function (string $path, int $count): void {
                if ($path === $this->temporary('backup') && 2 === $count) {
                    $this->bytes[$path . '/SKILL.md'] = 'New human backup edit';
                }
            };
        } else {
            $this->onRemove = static function (): void {
                throw new RuntimeException('Cleanup unavailable');
            };
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('new skill is installed');
        try {
            $this->installer->run(mode: 'update');
        } finally {
            self::assertSame($this->bytes['/payload/SKILL.md'], $this->bytes[self::DEST . '/SKILL.md']);
            self::assertArrayHasKey($this->temporary('backup') . '/SKILL.md', $this->bytes);
        }
    }

    public static function cleanupFailures(): array
    {
        return [[true], [false]];
    }

    /** Creates only injected in-memory boundaries; no Finder, filesystem, host or lock store is used. */
    private function compose(
        string $source = '/payload',
        ?string $home = '/home/test',
        ?string $reference = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ): SkillInstaller {
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('readlink')->willReturnCallback(function (string $path): ?string {
            $this->observedLinks[] = $path;

            return $this->links[$path] ?? null;
        });
        $filesystem->method('exists')->willReturnCallback(
            fn(string $path): bool => isset($this->directories[$path]) || isset($this->bytes[$path]),
        );
        $filesystem->method('mkdir')->willReturnCallback(function (string $path): void {
            $this->mkdir($path);
        });
        $filesystem->method('rename')->willReturnCallback(function (string $from, string $to): void {
            ($this->onRename ?? static fn() => null)($from, $to);
            $this->move($from, $to);
        });
        $filesystem->method('remove')->willReturnCallback(function (string $path): void {
            ($this->onRemove ?? static fn() => null)($path);
            foreach (['bytes', 'directories'] as $property) {
                foreach (array_keys($this->{$property}) as $entry) {
                    if ($entry === $path || str_starts_with($entry, $path . '/')) {
                        unset($this->{$property}[$entry]);
                    }
                }
            }
        });
        $files = $this->createStub(ManagedFileStoreInterface::class);
        $files->method('read')->willReturnCallback(
            fn(string $path): ?string => $path === $this->missingRead ? null : ($this->bytes[$path] ?? null),
        );
        $files->method('write')->willReturnCallback(function (string $path, string $bytes): void {
            ($this->onWrite ?? static fn() => null)($path, $bytes);
            $this->put($path, $bytes);
            ++$this->writes;
        });
        $finders = $this->createStub(FinderFactoryInterface::class);
        $finders->method('create')->willReturnCallback(function (): Finder {
            $directory = '';
            $finder = $this->createStub(Finder::class);
            $finder->method('in')->willReturnCallback(function (string $path) use (&$directory, $finder): Finder {
                if (! isset($this->directories[$path])) {
                    throw new RuntimeException('Directory not found in isolated fixture.');
                }
                $directory = $path;

                return $finder;
            });
            foreach (['ignoreDotFiles', 'ignoreVCS', 'sortByName'] as $method) {
                $finder->method($method)->willReturnSelf();
            }
            $finder->method('getIterator')->willReturnCallback(function () use (&$directory): ArrayIterator {
                $count = $this->scans[$directory] = ($this->scans[$directory] ?? 0) + 1;
                ($this->onScan ?? static fn() => null)($directory, $count);
                $entries = [];
                foreach (['directories' => 'directory', 'bytes' => 'file'] as $property => $type) {
                    foreach (array_keys($this->{$property}) as $path) {
                        if (str_starts_with($path, $directory . '/')) {
                            $entries[] = $this->entry(substr($path, strlen($directory) + 1), $type);
                        }
                    }
                }
                foreach ($this->extraEntries[$directory] ?? [] as [$path, $type]) {
                    $entries[] = $this->entry($path, $type);
                }

                return new ArrayIterator($entries);
            });

            return $finder;
        });
        $locks = $this->createStub(LockFactory::class);
        $locks->method('createLock')->willReturnCallback(function (): SharedLockInterface {
            ++$this->locks;
            $lock = $this->createStub(SharedLockInterface::class);
            $lock->method('acquire')->willReturnCallback(fn(): bool => $this->acquire);
            $lock->method('release')->willReturnCallback(function (): void {
                ++$this->releases;
            });

            return $lock;
        });
        $version = $this->createStub(PackageVersionResolverInterface::class);
        $version->method('resolve')->willReturnCallback(fn(): string => $this->version);

        return new SkillInstaller($filesystem, $files, $finders, new PackagePathResolver(
            '/project',
        ), $locks, $version, $source, $home, $reference);
    }

    /** Describes a mocked inventory entry without touching its apparent native path. */
    private function entry(string $path, string $type): SplFileInfo
    {
        $entry = $this->createStub(SplFileInfo::class);
        $entry->method('getRelativePathname')->willReturn($path);
        $entry->method('isLink')->willReturn('link' === $type);
        $entry->method('isDir')->willReturn('directory' === $type);
        $entry->method('isFile')->willReturn(in_array($type, ['file', 'duplicate'], true));

        return $entry;
    }

    /** Creates a complete parent chain only in the fixture arrays. */
    private function mkdir(string $path): void
    {
        while (! isset($this->directories[$path])) {
            $this->directories[$path] = true;
            $parent = dirname($path);
            if ($path === $parent) {
                break;
            }
            $path = $parent;
        }
    }

    /** Records exact fixture bytes and their synthetic parent directories. */
    private function put(string $path, string $contents): void
    {
        $this->mkdir(dirname($path));
        $this->bytes[$path] = $contents;
    }

    /** Performs an in-memory directory rename with the same no-replacement precondition. */
    private function move(string $from, string $to): void
    {
        if (isset($this->directories[$to])) {
            throw new RuntimeException('Destination already exists.');
        }
        foreach (['bytes', 'directories'] as $property) {
            foreach (array_keys($this->{$property}) as $path) {
                if ($path === $from || str_starts_with($path, $from . '/')) {
                    $this->{$property}[$to . substr($path, strlen($from))] = $this->{$property}[$path];
                    unset($this->{$property}[$path]);
                }
            }
        }
    }

    /** Returns the receipt just produced by the public installation operation. */
    private function receipt(): array
    {
        return json_decode($this->bytes[self::DEST . '/' . SkillInstaller::RECEIPT], true, flags: JSON_THROW_ON_ERROR);
    }

    /** Selects the target's deterministic recovery evidence outside discovery. */
    private function temporary(string $suffix): string
    {
        return '/project/.fast-forward-changelog-' . substr(
            hash('sha256', '.agents/skills/' . SkillInstaller::NAME),
            0,
            16,
        ) . '.' . $suffix;
    }

    /** Establishes an intact older installation and then changes only its executing donor. */
    private function prepareUpdate(): void
    {
        $this->installer->run();
        $this->bytes['/payload/SKILL.md'] .= 'New payload';
        $this->version = '1.1.0';
        $this->scans = [];
    }
}
