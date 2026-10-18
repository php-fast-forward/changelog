<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Integration;

use FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider;
use FastForward\Changelog\Skill\SkillInstaller;
use FastForward\Changelog\Skill\SkillInstallerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function FastForward\Config\config;
use function FastForward\Container\container;

/** Runs real local file/CLI transactions exclusively inside disposable directories. */
#[CoversNothing]
final class SkillInstallationTest extends TestCase
{
    public function testRealCwdPortabilityGlobalOptInAndCompletePayload(): void
    {
        $filesystem = new Filesystem();
        $root = realpath(sys_get_temp_dir()) . '/changelog-native-skill-' . bin2hex(random_bytes(8));
        $filesystem->mkdir([$root . '/consumer', $root . '/fake-home'], 0700);
        $binary = dirname(__DIR__, 2) . '/bin/changelog';
        $environment = ['HOME' => $root . '/fake-home', 'USERPROFILE' => $root . '/fake-home', 'CI' => '1',
            'GH_TOKEN' => false, 'GITHUB_TOKEN' => false, 'FF_CHANGELOG_TOKEN' => false];
        $cli = static function (array $arguments, int $exit = 0) use ($root, $binary, $environment): array {
            $process = new Process([
                PHP_BINARY,
                $binary,
                'skill:install',
                '--json',
                '--no-interaction',
                '--cwd=' . $root . '/consumer',
                ...$arguments,
            ], $root, $environment);
            $process->run();
            self::assertSame($exit, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame('', $process->getErrorOutput());

            return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        };
        try {
            self::assertSame('absent', $cli(['--dry-run'])['state']);
            self::assertFalse(is_dir($root . '/consumer/.agents'));
            self::assertSame('installed', $cli([])['state']);
            self::assertFalse(is_dir($root . '/fake-home/.agents'));
            $destination = $root . '/consumer/.agents/skills/' . SkillInstaller::NAME;
            $receiptBytes = file_get_contents($destination . '/' . SkillInstaller::RECEIPT);
            $receipt = json_decode($receiptBytes, true, flags: JSON_THROW_ON_ERROR);
            self::assertGreaterThanOrEqual(6, count($receipt['files']));
            self::assertStringNotContainsString($root, $receiptBytes);
            foreach ($receipt['files'] as $path => $hash) {
                self::assertSame($hash, hash_file('sha256', $destination . '/' . $path));
                self::assertSame(
                    file_get_contents(dirname(__DIR__, 2) . '/resources/skills/' . SkillInstaller::NAME . '/' . $path),
                    file_get_contents($destination . '/' . $path),
                );
            }
            self::assertSame('current', $cli(['--check'])['state']);
            self::assertSame('current', $cli([])['state']);
            self::assertSame($receiptBytes, file_get_contents($destination . '/' . SkillInstaller::RECEIPT));
            self::assertSame('installed', $cli(['--global'])['state']);
            self::assertFileExists($root . '/fake-home/.agents/skills/' . SkillInstaller::NAME . '/SKILL.md');
            $filesystem->rename($root . '/consumer', $root . '/moved');
            $installer = container(
                config([
                    'changelog' => ['working_directory' => $root . '/moved', 'skill_home_directory' => $root . '/fake-home'],
                ]),
                ChangelogServiceProvider::class,
            )->get(
                SkillInstallerInterface::class,
            );
            self::assertSame('current', $installer->run(mode: 'check')['state']);
            file_put_contents(
                $root . '/moved/.agents/skills/' . SkillInstaller::NAME . '/SKILL.md',
                'Local human edits',
            );
            self::assertSame('diverged', $installer->run(mode: 'update')['state']);
            self::assertSame(
                'Local human edits',
                file_get_contents($root . '/moved/.agents/skills/' . SkillInstaller::NAME . '/SKILL.md'),
            );
            self::assertFileDoesNotExist($root . '/moved/skills-lock.json');
            self::assertFileDoesNotExist($root . '/moved/.skills-lock.json');
        } finally {
            $filesystem->remove($root);
        }
    }

    public function testRealUpdateAndForeignCopyRefusalDoNotTouchOtherManagerState(): void
    {
        $filesystem = new Filesystem();
        $root = realpath(sys_get_temp_dir()) . '/changelog-native-update-' . bin2hex(random_bytes(8));
        $filesystem->mkdir([$root . '/consumer', $root . '/payload'], 0700);
        $filesystem->mirror(dirname(__DIR__, 2) . '/resources/skills/' . SkillInstaller::NAME, $root . '/payload');
        $service = static fn(string $version): SkillInstallerInterface => container(config(['changelog' => [
            'working_directory' => $root . '/consumer', 'skill_home_directory' => null,
            'skill_source_directory' => $root . '/payload', 'installed_version' => $version,
        ]]), ChangelogServiceProvider::class)->get(SkillInstallerInterface::class);
        try {
            self::assertSame('installed', $service('1.0.0')->run()['state']);
            $filesystem->appendToFile($root . '/payload/SKILL.md', "\nNew bundled instructions.\n");
            self::assertSame('outdated', $service('1.1.0')->run(mode: 'check')['state']);
            self::assertSame('updated', $service('1.1.0')->run(mode: 'update')['state']);
            self::assertSame('current', $service('1.1.1')->run(mode: 'update')['state']);
            $destination = $root . '/consumer/.agents/skills/' . SkillInstaller::NAME;
            $filesystem->remove($destination . '/' . SkillInstaller::RECEIPT);
            file_put_contents($root . '/consumer/skills-lock.json', 'Third-party manager state');
            self::assertSame('unmanaged', $service('2.0.0')->run(mode: 'update')['state']);
            self::assertSame('Third-party manager state', file_get_contents($root . '/consumer/skills-lock.json'));
        } finally {
            $filesystem->remove($root);
        }
    }
}
