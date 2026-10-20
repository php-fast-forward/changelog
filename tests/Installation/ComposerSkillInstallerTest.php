<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Installation;

use Closure;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Installation\ComposerSkillInstaller;
use FastForward\Changelog\Installation\InstallationFilesystemInterface;
use FastForward\Changelog\Installation\SkillManagerRuntimeInterface;
use FastForward\Changelog\Installation\VerifiedSkillManagerRuntime;
use FastForward\Changelog\Process\Factory\ProcessFactoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Process\Process;

/** Exercises manager delegation entirely through in-memory metadata and unstarted process doubles. */
#[CoversClass(ComposerSkillInstaller::class)]
final class ComposerSkillInstallerTest extends TestCase
{
    private const string SKILL_PATH = '.agents/skills/fast-forward-changelog/SKILL.md';
    private array $bytes = [];
    private array $writes = [];
    private array $commands = [];
    private int $locks = 0;
    private int $releases = 0;
    private bool $acquire = true;
    private int $exit = 0;
    private ?Closure $onAcquire = null;
    private ?Closure $onProcess = null;

    /** Starts with only an unrelated owner dependency and script; no real host state is observed. */
    protected function setUp(): void
    {
        $this->bytes = ['composer.json' => '{"name":"vendor/project","require-dev":{"phpunit/phpunit":"^13"},"scripts":{"post-install-cmd":"owner-script"},"config":{"allow-plugins":{"other/plugin":true}}}'];
    }

    /** Preview has no effects; application delegates installation and the exact selected donor, preserving owner values. */
    public function testFreshSetupDelegatesAndCanBeRepeatedWithoutChanges(): void
    {
        $installer = $this->compose();
        $preview = $installer->run('/project', true);
        self::assertSame('planned', $preview['state']);
        self::assertSame('llm/skills', $preview['manager']);
        self::assertSame('php-fast-forward/changelog', $preview['source']);
        self::assertSame(['skills.json', '.gitignore', '.gitattributes'], $preview['changes']);
        self::assertSame([], $this->writes);
        self::assertSame([], $this->commands);
        self::assertSame(0, $this->locks);
        self::assertStringNotContainsString('owner-script', json_encode($preview));
        $this->onProcess = function (array $command): void {
            $config = json_decode($this->bytes['skills.json'], true);
            self::assertSame([], $config['aliases']);
            self::assertFalse($config['dependencies']['composer']);
            self::assertFalse($config['discovery']);
            self::assertFalse($config['vendor-sources']);
            self::assertSame([
                'other/plugin' => true],
                json_decode($this->bytes['composer.json'], true)['config']['allow-plugins'],
            );
            if (in_array('add', $command, true)) {
                $this->complete();
            }
        };
        self::assertSame(
            'installed',
            $installer->run('/project', expectedFingerprint: $preview['fingerprint'])['state'],
        );
        self::assertSame($preview['commands'], $this->commands);
        self::assertContains('--no-plugins', $this->commands[0]);
        self::assertSame(VerifiedSkillManagerRuntime::COMMAND, $this->commands[1][0]);
        self::assertSame('required', $preview['plugin_approval']);
        self::assertSame('llm/skills:^1.15', array_last($this->commands[0]));
        self::assertContains('--skill=fast-forward-changelog', $this->commands[1]);
        self::assertStringContainsString('owner-script', $this->bytes['composer.json']);
        self::assertArrayNotHasKey('.agents/skills/fast-forward-changelog/.fast-forward-install.json', $this->bytes);
        self::assertSame(1, $this->locks);
        self::assertSame(1, $this->releases);
        $before = [$this->bytes, $this->writes, $this->commands];
        self::assertSame('current', $installer->run('/project')['state']);
        self::assertSame($before, [$this->bytes, $this->writes, $this->commands]);
        self::assertSame(1, $this->locks);
    }

    /** The reviewed fingerprint binds exact metadata, project identity and structured commands before lock acquisition. */
    public function testChangedMetadataAndRootInvalidateConfirmation(): void
    {
        $installer = $this->compose();
        $preview = $installer->run('/project', true);
        $this->bytes['composer.lock'] = '{}';
        self::assertSame('blocked', $installer->run('/project', expectedFingerprint: $preview['fingerprint'])['state']);
        self::assertSame(
            'blocked',
            $installer->run('/different', expectedFingerprint: $preview['fingerprint'])['state'],
        );
        self::assertSame(0, $this->locks);
        self::assertSame([], $this->writes);
    }

    /** Lock contention cannot write metadata or start Composer. */
    public function testConcurrentInstallerIsReportedWithoutEffects(): void
    {
        $this->acquire = false;
        self::assertSame('blocked', $this->compose()->run('/project')['state']);
        self::assertSame([], $this->writes);
        self::assertSame([], $this->commands);
        self::assertSame(0, $this->releases);
    }

    /** Metadata is rechecked under the setup lock so changes between planning and application cannot slip through. */
    public function testChangeUnderLockBlocksEveryWriteAndReleasesLock(): void
    {
        $this->onAcquire = function (): void {
            $this->bytes['composer.lock'] = 'changed';
        };
        try {
            $this->compose()->run('/project');
            self::fail('Changed metadata must prevent application.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('changed before application', $failure->getMessage());
        }
        self::assertSame([], $this->writes);
        self::assertSame([], $this->commands);
        self::assertSame(1, $this->releases);
    }

    /** A failed dependency manager retains completed metadata honestly, does not leak stderr, and releases the lock. */
    public function testComposerFailureReportsRetainedChangesWithoutClaimingRollback(): void
    {
        $this->exit = 37;
        try {
            $this->compose()->run('/project');
            self::fail('Failed Composer must not report installation.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('exit 37', $failure->getMessage());
            self::assertStringContainsString('retained', $failure->getMessage());
            self::assertStringNotContainsString('synthetic-secret', $failure->getMessage());
        }
        self::assertNotEmpty($this->writes);
        self::assertCount(1, $this->commands);
        self::assertSame(1, $this->releases);
    }

    /** Successful process exit alone cannot prove the requested source and payload were installed. */
    public function testMissingManagerResultCannotReportSuccess(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not leave the selected skill');
        $this->compose()->run('/project');
    }

    /** Default setup requires a project manifest and never invents an application or claims native ownership. */
    public function testMissingComposerManifestIsBlocked(): void
    {
        $this->bytes = [];
        $result = $this->compose()->run('/project');
        self::assertSame('blocked', $result['state']);
        self::assertStringContainsString('existing composer.json', $result['diagnostics'][0]);
        self::assertSame([], $this->writes);
    }

    /** A new external manager preset must never shadow active inline settings, even unknown future owner fields. */
    public function testInlineManagerSettingsRequireExplicitMigration(): void
    {
        $this->bytes['composer.json'] = '{"extra":{"skills":{"target":"owner/skills","sources":[]}}}';
        $before = $this->bytes;
        $result = $this->compose()->run('/project');

        self::assertSame('blocked', $result['state']);
        self::assertStringContainsString('inline extra.skills', $result['diagnostics'][0]);
        self::assertSame($before, $this->bytes);
        self::assertSame([], $result['changes']);
    }

    /** An outer target anchor must be rejected before ownership checks can observe the wrong directory. */
    public function testPathFromRootBlocksBeforeAnyWriteOrProcess(): void
    {
        $this->bytes['skills.json'] = '{"path-from-root":"project","target":".agents/skills"}';
        $before = $this->bytes;
        $result = $this->compose()->run('/project');

        self::assertSame('blocked', $result['state']);
        self::assertStringContainsString('target anchor', $result['diagnostics'][0]);
        self::assertSame($before, $this->bytes);
        self::assertSame([], $this->commands);
        self::assertSame(0, $this->locks);
    }

    /** Forged manager metadata and root autoload maps never select consumer PHP for the skill operation. */
    public function testContributorControlledPackageAndAutoloadCannotReceiveExecutionOrApproval(): void
    {
        $this->ready();
        $this->bytes['composer.json'] = '{"require-dev":{"llm/skills":"^1.15"},"repositories":[{"type":"package","package":{"name":"llm/skills","version":"1.15.0","dist":{"type":"path","url":"payload"}}}],"autoload":{"psr-4":{"LLM\\\\Skills\\\\":"payload/"},"classmap":["payload/"]},"config":{"allow-plugins":{"owner/plugin":true}}}';
        $before = $this->bytes['composer.json'];
        unset($this->bytes[self::SKILL_PATH]);
        $this->onProcess = function (array $command): void {
            self::assertSame(VerifiedSkillManagerRuntime::COMMAND, $command[0]);
            $this->bytes[self::SKILL_PATH] = '# Verified manager output';
        };
        $result = $this->compose()->run('/project');

        self::assertSame('installed', $result['state']);
        self::assertSame('required', $result['plugin_approval']);
        self::assertSame($before, $this->bytes['composer.json']);
        self::assertCount(1, $this->commands);
        self::assertSame(VerifiedSkillManagerRuntime::COMMAND, $this->commands[0][0]);
        self::assertStringContainsString('No plugin approval was added', implode("\n", $result['diagnostics']));
    }

    /** Donor-only Composer metadata does not become an application manager configuration or prevent setup. */
    public function testDonorSourceMetadataIsPreservedWithoutBlockingSetup(): void
    {
        $this->bytes['composer.json'] = '{"extra":{"skills":{"source":"resources/skills"}}}';

        self::assertSame('planned', $this->compose()->run('/project', true)['state']);
        self::assertSame([], $this->writes);
    }

    /** Existing native receipts and unknown payloads are preserved even if a donor was already configured. */
    #[DataProvider('ownershipConflicts')]
    public function testForeignAndNativeOwnershipAreBlocked(array $files): void
    {
        $this->bytes = [...$this->bytes, ...$files];
        $before = $this->bytes;
        self::assertSame('blocked', $this->compose()->run('/project')['state']);
        self::assertSame($before, $this->bytes);
        self::assertSame(0, $this->locks);
    }

    /** Supplies explicit NPX ownership, unknown lock formats and native or unowned installed payloads. */
    public static function ownershipConflicts(): iterable
    {
        yield [['skills-lock.json' => '{"skills":{"fast-forward-changelog":{}}}']];
        yield [['skills-lock.json' => '{}']];
        yield [[self::SKILL_PATH => '# Local edits']];
        yield [['.agents/skills/fast-forward-changelog/.fast-forward-install.json' => '{}']];
        yield [[self::SKILL_PATH => '# Existing', 'skills.json' => '{"sources":[{"from":"github","package":"php-fast-forward/changelog","skills":["other"]}]}']];
    }

    /** Explicit plugin grants, including Composer's first matching wildcard, are honored without policy replacement. */
    #[DataProvider('pluginPolicies')]
    public function testExistingPluginPolicyRemainsAuthoritative(mixed $policy, bool $permitted): void
    {
        $this->bytes['composer.json'] = json_encode(['config' => ['allow-plugins' => $policy]]);
        $result = $this->compose()->run('/project', true);
        self::assertSame($permitted ? 'planned' : 'blocked', $result['state']);
        self::assertNotContains('composer.json', $result['changes']);
        self::assertSame([], $this->writes);
    }

    /** Covers exact grants, broad policies and ordered wildcard refusal with no implicit escalation. */
    public static function pluginPolicies(): iterable
    {
        yield [true, true];
        yield [false, false];
        yield [['llm/skills' => true], true];
        yield [['llm/skills' => false], false];
        yield [['llm/*' => true], true];
        yield [['*' => false, 'llm/skills' => true], false];
        yield [['llm/skills' => true, '*' => false], true];
    }

    /** Existing manager settings and constraints stay byte-identical; only a scoped manager sync is delegated. */
    public function testExistingManagerConfigUsesScopedUpdateAndPreservesCustomTarget(): void
    {
        $this->ready();
        $config = json_decode($this->bytes['skills.json'], true);
        $config['target'] = 'custom/skills/';
        $config['aliases'] = ['.claude/skills'];
        $config['owner'] = ['extra' => []];
        $this->bytes['skills.json'] = json_encode($config);
        unset($this->bytes[self::SKILL_PATH]);
        $before = $this->bytes['skills.json'];
        $this->onProcess = function (array $command): void {
            self::assertContains('update', $command);
            self::assertContains('fast-forward/changelog', $command);
            $this->bytes['custom/skills/fast-forward-changelog/SKILL.md'] = '# Managed';
        };
        $plan = $this->compose()->run('/project');
        self::assertSame('installed', $plan['state']);
        self::assertSame('custom/skills', $plan['target']);
        self::assertSame($before, $this->bytes['skills.json']);
        self::assertSame([], $plan['changes']);
        self::assertCount(1, $this->commands);
    }

    /** A configured dependency absent from vendor is installed using its existing Composer constraint. */
    public function testDeclaredButUninstalledPluginUsesInstallInsteadOfRequire(): void
    {
        $this->ready();
        unset($this->bytes['vendor/composer/installed.json'], $this->bytes[self::SKILL_PATH]);
        $plan = $this->compose()->run('/project', true);
        self::assertContains('install', $plan['commands'][0]);
        self::assertNotContains('require', $plan['commands'][0]);
        self::assertContains('update', $plan['commands'][1]);
    }

    /** An existing generated skill cannot hide a removed Composer requirement or missing local plugin installation. */
    public function testGeneratedPayloadAloneDoesNotProveManagerSetup(): void
    {
        $this->ready();
        unset($this->bytes['vendor/composer/installed.json']);
        $this->bytes['composer.json'] = '{"name":"vendor/project"}';
        $plan = $this->compose()->run('/project', true);

        self::assertSame('planned', $plan['state']);
        self::assertContains('require', $plan['commands'][0]);
        self::assertContains('update', $plan['commands'][1]);
        self::assertSame([], $this->commands);
    }

    /** Revoking the plugin grant is honored even when the last generated payload remains on disk. */
    public function testGeneratedPayloadCannotOverrideAnExplicitPluginDenial(): void
    {
        $this->ready();
        $this->bytes['composer.json'] = '{"require-dev":{"llm/skills":"^1.15"},"config":{"allow-plugins":false}}';

        self::assertSame('blocked', $this->compose()->run('/project')['state']);
        self::assertSame([], $this->writes);
        self::assertSame([], $this->commands);
    }

    /** A custom project-relative vendor directory remains authoritative for local plugin discovery. */
    public function testCustomComposerVendorDirectoryIsObservedWithoutReinstalling(): void
    {
        $this->ready();
        $this->bytes['composer.json'] = '{"require":{"llm/skills":"^1.15"},"config":{"allow-plugins":{"llm/skills":true},"vendor-dir":"tools/vendor"}}';
        $this->bytes['tools/vendor/composer/installed.json'] = $this->bytes['vendor/composer/installed.json'];
        unset($this->bytes['vendor/composer/installed.json']);

        self::assertSame('current', $this->compose()->run('/project')['state']);
        self::assertSame([], $this->commands);
        self::assertSame([], $this->writes);
    }

    /** An old locked or installed manager is never silently upgraded or allowed to receive an unsupported preset. */
    #[DataProvider('unsupportedManagers')]
    public function testExistingUnqualifiedManagerRequiresReviewBeforeWrites(string $path, string $version): void
    {
        $this->ready();
        $this->bytes[$path] = '{"packages-dev":[{"name":"llm/skills","version":' . $version . '}],"packages":[]}';
        try {
            $this->compose()->run('/project');
            self::fail('Unqualified manager must require review.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('stable 1.x from 1.15.0', $failure->getMessage());
        }
        self::assertSame([], $this->writes);
        self::assertSame([], $this->commands);
    }

    /** Covers old releases, development branches and unverifiable package metadata from either local source. */
    public static function unsupportedManagers(): iterable
    {
        yield ['vendor/composer/installed.json', '"1.10.0"'];
        yield ['vendor/composer/installed.json', '"dev-master"'];
        yield ['vendor/composer/installed.json', 'null'];
        yield ['composer.lock', '"v1.14.0"'];
        yield ['composer.lock', '"2.0.0"'];
    }

    /** A missing lock/vendor cannot turn a broad or unknown existing constraint into unreviewed dependency changes. */
    #[DataProvider('unprovenConstraints')]
    public function testUnprovenUninstalledRequirementDoesNotWrite(mixed $constraint): void
    {
        $this->bytes['composer.json'] = json_encode(['require-dev' => ['llm/skills' => $constraint]]);
        try {
            $this->compose()->run('/project');
            self::fail('Unproven manager constraint must require owner review.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('constraint cannot be verified', $failure->getMessage());
        }
        self::assertSame([], $this->writes);
        self::assertSame([], $this->commands);
    }

    /** Broad ranges, another major and unsupported expression syntax cannot prove the verified minimum. */
    public static function unprovenConstraints(): iterable
    {
        yield ['^1.0'];
        yield ['^2.0'];
        yield ['>=1.15'];
        yield ['^1.15 || ^2'];
        yield [false];
    }

    /** Existing manager setup can receive an absent exact local grant without rerunning dependency resolution. */
    public function testExistingPayloadNeedsOnlyMissingLocalPluginGrant(): void
    {
        $this->ready();
        $this->bytes['composer.json'] = '{"require-dev":{"llm/skills":"^1.15"}}';
        $result = $this->compose()->run('/project');

        self::assertSame('current', $result['state']);
        self::assertSame([], $result['changes']);
        self::assertSame('required', $result['plugin_approval']);
        self::assertSame([], $this->commands);
    }

    /** Adding only this skill to an existing donor preserves its exact ref and delegates allowlist merging. */
    public function testExistingAllowlistReceivesOnlyTheSelectedSkillAtItsExistingRef(): void
    {
        $this->bytes['skills.json'] = '{"sources":[{"from":"github","package":"php-fast-forward/changelog","host":"github.com","ref":"v0.3.0","skills":["other"]}]}';
        $this->bytes['skills-lock.json'] = '{"skills":{"unrelated":{}}}';
        $plan = $this->compose()->run('/project', true);
        self::assertContains('--ref=v0.3.0', $plan['commands'][1]);
        self::assertContains('--host=github.com', $plan['commands'][1]);
        self::assertContains('--skill=fast-forward-changelog', $plan['commands'][1]);
        self::assertNotContains('skills.json', $plan['changes']);
    }

    /** Manager registration can select every donor skill while installed metadata may use Composer's legacy array. */
    public function testUnrestrictedSourceAndLegacyInstalledMetadataAreRecognized(): void
    {
        $this->ready();
        $this->bytes['skills.json'] = '{"sources":[{"from":"github","package":"other/repo"},{"from":"github","package":"php-fast-forward/changelog"}]}';
        $this->bytes['vendor/composer/installed.json'] = '[false,{"name":"other/plugin"},{"name":"llm/skills","version_normalized":"1.15.0.0"}]';
        self::assertSame('current', $this->compose()->run('/project')['state']);
    }

    /** Ignore entries retain their original newline convention and unrelated bytes. */
    public function testExistingMetadataNewlinesAndOwnerValuesArePreserved(): void
    {
        $this->bytes['.gitignore'] = "vendor/\r\n";
        $this->bytes['.gitattributes'] = '* text=auto';
        $this->bytes['composer.json'] = "{\r\n\"description\":\"Instruções\",\"require\": {}, \"extra\": {\"empty\":{},\"list\":[],\"enabled\":false}\r\n}";
        $this->onProcess = function (array $command): void {
            if (in_array('add', $command, true)) {
                $this->complete();
            }
        };
        $this->compose()->run('/project');
        self::assertSame("vendor/\r\n/.agents/\r\n", $this->bytes['.gitignore']);
        self::assertSame("* text=auto\n/.agents/** export-ignore\n", $this->bytes['.gitattributes']);
        self::assertArrayNotHasKey('composer.json', $this->writes);
        $extra = json_decode($this->bytes['composer.json'])->extra;
        self::assertInstanceOf(\stdClass::class, $extra->empty);
        self::assertSame([], $extra->list);
        self::assertFalse($extra->enabled);
    }

    /** Invalid owner configuration fails during preview before any command, lock or write. */
    #[DataProvider('invalidMetadata')]
    public function testMalformedMetadataNeverStartsApplication(array $files, string $message): void
    {
        $this->bytes = [...$this->bytes, ...$files];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        $this->compose()->run('/project');
    }

    /** Covers invalid JSON, policy shapes, donor ambiguity and source constraints without calling Composer. */
    public static function invalidMetadata(): iterable
    {
        yield [['skills-lock.json' => '{'], 'invalid JSON'];
        yield [['composer.json' => '[]'], 'JSON object'];
        yield [['skills.json' => '{"target":false}'], 'target'];
        yield [['skills.json' => '{"sources":{}}'], 'sources'];
        yield [['skills.json' => '{"remote":[]}'], 'remote source alias'];
        yield [['skills.json' => '{"sources":[false]}'], 'entries'];
        $donor = '{"from":"github","package":"php-fast-forward/changelog","skills":[]}';
        yield [['skills.json' => '{"sources":[' . $donor . ',' . $donor . ']}'], 'Multiple'];
        yield [['skills.json' => '{"sources":[' . $donor . ']}'], 'explicit ref'];
        yield [['skills.json' => '{"sources":[{"from":"github","package":"php-fast-forward/changelog","skills":false}]}'], 'allowlist'];
        yield [['skills.json' => '{"sources":[{"from":"github","package":"php-fast-forward/changelog","host":"https://github.com"}]}'], 'noncanonical host'];
        yield [['composer.json' => '{"config":false}'], 'config must'];
        yield [['composer.json' => '{"config":{"allow-plugins":[]}}'], 'allow-plugins'];
        yield [['composer.json' => '{"config":{"vendor-dir":false}}'], 'vendor-dir'];
        yield [['vendor/composer/installed.json' => 'false'], 'package list'];
        yield [['vendor/composer/installed.json' => '{"packages":false}'], 'packages must'];
        yield [['composer.lock' => '{"packages":[],"packages-dev":false}'], 'packages must'];
    }

    /** Builds a fully manager-owned local state with no intended writes or dependency operations. */
    private function ready(): void
    {
        $this->bytes['composer.json'] = '{"require-dev":{"llm/skills":"^1.15"},"config":{"allow-plugins":{"llm/skills":true}}}';
        $this->bytes['skills.json'] = '{"sources":[{"from":"github","package":"php-fast-forward/changelog","ref":"^0.3.0","skills":["fast-forward-changelog"]}]}';
        $this->bytes['vendor/composer/installed.json'] = '{"packages":[{"name":"llm/skills","version":"1.15.0"}]}';
        $this->bytes[self::SKILL_PATH] = '# Managed';
        $this->bytes['.gitignore'] = "/.agents/\n";
        $this->bytes['.gitattributes'] = "/.agents/** export-ignore\n";
    }

    /** Simulates only the manager's observed success; no package source is copied by production code or test IO. */
    private function complete(): void
    {
        $config = json_decode($this->bytes['skills.json'], true);
        $config['sources'][] = ['from' => 'github', 'package' => 'php-fast-forward/changelog', 'ref' => '^0.3.0', 'skills' => [
            'fast-forward-changelog',
        ]];
        $this->bytes['skills.json'] = json_encode($config);
        $composer = json_decode($this->bytes['composer.json']);
        $composer->{'require-dev'} ??= new \stdClass();
        $composer->{'require-dev'}->{'llm/skills'} = '^1.15';
        $this->bytes['composer.json'] = json_encode($composer);
        $this->bytes['vendor/composer/installed.json'] = '{"packages":[{"name":"llm/skills","version":"1.15.0"}]}';
        $this->bytes[self::SKILL_PATH] = '# Managed';
    }

    /** Injects every filesystem, lock and process boundary, keeping tests independent from HOME and the network. */
    private function compose(): ComposerSkillInstaller
    {
        $filesystem = $this->createStub(InstallationFilesystemInterface::class);
        $filesystem->method('read')->willReturnCallback(
            fn(string $root, string $path): ?string => $this->bytes[$path] ?? null,
        );
        $files = $this->createStub(ManagedFileStoreInterface::class);
        $files->method('write')->willReturnCallback(function (string $path, string $bytes): void {
            $relative = substr($path, strlen('/project/'));
            $this->writes[$relative] = $this->bytes[$relative] = $bytes;
        });
        $processes = $this->createStub(ProcessFactoryInterface::class);
        $processes->method('create')->willReturnCallback(function (array $command): Process {
            self::assertSame('composer', $command[0]);
            self::assertContains('--no-plugins', $command);
            self::assertContains('--no-scripts', $command);
            $process = $this->createMock(Process::class);
            $process->expects(self::once())->method('setWorkingDirectory')->with('/project');
            $process->method('getEnv')->willReturn(
                ['GH_TOKEN' => false, 'COMPOSER' => 'foreign.json', 'COMPOSER_VENDOR_DIR' => '/foreign'],
            );
            $process->expects(self::once())->method('setEnv')->with([
                'GH_TOKEN' => false, 'COMPOSER' => 'composer.json', 'COMPOSER_VENDOR_DIR' => 'vendor',
            ]);
            $process->expects(self::once())->method('setTimeout')->with(300);
            $process->expects(self::once())->method('run')->willReturnCallback(function () use ($command): int {
                $this->commands[] = $command;
                ($this->onProcess ?? static fn() => null)($command);

                return $this->exit;
            });
            $process->method('getExitCode')->willReturn($this->exit);
            $process->expects(self::never())->method('getErrorOutput');

            return $process;
        });
        $locks = $this->createStub(LockFactory::class);
        $locks->method('createLock')->willReturnCallback(function (string $key): SharedLockInterface {
            self::assertSame('fast-forward/changelog:project-skills:' . hash('sha256', '/project'), $key);
            ++$this->locks;
            $lock = $this->createStub(SharedLockInterface::class);
            $lock->method('acquire')->willReturnCallback(function (): bool {
                ($this->onAcquire ?? static fn() => null)();

                return $this->acquire;
            });
            $lock->method('release')->willReturnCallback(function (): void {
                ++$this->releases;
            });

            return $lock;
        });

        $runtime = $this->createStub(SkillManagerRuntimeInterface::class);
        $runtime->method('run')->willReturnCallback(function (string $root, string $vendor, array $arguments): void {
            self::assertSame('/project', $root);
            $command = [VerifiedSkillManagerRuntime::COMMAND, ...$arguments];
            $this->commands[] = $command;
            ($this->onProcess ?? static fn() => null)($command);
        });

        return new ComposerSkillInstaller($filesystem, $files, $processes, $locks, $runtime);
    }
}
