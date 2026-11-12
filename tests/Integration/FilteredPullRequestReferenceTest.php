<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Integration;

use FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider;
use FastForward\Changelog\Git\GitObjectScope;
use FastForward\Changelog\Git\GitRepository;
use FastForward\Changelog\GitHub\GitHubClient;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use FastForward\Container\Factory\ServiceFactory;
use Interop\Container\ServiceProviderInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function FastForward\Config\config;
use function FastForward\Container\container;

/** Real isolated Git objects and native provider composition; all HTTP evidence remains synthetic. */
#[CoversNothing]
#[Group('windows-references')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FilteredPullRequestReferenceTest extends TestCase
{
    use RecordsIntegrationProgress;

    /** Filtered original-head recovery proves a squash fragment without persisting partial objects or enabling fetches. */
    public function testNativeFilteredProvenancePreservesTheConsumer(): void
    {
        $root = realpath(sys_get_temp_dir()) . '/changelog-filtered-reference-' . bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir([$root . '/source', $root . '/consumer', $root . '/scopes'], 0700);
        // This test runs in a separate PHP process; host Git routing and credentials must not enter its fixtures.
        foreach (array_unique([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]) as $name) {
            if (str_starts_with(strtoupper($name), 'GIT_')) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            }
        }
        foreach ([
            'HOME' => $root, 'XDG_CONFIG_HOME' => $root . '/xdg', 'GIT_CONFIG_GLOBAL' => $root . '/empty',
            'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_TERMINAL_PROMPT' => '0', 'GIT_ALLOW_PROTOCOL' => '',
            'GIT_AUTHOR_NAME' => 'Synthetic fixture', 'GIT_AUTHOR_EMAIL' => 'fixture@example.invalid',
            'GIT_COMMITTER_NAME' => 'Synthetic fixture', 'GIT_COMMITTER_EMAIL' => 'fixture@example.invalid',
        ] as $name => $value) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        file_put_contents($root . '/empty', '');
        $run = static function (
            string $directory,
            array $arguments,
            ?string $input = null,
            array $environment = [],
            bool $mustSucceed = true,
        ): Process {
            $process = new Process(['git', '-C', $directory, ...$arguments], $directory, [
                'GITHUB_TOKEN' => false, 'GH_TOKEN' => false, 'FF_CHANGELOG_TOKEN' => false,
                'FF_CHANGELOG_INPUTS' => false, ...$environment,
            ], timeout: 30);
            $process->setInput($input);
            $process->run();
            if ($mustSucceed && !$process->isSuccessful()) {
                throw new RuntimeException('Fixture Git failed: ' . $process->getErrorOutput());
            }

            return $process;
        };
        $source = $root . '/source';
        $consumer = $root . '/consumer';
        try {
            $run($source, ['init', '-q', '-b', 'main']);
            $run($source, ['config', 'core.autocrlf', 'false']);
            $history = "# Changelog\n\n## [1.0.0] - 2026-10-01\n\n### Added\n\n- Initial fixture.\n";
            file_put_contents($source . '/CHANGELOG.md', $history);
            $run($source, ['add', 'CHANGELOG.md']);
            $run($source, [
                '-c',
                'commit.gpgsign=false',
                '-c',
                'core.hooksPath=/dev/null',
                'commit',
                '-qm',
                'Initial fixture',
            ]);
            $initial = trim($run($source, ['rev-parse', 'HEAD'])->getOutput());
            $run($source, ['switch', '-qc', 'contribution']);
            // Both payloads exceed the native 1 MiB filter; only the final one exists in the squash consumer.
            $payload = str_repeat("Generated fixture payload, never a tracked PHAR.\n", 50_000);
            self::assertGreaterThan(1_048_576, strlen($payload));
            file_put_contents($source . '/before.txt', $payload);
            $run($source, ['add', 'before.txt']);
            $run($source, [
                '-c',
                'commit.gpgsign=false',
                '-c',
                'core.hooksPath=/dev/null',
                'commit',
                '-qm',
                'Introduce generated payload',
            ]);
            $first = trim($run($source, ['rev-parse', 'HEAD'])->getOutput());
            $beforeBlob = trim($run($source, ['rev-parse', $first . ':before.txt'])->getOutput());
            rename($source . '/before.txt', $source . '/after.txt');
            file_put_contents($source . '/after.txt', $payload . "Edited in the original PR.\n");
            $filesystem->mkdir($source . '/.changelog', 0700);
            $fragment = "---\ncategory: fixed\ntype: patch\n---\n\nProves the original implementation without persisting partial objects.\n";
            file_put_contents($source . '/.changelog/filtered.md', $fragment);
            $run($source, ['add', '--all']);
            $run($source, [
                '-c',
                'commit.gpgsign=false',
                '-c',
                'core.hooksPath=/dev/null',
                'commit',
                '-qm',
                'Rename payload and implement the fragment',
            ]);
            $head = trim($run($source, ['rev-parse', 'HEAD'])->getOutput());
            $tree = trim($run($source, ['rev-parse', $head . '^{tree}'])->getOutput());
            $merged = trim(
                $run($source, ['commit-tree', $tree, '-p', $initial, '-m', 'Squashed contribution'])->getOutput(),
            );
            $completePack = $run($source, ['pack-objects', '--stdout', '--revs'], $merged . "\n")->getOutput();
            $filteredPack = $run($source, [
                'pack-objects',
                '--stdout',
                '--revs',
                '--filter=blob:limit=1048576',
            ], $head . "\n")->getOutput();
            self::assertLessThan(1_048_576, strlen($filteredPack));
            $run($consumer, ['init', '-q', '-b', 'main']);
            $run($consumer, ['config', 'core.autocrlf', 'false']);
            $run($consumer, ['config', 'diff.renames', 'true']);
            $run($consumer, ['index-pack', '--stdin', '--strict'], $completePack);
            $run($consumer, ['update-ref', 'refs/heads/main', $merged]);
            $run($consumer, ['update-ref', 'refs/tags/v1.0.0', $initial]);
            $run($consumer, ['reset', '--hard', 'HEAD']);
            $run($consumer, ['remote', 'add', 'origin', 'https://github.com/owner/fixture.git']);
            self::assertFalse($run($consumer, ['cat-file', '-e', $head], mustSucceed: false)->isSuccessful());
            self::assertFalse($run($consumer, ['cat-file', '-e', $first], mustSucceed: false)->isSuccessful());
            self::assertSame(0, $run($consumer, ['fsck', '--full'])->getExitCode());

            self::assertFalse($run($consumer, ['cat-file', '-e', $beforeBlob], mustSucceed: false)->isSuccessful());

            $snapshot = static function () use ($consumer, $run): array {
                $status = $run($consumer, ['status', '--porcelain=v1'])->getOutput();

                return [
                    'objects' => $run($consumer, ['count-objects', '-v'])->getOutput(),
                    'refs' => $run($consumer, ['show-ref'])->getOutput(),
                    'config' => file_get_contents($consumer . '/.git/config'),
                    'index' => hash_file('sha256', $consumer . '/.git/index'),
                    'status' => $status,
                    'fragment' => file_get_contents($consumer . '/.changelog/filtered.md'),
                    'payload' => hash_file('sha256', $consumer . '/after.txt'),
                ];
            };
            $before = $snapshot();
            $requests = [];
            $repo = ['id' => 42, 'node_id' => 'opaque-filtered-repo', 'full_name' => 'owner/fixture'];
            $fragmentBlob = hash('sha1', 'blob ' . strlen($fragment) . "\0" . $fragment);
            $afterBlob = trim($run($source, ['rev-parse', $head . ':after.txt'])->getOutput());
            $http = new MockHttpClient(static function (string $method, string $url, array $options) use (
                &$requests,
                $repo,
                $merged,
                $head,
                $first,
                $filteredPack,
                $fragmentBlob,
                $afterBlob,
                $root
            ): MockResponse {
                $requests[] = [$method, $url];
                self::assertSame(0, $options['max_redirects']);
                if (str_starts_with($url, 'https://github.com/owner/fixture.git/')) {
                    self::assertSame([
                        'Authorization: Basic ' . base64_encode('x-access-token:synthetic-http-token')],
                        $options['normalized_headers']['authorization'],
                    );
                    if ('GET' === $method && str_ends_with($url, 'info/refs?service=git-upload-pack')) {
                        $line = $head . " HEAD\0filter\n";

                        return new MockResponse("001e# service=git-upload-pack\n0000" . sprintf(
                            '%04x',
                            strlen($line) + 4,
                        ) . $line . '0000', ['response_headers' => ['content-type' => 'application/x-git-upload-pack-advertisement']]);
                    }
                    if ('POST' === $method && str_ends_with($url, '/git-upload-pack')) {
                        $want = 'want ' . $head . " filter\n";
                        $filter = "filter blob:limit=1048576\n";
                        self::assertSame(
                            sprintf('%04x', strlen($want) + 4) . $want . sprintf(
                                '%04x',
                                strlen($filter) + 4,
                            ) . $filter . "00000009done\n",
                            $options['body'],
                        );
                        self::assertStringNotContainsString('synthetic-http-token', $options['body']);
                        self::assertCount(1, glob($root . '/scopes/fast-forward-changelog-objects-*'));

                        return new MockResponse("0008NAK\n" . $filteredPack, ['response_headers' => ['content-type' => 'application/x-git-upload-pack-result']]);
                    }

                    throw new RuntimeException('Unexpected smart HTTP fixture request.');
                }
                self::assertSame([
                    'Authorization: Bearer synthetic-http-token'],
                    $options['normalized_headers']['authorization'],
                );
                $path = parse_url($url, PHP_URL_PATH);
                if ('POST' === $method && '/graphql' === $path) {
                    $body = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                    self::assertSame(['owner' => 'owner', 'name' => 'fixture', 'number' => 41], $body['variables']);
                    $answer = ['data' => ['repository' => ['nameWithOwner' => $repo['full_name'], 'id' => $repo['node_id'], 'pullRequest' => ['number' => 41, 'state' => 'MERGED', 'merged' => true, 'headRefOid' => $head, 'headRefName' => 'contribution', 'baseRefName' => 'main', 'mergeCommit' => ['oid' => $merged]]]]];
                } elseif ('GET' !== $method) {
                    throw new RuntimeException('No remote writes are permitted by this fixture.');
                } elseif ('/repos/owner/fixture' === $path) {
                    $answer = $repo;
                } elseif ('/repos/owner/fixture/commits/' . $merged . '/pulls' === $path) {
                    $answer = [['number' => 41]];
                } elseif ('/repos/owner/fixture/pulls/41' === $path) {
                    $answer = ['number' => 41, 'state' => 'closed', 'merged' => true, 'changed_files' => 2, 'commits' => 2, 'head' => ['sha' => $head, 'ref' => 'contribution'], 'base' => ['ref' => 'main', 'repo' => $repo]];
                } elseif ('/repos/owner/fixture/pulls/41/files' === $path) {
                    $answer = [['filename' => '.changelog/filtered.md', 'status' => 'added', 'sha' => $fragmentBlob], ['filename' => 'after.txt', 'status' => 'added', 'sha' => $afterBlob]];
                } elseif ('/repos/owner/fixture/pulls/41/commits' === $path) {
                    $answer = [['sha' => $first], ['sha' => $head]];
                } else {
                    throw new RuntimeException('Unexpected native API fixture endpoint: ' . $path);
                }

                return new MockResponse(json_encode($answer, JSON_THROW_ON_ERROR));
            });
            $bindings = new readonly class ($http) implements ServiceProviderInterface {
                public function __construct(
                    private HttpClientInterface $http,
                ) {}
                public function getFactories(): array
                {
                    return [HttpClientInterface::class => new ServiceFactory($this->http)];
                }
                public function getExtensions(): array
                {
                    return [];
                }
            };
            $runtime = container(
                config([
                    'changelog' => ['working_directory' => $consumer, 'temporary_directory' => $root . '/scopes', 'token' => 'synthetic-http-token'],
                ]),
                $bindings,
                ChangelogServiceProvider::class,
            );
            self::assertInstanceOf(GitHubClient::class, $runtime->get(GitHubClientInterface::class));
            $options = $runtime->get(ReleaseOptionsFactoryInterface::class)->create(
                ['workingDirectory' => $consumer, 'repository' => 'owner/fixture', 'source' => 'tags', 'resolvePullRequests' => true],
            );
            $plan = $runtime->get(ReleasePlannerInterface::class)->plan($options);
            self::assertSame('1.0.1', $plan->nextVersion);
            self::assertStringContainsString('([#41](https://github.com/owner/fixture/pull/41))', $plan->notes);
            self::assertStringContainsString('Proves the original implementation', $plan->notes);
            self::assertCount(
                1,
                array_filter($requests, static fn(array $request): bool => 'POST' === $request[0] && str_ends_with(
                    $request[1],
                    '/git-upload-pack',
                )),
            );
            self::assertSame([], glob($root . '/scopes/fast-forward-changelog-objects-*'));
            self::assertSame($before, $snapshot());
            self::assertSame(0, $run($consumer, ['fsck', '--full'])->getExitCode());
            self::assertFalse($run($consumer, ['cat-file', '-e', $head], mustSucceed: false)->isSuccessful());
            self::assertFalse($run($consumer, ['cat-file', '-e', $first], mustSucceed: false)->isSuccessful());
            self::assertFalse($run($consumer, ['cat-file', '-e', $beforeBlob], mustSucceed: false)->isSuccessful());
            // Partial acquisition may omit blobs, but every reachable commit and tree remains mandatory.
            $firstTree = trim($run($source, ['rev-parse', $first . '^{tree}'])->getOutput());
            $initialTree = trim($run($source, ['rev-parse', $initial . '^{tree}'])->getOutput());
            $side = trim(
                $run($source, [
                    'commit-tree',
                    $initialTree,
                    '-p',
                    $initial,
                    '-m',
                    'Separate side-parent fixture',
                ])->getOutput(),
            );
            $mergeHead = trim(
                $run($source, [
                    'commit-tree',
                    $tree,
                    '-p',
                    $head,
                    '-p',
                    $side,
                    '-m',
                    'Merge provenance fixture',
                ])->getOutput(),
            );
            foreach ([
                'first parent' => [$head, $first],
                'parent tree' => [$head, $firstTree],
                'side parent' => [$mergeHead, $side],
            ] as $missing => [$target, $omitted]) {
                self::assertFalse($run($consumer, ['cat-file', '-e', $omitted], mustSucceed: false)->isSuccessful());
                $records = $run($source, ['rev-list', '--objects', '--filter=blob:none', $target])->getOutput();
                $identities = array_map(
                    static fn(string $record): string => explode(' ', $record, 2)[0],
                    explode("\n", rtrim($records, "\n")),
                );
                self::assertContains($omitted, $identities);
                $selected = array_values(
                    array_filter($identities, static fn(string $identity): bool => $identity !== $omitted),
                );
                // Explicit object input, without --revs, prevents pack-objects from restoring the omitted metadata.
                $incompletePack = $run($source, ['pack-objects', '--stdout'], implode(
                    "\n",
                    $selected,
                ) . "\n")->getOutput();
                $rejected = false;
                try {
                    $runtime->get(GitObjectScope::class)->run($consumer, static function (GitRepository $git) use (
                        $consumer,
                        $target,
                        $incompletePack
                    ): void {
                        $git->acquireFilteredCommit($consumer, 'owner/fixture', $target, $incompletePack);
                    });
                } catch (RuntimeException) {
                    $rejected = true;
                }
                self::assertTrue($rejected, 'Missing reachable ' . $missing . ' must prevent provenance acquisition.');
                self::assertSame([], glob($root . '/scopes/fast-forward-changelog-objects-*'));
                self::assertSame($before, $snapshot(), 'Rejected metadata must leave the consumer unchanged.');
                self::assertSame(0, $run($consumer, ['fsck', '--full'])->getExitCode());
            }
        } finally {
            $filesystem->chmod($root, 0700, 0000, true);
            $filesystem->remove($root);
        }
    }
}
