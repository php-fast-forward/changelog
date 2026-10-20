<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Integration;

use FastForward\Changelog\Console\Changelog;
use FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider;
use FastForward\Changelog\Installation\WorkflowReleaseResolverInterface;
use FastForward\Changelog\Skill\SkillInstaller;
use FastForward\Container\ServiceProvider\ArrayServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

use function FastForward\Config\config;
use function FastForward\Container\container;

/** Rehearses native command composition in disposable Git repositories without real release discovery. */
#[CoversNothing]
final class ProjectInstallationTest extends TestCase
{
    /** Preview writes nothing; application creates exact YAML and skill, and a later invocation preserves both. */
    public function testProjectSetupPreservesExistingConfigurationAndInstallerOwnership(): void
    {
        $filesystem = new Filesystem();
        $root = realpath(sys_get_temp_dir()) . '/changelog-project-install-' . bin2hex(random_bytes(8));
        $filesystem->mkdir($root, 0700);
        $environment = ['HOME' => $root, 'USERPROFILE' => $root, 'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null', 'GH_TOKEN' => false, 'GITHUB_TOKEN' => false];
        $git = new Process(['git', 'init', '--initial-branch=main', $root], $root, $environment);
        $git->mustRun();
        $runtime = dirname(__DIR__, 2);
        $resolver = $this->createStub(WorkflowReleaseResolverInterface::class);
        $resolver->method('resolve')->willReturn([
            'ref' => 'v9.9.9', 'sha' => str_repeat('a', 40),
            'caller' => file_get_contents($runtime . '/resources/workflows/changelog.yml'),
            'inputs' => Yaml::parseFile($runtime . '/.github/workflows/changelog.yml')['on']['workflow_call']['inputs'],
        ]);
        $services = container(config(['changelog' => ['working_directory' => $root,
            'skill_home_directory' => $root . '/fake-home', 'installed_version' => '9.9.9',
            'skill_warning_enabled' => false]]), new ArrayServiceProvider([
                WorkflowReleaseResolverInterface::class => static fn() => $resolver,
            ]), ChangelogServiceProvider::class);
        $app = $services->get(Changelog::class);
        $app->setAutoExit(false);
        $cli = new ApplicationTester($app);
        $run = static function (array $options) use ($cli, $root): array {
            self::assertSame(0, $cli->run(['command' => 'install', '--cwd' => $root, '--json' => true,
                '--ref' => 'v9.9.9', ...$options], ['interactive' => false]), $cli->getDisplay());

            return json_decode($cli->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        };
        try {
            self::assertSame('planned', $run(['--dry-run' => true])['state']);
            self::assertDirectoryDoesNotExist($root . '/.github');
            self::assertDirectoryDoesNotExist($root . '/.agents');
            $created = $run(['--yes' => true]);
            self::assertSame('installed', $created['workflow']['state']);
            self::assertSame('v9.9.9', $created['workflow']['reference']);
            self::assertSame('installed', $created['skill']['state']);
            self::assertSame('9.9.9', $created['skill']['installed_source']['version']);
            $workflow = $root . '/.github/workflows/changelog.yml';
            $payload = file_get_contents($workflow);
            $parsed = Yaml::parse($payload);
            self::assertSame(
                'php-fast-forward/changelog/.github/workflows/changelog.yml@' . str_repeat('a', 40),
                $parsed['jobs']['changelog']['uses'],
            );
            self::assertStringContainsString('# v9.9.9', $payload);
            self::assertTrue($parsed['jobs']['changelog']['with']['publish']);
            self::assertSame(['main'], $parsed['on']['push']['branches']);
            self::assertStringContainsString("\non:\n", $payload);
            self::assertStringNotContainsString('---', $payload);
            self::assertFileDoesNotExist($root . '/skills-lock.json');
            self::assertDirectoryDoesNotExist($root . '/fake-home');
            $receipt = $root . '/.agents/skills/' . SkillInstaller::NAME . '/' . SkillInstaller::RECEIPT;
            $receiptBytes = file_get_contents($receipt);
            $repeat = $run(['--yes' => true, '--publish' => 'false', '--base-branch' => 'other']);
            self::assertSame('existing', $repeat['workflow']['state']);
            self::assertSame('current', $repeat['skill']['state']);
            self::assertSame($payload, file_get_contents($workflow));
            self::assertSame($receiptBytes, file_get_contents($receipt));
            self::assertSame([], glob($root . '/.github/workflows/.fast-forward-install-*'));
            $filesystem->remove($workflow);
            $components = [];
            foreach (['check', 'publish'] as $name) {
                $path = $root . '/.github/workflows/' . $name . '.yml';
                $components[$path] = Yaml::dump(['name' => $name, 'on' => ['workflow_dispatch' => null],
                    'jobs' => [$name => ['uses' => 'php-fast-forward/changelog/.github/workflows/changelog-'
                        . $name . '.yml@v9.9.9']]]);
                $filesystem->dumpFile($path, $components[$path]);
            }
            $modular = $run(['--yes' => true]);
            self::assertSame('existing', $modular['workflow']['state']);
            self::assertSame('current', $modular['skill']['state']);
            self::assertFileDoesNotExist($workflow);
            foreach ($components as $path => $bytes) {
                self::assertSame($bytes, file_get_contents($path));
            }
        } finally {
            $filesystem->remove($root);
        }
    }
}
