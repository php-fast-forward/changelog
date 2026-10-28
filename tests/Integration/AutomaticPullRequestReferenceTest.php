<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Integration;

use FastForward\Changelog\Console\Changelog;
use FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Publication\PublicationServiceInterface;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Release\ReleaseApplierInterface;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use FastForward\Container\Factory\ServiceFactory;
use Interop\Container\ServiceProviderInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Throwable;

use function FastForward\Config\config;
use function FastForward\Container\container;

/** Exercises real CLI, container, Git, consolidation and publication reconstruction with synthetic API evidence. */
#[CoversNothing]
#[Group('windows-references')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AutomaticPullRequestReferenceTest extends TestCase
{
    use RecordsIntegrationProgress;

    /** Approved references cannot be added, removed or guessed when association evidence later changes. */
    #[TestWith([true, false])]
    #[TestWith([false, false])]
    #[TestWith([true, true])]
    public function testAutomaticReferencesSurviveRecoveryAndAreReprovedBeforePublication(
        bool $associated,
        bool $stacked,
    ): void {
        $root = realpath(sys_get_temp_dir()) . '/changelog-auto-pr-' . bin2hex(random_bytes(8));
        $fs = new Filesystem();
        $fs->mkdir($root, 0700);
        if ($stacked) {
            // Reproduce a host override that would rewrite fixture bytes during checkout on any platform.
            foreach ([
                'GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => 'core.autocrlf', 'GIT_CONFIG_VALUE_0' => 'true',
            ] as $name => $value) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
        // This isolated PHP process must not inherit host Git routing, filters or configuration overrides.
        foreach (array_unique([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]) as $name) {
            if (str_starts_with(strtoupper($name), 'GIT_')) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            }
        }
        foreach ([
            'HOME' => $root, 'XDG_CONFIG_HOME' => $root . '/xdg',
            'GIT_CONFIG_GLOBAL' => $root . '/empty', 'GIT_CONFIG_NOSYSTEM' => '1',
        ] as $name => $value) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        file_put_contents($root . '/empty', '');
        $git = static fn(array $args): string => new Process(
            ['git', ...$args],
            $root,
            ['GH_TOKEN' => false, 'GITHUB_TOKEN' => false, 'FF_CHANGELOG_TOKEN' => false, 'GIT_TERMINAL_PROMPT' => '0'],
        )->mustRun()->getOutput();
        try {
            $git(['init', '--initial-branch=main']);
            $git(['config', 'user.name', 'Synthetic fixture']);
            $git(['config', 'user.email', 'fixture@example.invalid']);
            $history = "# Changelog\n\n## [1.0.0] - 2026-10-01\n\nOriginal notes.\n\n"
                . "[1.0.0]: https://example.invalid/retained\n";
            file_put_contents($root . '/CHANGELOG.md', $history);
            $git(['add', 'CHANGELOG.md']);
            $git(['commit', '-m', 'Initial fixture']);
            $git(['tag', 'v1.0.0']);
            if ($stacked) {
                $git(['switch', '-c', 'umbrella']);
                $git(['commit', '--allow-empty', '-m', 'Root integration work']);
                $git(['switch', '-c', 'implementation']);
            }
            $fs->mkdir($root . '/.changelog');
            $fragment = "---\ncategory: fixed\ntype: patch\n---\n\nKeeps the authored fragment intact.\n";
            file_put_contents($root . '/.changelog/auto-pr.md', $fragment);
            $git(['add', '.changelog/auto-pr.md']);
            $git(['commit', '-m', 'A title does not supply provenance #999']);
            $base = trim($git(['rev-parse', 'HEAD']));
            $implementation = $base;
            if ($stacked) {
                $git(['switch', 'umbrella']);
                $git(['merge', '--no-ff', 'implementation', '-m', 'Import a nested implementation']);
                $git(['switch', 'main']);
                $git(['merge', '--no-ff', 'umbrella', '-m', 'Integrate stack']);
                $base = trim($git(['rev-parse', 'HEAD']));
                self::assertSame($fragment, file_get_contents($root . '/.changelog/auto-pr.md'),
                    'Fixture checkouts must retain exact authored bytes independently of host line-ending settings.');
            }
            $github = new class ($base, $associated, $fragment) implements GitHubClientInterface {
                public array $numbers;
                public bool $unavailable = false;
                public ?string $head = null;
                public bool $incompleteFiles = false;
                public array $calls = [];

                public function __construct(
                    private readonly string $origin,
                    bool $associated,
                    private readonly string $fragment,
                ) {
                    $this->numbers = $associated ? [41] : [];
                }

                public function request(string $method, string $path, ?array $body = null): ?array
                {
                    $this->calls[] = [$method, $path];
                    if ($this->unavailable) {
                        throw new RuntimeException('Synthetic API outage');
                    }
                    $repo = ['id' => 42, 'node_id' => 'opaque-repo', 'full_name' => 'owner/fixture'];
                    if ('POST' === $method && '/graphql' === $path) {
                        return ['data' => ['repository' => [
                            'nameWithOwner' => $repo['full_name'], 'id' => $repo['node_id'],
                            'pullRequest' => [
                                'number' => $body['variables']['number'], 'state' => 'MERGED', 'merged' => true,
                                'headRefOid' => $this->head ?? $this->origin, 'headRefName' => 'contribution', 'baseRefName' => 'main',
                                'mergeCommit' => ['oid' => $this->origin],
                            ],
                        ]]];
                    }
                    if ('GET' !== $method) {
                        throw new RuntimeException('The fixture must never publish a remote object.');
                    }
                    if ('/repos/owner/fixture' === $path) {
                        return $repo;
                    }
                    if (preg_match('~/pulls/([0-9]+)$~D', $path, $match)) {
                        return ['number' => (int) $match[1], 'state' => 'closed', 'merged' => true, 'changed_files' => 1, 'commits' => 1,
                            'head' => ['sha' => $this->head ?? $this->origin, 'ref' => 'contribution'],
                            'base' => ['ref' => 'main', 'repo' => $repo]];
                    }

                    return null;
                }

                public function paginate(string $path): array
                {
                    $this->calls[] = ['GET', $path];
                    if (! $this->unavailable && str_ends_with($path, '/files')) {
                        return $this->incompleteFiles ? [] : [['filename' => '.changelog/auto-pr.md', 'status' => 'added',
                            'sha' => hash('sha1', 'blob ' . strlen($this->fragment) . "\0" . $this->fragment)]];
                    }
                    if (! $this->unavailable && str_ends_with($path, '/commits')) {
                        return [['sha' => $this->head ?? $this->origin]];
                    }
                    if ($this->unavailable || $path !== '/repos/owner/fixture/commits/' . $this->origin . '/pulls') {
                        throw new RuntimeException('Synthetic association evidence unavailable.');
                    }

                    return array_map(static fn(int $number): array => ['number' => $number], $this->numbers);
                }
            };
            $bindings = new readonly class ($github) implements ServiceProviderInterface {
                public function __construct(
                    private GitHubClientInterface $github,
                ) {}
                public function getFactories(): array
                {
                    return [GitHubClientInterface::class => new ServiceFactory($this->github)];
                }
                public function getExtensions(): array
                {
                    return [];
                }
            };
            $runtime = container(
                config(['changelog' => ['working_directory' => $root]]),
                $bindings,
                ChangelogServiceProvider::class,
            );
            $application = $runtime->get(Changelog::class);
            $application->setAutoExit(false);
            $tester = new ApplicationTester($application);
            if ($stacked) {
                self::assertSame(1, $tester->run([
                    'command' => 'status', '--cwd' => $root, '--repository' => 'owner/fixture',
                    '--source' => 'tags', '--resolve-pull-requests' => 'true', '--no-interaction' => true,
                ]));
                self::assertStringContainsString('explicit pull_request', $tester->getDisplay());
                self::assertSame($history, file_get_contents($root . '/CHANGELOG.md'));
                self::assertSame($fragment, file_get_contents($root . '/.changelog/auto-pr.md'));
                // Complete association evidence for the actual implementation can still be proved at the shared merge.
                $github->head = $implementation;
            }
            self::assertSame(0, $tester->run([
                'command' => 'status', '--cwd' => $root, '--repository' => 'owner/fixture',
                '--source' => 'tags', '--resolve-pull-requests' => 'true', '--no-interaction' => true,
            ]));
            $summary = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            $suffix = $associated ? ' ([#41](https://github.com/owner/fixture/pull/41))' : '';
            self::assertStringContainsString('Keeps the authored fragment intact.' . $suffix, $summary['notes']);
            self::assertStringNotContainsString('#999', $summary['notes']);
            self::assertStringNotContainsString('/commit/', $summary['notes']);
            self::assertSame($fragment, file_get_contents($root . '/.changelog/auto-pr.md'));
            self::assertSame($history, file_get_contents($root . '/CHANGELOG.md'));
            $options = $runtime->get(ReleaseOptionsFactoryInterface::class)->create([
                'workingDirectory' => $root, 'repository' => 'owner/fixture', 'source' => 'tags', 'resolvePullRequests' => true,
            ]);
            $planner = $runtime->get(ReleasePlannerInterface::class);
            $plan = $planner->plan($options);
            self::assertSame($summary['notes'], $plan->notes);
            self::assertStringContainsString(
                '[1.0.1]: https://github.com/owner/fixture/compare/v1.0.0...v1.0.1',
                $plan->changelogContents,
            );
            $fs->mkdir(dirname($plan->receiptPath));
            file_put_contents($plan->receiptPath, $plan->receiptContents);
            $beforeCalls = count($github->calls);
            $github->unavailable = true;
            $resumed = $planner->plan($options);
            self::assertTrue($resumed->resuming);
            self::assertSame($plan->changelogContents, $resumed->changelogContents);
            self::assertSame($beforeCalls, count($github->calls), 'Recovery must not rediscover links.');
            $github->unavailable = false;
            self::assertTrue($runtime->get(ReleaseApplierInterface::class)->apply($resumed));
            self::assertFileDoesNotExist($plan->receiptPath);
            self::assertFileDoesNotExist($root . '/.changelog/auto-pr.md');
            $git(['add', '-A']);
            $git(['commit', '-m', $plan->commitMessage('chore: version fixture')]);
            $approved = trim($git(['rev-parse', 'HEAD']));
            $publisher = $runtime->get(PublicationServiceInterface::class);
            $proof = $publisher->publish($options, $approved, true);
            self::assertSame('dry-run', $proof->state);
            self::assertSame('1.0.1', $proof->version);
            self::assertSame("v1.0.0\n", $git(['tag', '--list']));
            file_put_contents(
                $root . '/.changelog/auto-pr.md',
                'A new uncommitted note cannot change approved evidence.',
            );
            self::assertSame('dry-run', $publisher->publish($options, $approved, true)->state);
            foreach (['outage', 'drift', 'ambiguity', 'implementation'] as $failure) {
                $github->unavailable = 'outage' === $failure;
                $github->incompleteFiles = 'implementation' === $failure;
                $github->numbers = 'ambiguity' === $failure ? [41, 42]
                    : ('implementation' === $failure ? [41] : ($associated ? [] : [41]));
                $failed = false;
                try {
                    $publisher->publish($options, $approved, true);
                } catch (Throwable) {
                    $failed = true;
                }
                self::assertTrue($failed, 'Changed or unavailable provenance must prevent publication: ' . $failure);
                self::assertSame("v1.0.0\n", $git(['tag', '--list']));
            }
            self::assertSame([], array_values(array_filter(
                $github->calls,
                static fn(array $call): bool => 'GET' !== $call[0] && ['POST', '/graphql'] !== $call,
            )));
        } finally {
            $fs->chmod($root, 0700, 0000, true);
            $fs->remove($root);
        }
    }
}
