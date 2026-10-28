<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Integration;

use Closure;
use FastForward\Changelog\Automation\AutomationRunnerInterface;
use FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\History\HistoryCodecInterface;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\Markdown\SourceDocument;
use FastForward\Changelog\Publication\PublicationServiceInterface;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use FastForward\Changelog\Template\Factory\TemplateFactoryInterface;
use FastForward\Container\Factory\ServiceFactory;
use Interop\Container\ServiceProviderInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Throwable;

use function FastForward\Config\config;
use function FastForward\Container\container;

/** Separate disposable integration suite: real Git/domain, synthetic HTTP only, no real publication. */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SquashPublicationTest extends TestCase
{
    /** A clone of main/tags has no H object, while the deleted branch's H survives only in the origin PR ref. */
    #[DataProvider('squashModes')]
    public function testDeletedBranchAndMissingLocalHead(
        bool $staleTrailers,
        bool $customTemplate,
        bool $hostileGitEnvironment = false,
        ?string $authoredList = null,
    ): void {
        $this->exerciseScenario($staleTrailers, $customTemplate, $hostileGitEnvironment, $authoredList, true);
    }

    /** Every Markdown regression exercises real planning and exact history round trips without another publication transaction. */
    #[DataProvider('markdownModes')]
    public function testMarkdownSurvivesPlanning(
        bool $staleTrailers,
        bool $customTemplate,
        bool $hostileGitEnvironment,
        string $authoredList,
    ): void {
        $this->exerciseScenario($staleTrailers, $customTemplate, $hostileGitEnvironment, $authoredList, false);
    }

    /** Builds disposable Git evidence; only publication scenarios continue through squash, clone and reconstruction. */
    private function exerciseScenario(
        bool $staleTrailers,
        bool $customTemplate,
        bool $hostileGitEnvironment,
        ?string $authoredList,
        bool $publish,
    ): void {
        $root = realpath(sys_get_temp_dir()) . '/changelog-squash-' . bin2hex(random_bytes(8));
        if ($hostileGitEnvironment) {
            putenv('GIT_DIR=' . $root . '/must-not-be-a-git-dir');
            putenv('GIT_WORK_TREE=' . $root . '/must-not-be-a-worktree');
            putenv('GIT_INDEX_FILE=' . $root . '/must-not-be-an-index');
            foreach (['git_DIR' => '/must-not-be-a-git-dir', 'Git_Work_Tree' => '/must-not-be-a-worktree', 'gIt_InDeX_fIlE' => '/must-not-be-an-index'] as $name => $path) {
                putenv($name . '=' . $root . $path);
                $_ENV[$name] = $_SERVER[$name] = $root . $path;
            }
        }
        // PHPUnit isolates this process, so no caller environment is modified or restored with secret values.
        foreach (array_unique([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]) as $name) {
            if (str_starts_with(strtoupper($name), 'GIT_')) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            }
        }
        if ($hostileGitEnvironment) {
            foreach (['git_DIR', 'Git_Work_Tree', 'gIt_InDeX_fIlE'] as $name) {
                self::assertFalse(getenv($name));
                self::assertArrayNotHasKey($name, $_ENV);
                self::assertArrayNotHasKey($name, $_SERVER);
            }
        }
        foreach (['HOME' => $root, 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => $root . '/empty-gitconfig', 'GIT_TERMINAL_PROMPT' => '0'] as $name => $value) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        new Filesystem()->mkdir($root, 0700);
        file_put_contents($root . '/empty-gitconfig', '');
        $producer = $root . '/origin';
        $consumer = $root . '/consumer';
        /** Runs only disposable fixture commands, with host configuration and credentials disabled. */
        $git = static fn(string $directory, array $arguments): string => new Process([
            'git',
            '-C',
            $directory,
            ...$arguments,
        ], $root, [
            'HOME' => $root, 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => $root . '/empty-gitconfig',
            'GIT_TERMINAL_PROMPT' => '0', 'GITHUB_TOKEN' => false, 'GH_TOKEN' => false, 'FF_CHANGELOG_TOKEN' => false,
        ])->mustRun()->getOutput();
        try {
            $git($root, ['init', '--initial-branch=main', $producer]);
            $git($producer, ['config', 'user.name', 'Synthetic fixture']);
            $git($producer, ['config', 'user.email', 'fixture@example.invalid']);
            file_put_contents(
                $producer . '/CHANGELOG.md',
                "# Changelog\n\n## [1.0.0] - 2026-10-01\n\nOriginal release.\n",
            );
            file_put_contents($producer . '/custom.php', '<?php return [];');
            $git($producer, ['add', '.']);
            $git($producer, ['commit', '-m', 'Original published fixture']);
            $git($producer, ['tag', 'v1.0.0']);
            mkdir($producer . '/.changelog');
            $listDescription = match ($authoredList) {
                'root-block-gaps' => "12. [local]: https://example.test/local\n"
                    . "13. First improvement: [inline](https://example.test \"<!--\"). See [local].\n\nAdditional context.\n\n"
                    . "14. Second improvement.\n\nMore context. See [guide].\n\n[guide]: https://example.test/reference",
                'html-tab' => "<pre>\nall:\n\tprintf 'hello'\n</pre>",
                'mixed-indent' => "  - First improvement.\n- Second improvement.\n\n  Continued.",
                'raw-html' => "- <pre>\n  ```\n  </pre>\n- Second improvement.",
                'sibling-inline' => "- Use `foo\n- <!-- hidden\n  ~~~\n  -->\n- bar`",
                'fenced' => "- ```md\n  ## [9.9.9]\n  ```\n- Second improvement.",
                'indented' => "  - ```md\n    example\n      ```\n  - Second improvement.",
                'nested' => "- - ```md\n    ## [9.9.9]\n    ```\n- Second improvement.",
                'code-comment' => "- First improvement.\n\n      <!-- literal example\n- Second improvement.",
                'reference-example' => "- ```md\n  [1.0.1]: https://example.test/literal\n  ```\n- Second improvement.",
                'comment-fence' => "- <!-- hidden\n  ~~~\n  --> Visible improvement.\n- Second improvement.",
                'quoted' => "> ~~~html\n> <!-- literal\n> ~~~",
                'tab-code' => "- First improvement.\n\n  \t  <!-- literal\tpayload\n- Second improvement.",
                'indented-single' => "-     printf hello\n- Second improvement.",
                'nested-definition' => "- See [guide].\n  - Child.\n\n    > ~~~html\n    > <!-- literal\n    > ~~~\n\n[guide]: https://example.test",
                'presentation-boundaries' => "- Description.\n\n      all:\n      \tprintf 'hello'\n - Second.\n\n<div>Detail.</div>",
                'tab-fenced' => "- ```make\n  all:\n  \tprintf 'hello'\n  ## [9.9.9]\n  [1.0.1]: https://example.test/literal\n  ```\n- Second improvement.",
                'inline' => "- Use `some\n  code` safely.\n- Second improvement.",
                'comment' => "- <!-- hidden\n  --> Visible improvement.\n- Second improvement.",
                'ordered' => "2. Second improvement.\n3. Third improvement.",
                default => "- First improvement.\n- Second improvement.",
            };

            if ('ordered' === $authoredList) {
                file_put_contents(
                    $producer . '/.changelog/a-first.md',
                    "---\ncategory: fixed\ntype: patch\n---\n\nFirst.\n",
                );
            }
            if ('presentation-boundaries' === $authoredList) {
                file_put_contents(
                    $producer . '/.changelog/z-next.md',
                    "---\ncategory: fixed\ntype: patch\n---\n\nNext.\n",
                );
            }
            $deliveryMetadata = in_array($authoredList, ['nested-definition', 'presentation-boundaries'], true)
                ? '' : "pull_request: 7\n";
            file_put_contents(
                $producer . '/.changelog/squash.md',
                null !== $authoredList
                    ? "---\ncategory: fixed\ntype: patch\n" . $deliveryMetadata . "---\n\n" . $listDescription . "\n"
                    : "---\ncategory: fixed\ntype: patch\n---\n\nPreserves publication after a squash merge.\n",
            );
            $git($producer, ['add', '.']);
            $git($producer, ['commit', '-m', 'Pending fixture fragment']);
            $base = trim($git($producer, ['rev-parse', 'HEAD']));
            $template = $customTemplate ? 'custom.php' : 'keep-a-changelog';
            $configured = container(
                config(['changelog' => ['working_directory' => $producer]]),
                ChangelogServiceProvider::class,
            );
            $options = $configured->get(ReleaseOptionsFactoryInterface::class)->create([
                'workingDirectory' => $producer, 'repository' => 'owner/fixture', 'source' => 'tags', 'template' => $template,
            ]);
            $plan = $configured->get(ReleasePlannerInterface::class)->plan($options, 'version');

            if (null !== $authoredList) {
                $reference = '([#7](https://github.com/owner/fixture/pull/7))';
                $first = match ($authoredList) {
                    'fenced' => "- ```md\n  ## [9.9.9]\n  ```\n\n  " . $reference,
                    'indented' => "- ```md\n  example\n    ```\n\n  " . $reference,
                    'nested' => "- - ```md\n    ## [9.9.9]\n    ```\n\n  " . $reference,
                    'code-comment' => "- First improvement.\n\n      <!-- literal example\n\n  " . $reference,
                    'reference-example' => "- ```md\n  [1.0.1]: https://example.test/literal\n  ```\n\n  " . $reference,
                    'comment-fence' => "- <!-- hidden\n  ~~~\n  --> Visible improvement.\n\n  " . $reference,
                    'tab-code' => "- First improvement.\n\n      <!-- literal\tpayload\n\n  " . $reference,
                    'indented-single' => "-     printf hello\n\n  " . $reference,
                    'tab-fenced' => "- ```make\n  all:\n  \tprintf 'hello'\n  ## [9.9.9]\n  [1.0.1]: https://example.test/literal\n  ```\n\n  " . $reference,
                    'inline' => "- Use `some\n  code` safely.\n\n  " . $reference,
                    'comment' => "- <!-- hidden\n  --> Visible improvement.\n\n  " . $reference,
                    default => '- First improvement. ' . $reference,
                };
                $expected = "\n### Fixed\n\n" . $first . "\n"
                    . '- Second improvement. ' . $reference . "\n\n";

                if ('ordered' === $authoredList) {
                    $expected = "\n### Fixed\n\n- First.\n\n2. Second improvement. " . $reference . "\n3. Third improvement. " . $reference . "\n\n";
                } elseif ('quoted' === $authoredList) {
                    $expected = "\n### Fixed\n\n- > ~~~html\n  > <!-- literal\n  > ~~~\n\n  " . $reference . "\n\n";
                } elseif ('nested-definition' === $authoredList) {
                    $expected = "\n### Fixed\n\n- See [guide].\n  - Child.\n\n    > ~~~html\n    > <!-- literal\n    > ~~~\n\n  [guide]: https://example.test\n\n";
                } elseif ('presentation-boundaries' === $authoredList) {
                    $expected = "\n### Fixed\n\n- Description.\n\n      all:\n      \tprintf 'hello'\n - Second.\n\n<div>Detail.</div>\n\n- Next.\n\n";
                } elseif ('mixed-indent' === $authoredList) {
                    $expected = "\n### Fixed\n\n- First improvement. " . $reference
                        . "\n- Second improvement.\n\n  Continued.\n\n  " . $reference . "\n\n";
                } elseif ('raw-html' === $authoredList) {
                    $expected = "\n### Fixed\n\n- <pre>\n  ```\n  </pre>\n\n  " . $reference
                        . "\n- Second improvement. " . $reference . "\n\n";
                } elseif ('sibling-inline' === $authoredList) {
                    $expected = "\n### Fixed\n\n- Use `foo " . $reference
                        . "\n- <!-- hidden\n  ~~~\n  -->\n\n  " . $reference . "\n- bar` " . $reference . "\n\n";
                } elseif ('root-block-gaps' === $authoredList) {
                    $expected = "\n### Fixed\n\n12. [local]: https://example.test/local\n\n    " . $reference
                        . "\n13. First improvement: [inline](https://example.test \"<!--\"). See [local]. " . $reference
                        . "\n\nAdditional context.\n\n14. Second improvement. " . $reference
                        . "\n\nMore context. See [guide].\n\n   [guide]: https://example.test/reference\n\n";
                    self::assertArrayHasKey('guide', SourceDocument::analyze($plan->notes)->referenceOffsets());
                } elseif ('html-tab' === $authoredList) {
                    $expected = "\n### Fixed\n\n- <pre>\n  all:\n  \tprintf 'hello'\n  </pre>\n\n  " . $reference . "\n\n";
                }
                $history = $configured->get(HistoryCodecInterface::class);
                self::assertSame($expected, $plan->notes);
                self::assertSame($expected, $history->notes($history->parse($plan->changelogContents), '1.0.1'));
                self::assertStringContainsString(
                    "## [1.0.0] - 2026-10-01\n\nOriginal release.\n",
                    $plan->changelogContents,
                );
                self::assertStringContainsString(
                    '[1.0.1]: https://github.com/owner/fixture/compare/v1.0.0...v1.0.1',
                    $plan->changelogContents,
                );

                if (in_array($authoredList, [
                    'tab-fenced', 'nested-definition', 'presentation-boundaries', 'mixed-indent', 'raw-html', 'sibling-inline',
                    'root-block-gaps', 'html-tab',
                ], true)) {
                    $presentation = $configured->get(TemplateFactoryInterface::class)->create();
                    $parsed = $history->parse($plan->changelogContents, $presentation);

                    foreach ([false, true] as $preserve) {
                        $roundTrip = $history->parse(
                            $history->render($parsed, $presentation, $preserve),
                            $presentation,
                        );
                        self::assertSame(['1.0.1', '1.0.0'], array_map(
                            static fn(HistoryRelease $release): string => $release->getVersion(),
                            $roundTrip->getReleases(),
                        ));
                        self::assertSame($expected, $history->notes($roundTrip, '1.0.1'));
                        self::assertSame(
                            $parsed->getRelease('1.0.0')->getBody(),
                            $roundTrip->getRelease('1.0.0')->getBody(),
                        );
                        self::assertSame($parsed->getReferences(), $roundTrip->getReferences());
                    }
                }
            }
            if (! $publish) {
                return;
            }
            $git($producer, ['checkout', '-b', 'changelog/version']);
            file_put_contents($producer . '/CHANGELOG.md', $plan->changelogContents);
            foreach ($plan->consumed as $path => $hash) {
                unlink($path);
            }
            $git($producer, ['add', '.']);
            $git($producer, ['commit', '-m', $plan->commitMessage()]);
            $head = trim($git($producer, ['rev-parse', 'HEAD']));
            $tree = trim($git($producer, ['rev-parse', 'HEAD^{tree}']));
            $message = $git($producer, ['show', '-s', '--format=%B', $head]);
            $git($producer, ['update-ref', 'refs/pull/7/head', $head]);
            $git($producer, ['checkout', 'main']);
            $git($producer, ['merge', '--squash', 'changelog/version']);
            $git($producer, [
                'commit',
                '-m',
                $staleTrailers ? str_replace(
                    hash('sha256', $plan->changelogContents),
                    str_repeat('0', 64),
                    $message,
                ) : 'Ordinary squash with no transaction trailers',
            ]);
            $merge = trim($git($producer, ['rev-parse', 'HEAD']));
            $git($producer, ['branch', '-D', 'changelog/version']);
            $git($root, ['clone', '--no-local', '--single-branch', '--branch=main', $producer, $consumer]);
            self::assertSame('', $git($producer, ['branch', '--list', 'changelog/version']));
            self::assertSame($head, trim($git($producer, ['rev-parse', 'refs/pull/7/head'])));
            $missing = new Process(['git', '-C', $consumer, 'cat-file', '-e', $head . '^{commit}'], $root, [
                'HOME' => $root, 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => $root . '/empty-gitconfig',
                'GITHUB_TOKEN' => false, 'GH_TOKEN' => false, 'FF_CHANGELOG_TOKEN' => false,
            ]);
            $missing->run();
            self::assertFalse($missing->isSuccessful(), 'The test must not have a local PR-head object.');
            $bot = ['login' => 'github-actions[bot]', 'type' => 'Bot', 'id' => 41898282];
            $calls = [];
            /** Emulates immutable GitHub objects from the disposable origin; every real HTTP/mutation is forbidden. */
            $api = static function (string $method, string $path, ?array $body = null) use (
                $git,
                $producer,
                $base,
                $head,
                $tree,
                $message,
                $merge,
                $bot,
                &$calls
            ): ?array {
                $calls[] = [$method, $path, $body];
                if ('POST' === $method && '/graphql' === $path
                    && ['query' => self::mergedQuery(), 'variables' => ['owner' => 'owner', 'name' => 'fixture', 'number' => 7]] === $body) {
                    return ['data' => ['repository' => ['nameWithOwner' => 'owner/fixture', 'id' => 'opaque-fixture-repository',
                        'pullRequest' => ['number' => 7, 'state' => 'MERGED', 'merged' => true,
                            'headRefOid' => $head, 'headRefName' => 'changelog/version', 'baseRefName' => 'main',
                            'mergeCommit' => ['oid' => $merge]]]]];
                }
                if ('GET' !== $method) {
                    throw new RuntimeException('This integration fixture forbids every HTTP mutation.');
                }
                $prefix = '/repos/owner/fixture';
                if ('/users/github-actions%5Bbot%5D' === $path) {
                    return $bot;
                }
                if (($prefix . '/pulls/7') === $path) {
                    $repo = ['id' => 11, 'full_name' => 'owner/fixture', 'node_id' => 'opaque-fixture-repository'];

                    return ['number' => 7, 'state' => 'closed', 'merged' => true, 'user' => $bot,
                        'head' => ['sha' => $head, 'ref' => 'changelog/version', 'repo' => $repo], 'base' => ['sha' => $base, 'ref' => 'main', 'repo' => $repo]];
                }
                if (($prefix . '/commits/' . $head) === $path) {
                    return ['sha' => $head, 'author' => $bot, 'committer' => $bot, 'commit' => ['message' => $message, 'tree' => ['sha' => $tree], 'verification' => ['verified' => true, 'reason' => 'valid']]];
                }
                if (($prefix . '/git/commits/' . $merge) === $path) {
                    return ['sha' => $merge, 'tree' => ['sha' => trim(
                        $git($producer, ['rev-parse', $merge . '^{tree}']),
                    )]];
                }
                if (str_starts_with($path, $prefix . '/compare/' . $base . '...')) {
                    $target = substr($path, strrpos($path, '...') + 3);
                    $files = [];
                    foreach (explode("\n", trim($git($producer, ['diff', '--name-status', $base, $target]))) as $line) {
                        [$status, $name] = explode("\t", $line);
                        $files[] = ['filename' => $name, 'status' => 'D' === $status ? 'removed' : 'modified'];
                    }

                    return ['merge_base_commit' => ['sha' => trim(
                        $git($producer, ['merge-base', $base, $target]),
                    )], 'status' => 'ahead', 'files' => $files];
                }
                if (preg_match('~^' . $prefix . '/contents/(.+)\?ref=([a-f0-9]{40})$~', $path, $parts)) {
                    $name = rawurldecode($parts[1]);
                    $sha = $parts[2];
                    if ('' === $git($producer, ['ls-tree', '--name-only', $sha, '--', $name])) {
                        return null;
                    }
                    if ('tree' === trim($git($producer, ['cat-file', '-t', $sha . ':' . $name]))) {
                        return array_map(
                            static fn(string $file): array => ['path' => $name . '/' . $file, 'type' => 'file'],
                            explode("\n", trim($git($producer, ['ls-tree', '--name-only', $sha . ':' . $name]))),
                        );
                    }

                    return ['type' => 'file', 'encoding' => 'base64', 'content' => base64_encode(
                        $git($producer, ['show', $sha . ':' . $name]),
                    )];
                }
                if (str_contains($path, '/git/ref/tags/') || str_contains($path, '/releases/tags/')) {
                    return null;
                }

                throw new RuntimeException('Unexpected synthetic API request: ' . $path);
            };
            $github = new readonly class ($api) implements GitHubClientInterface {
                /** Stores a synthetic in-memory API boundary, with no transport or credentials. */
                public function __construct(
                    private Closure $api,
                ) {}
                /** Returns only fixture data; this adapter cannot send network requests. */
                public function request(
                    string $method,
                    string $relativePath,
                    ?array $body = null,
                ): ?array {
                    return ($this->api)($method, $relativePath, $body);
                }
                /** No paginated operation belongs to the merged-publication proof. */
                public function paginate(string $relativePath): array
                {
                    throw new RuntimeException(
                        'Unexpected pagination.',
                    );
                }
            };
            $bindings = new readonly class ($github) implements ServiceProviderInterface {
                /** Replaces only the HTTP boundary; Git, domain and publication remain real. */
                public function __construct(
                    private GitHubClientInterface $github,
                ) {}
                /** Provides the explicitly synthetic HTTP adapter. */
                public function getFactories(): array
                {
                    return [GitHubClientInterface::class => new ServiceFactory(
                        $this->github,
                    )];
                }
                /** Retains the production services without decoration. */
                public function getExtensions(): array
                {
                    return [];
                }
            };
            $runtime = container(
                config(['changelog' => ['working_directory' => $consumer]]),
                $bindings,
                ChangelogServiceProvider::class,
            );
            self::assertSame(
                $github,
                $runtime->get(GitHubClientInterface::class),
                'The fixture must replace HTTP before resolving production services.',
            );
            $result = $runtime->get(AutomationRunnerInterface::class)->run('publish', [
                'working-directory' => $consumer, 'repository' => 'owner/fixture', 'source' => 'tags', 'template' => $template,
                'target-sha' => $merge, 'expected-head-sha' => $head, 'pull-request' => '7', 'dry-run' => 'true',
            ]);
            self::assertSame('dry-run', $result['state']);
            self::assertSame($merge, $result['sha']);
            self::assertSame('1.0.1', $result['version']);
            self::assertSame(['create_tag', 'create_release'], $result['actions']);
            self::assertSame([
            ],
                array_values(array_filter($calls, static fn(array $call): bool => 'GET' !== $call[0]
                    && ['POST', '/graphql', ['query' => self::mergedQuery(), 'variables' => ['owner' => 'owner', 'name' => 'fixture', 'number' => 7]]] !== $call)),
            );
            self::assertCount(1, array_filter($calls, static fn(array $call): bool => '/graphql' === $call[1]));
            $failed = false;
            try {
                $consumerOptions = $runtime->get(ReleaseOptionsFactoryInterface::class)->create([
                    'workingDirectory' => $consumer, 'repository' => 'owner/fixture', 'source' => 'tags', 'template' => $template,
                ]);
                $runtime->get(PublicationServiceInterface::class)->publish($consumerOptions, $merge, true);
            } catch (RuntimeException|\InvalidArgumentException) {
                $failed = true;
            }
            self::assertTrue($failed, 'Generic CLI publication must remain strict for missing/stale squash metadata.');
            $missing->run();
            self::assertFalse(
                $missing->isSuccessful(),
                'The contextual publisher must not fetch or create a local H object.',
            );
            self::assertSame($merge, trim($git($consumer, ['rev-parse', 'HEAD'])));
            foreach (['must-not-be-a-git-dir', 'must-not-be-a-worktree', 'must-not-be-an-index'] as $path) {
                self::assertFileDoesNotExist($root . '/' . $path);
            }
        } catch (Throwable $failure) {
            throw $failure;
        } finally {
            try {
                self::removeFixture($root);
            } catch (Throwable $cleanupFailure) {
                if (! isset($failure)) {
                    throw $cleanupFailure;
                }
                fwrite(STDERR, 'Disposable fixture cleanup also failed: ' . $cleanupFailure::class . "\n");
            }
        }
    }

    /** Git object files may be read-only on Windows; cleanup changes only disposable fixture descendants. */
    public function testCleanupRemovesReadOnlyFixtureFiles(): void
    {
        $root = realpath(sys_get_temp_dir()) . '/changelog-cleanup-' . bin2hex(random_bytes(8));
        new Filesystem()->mkdir($root, 0700);
        try {
            file_put_contents($root . '/read-only-object', 'disposable');
            new Filesystem()->chmod($root . '/read-only-object', 0444);
            self::removeFixture($root);
            self::assertDirectoryDoesNotExist($root);
        } finally {
            if (is_dir($root)) {
                self::removeFixture($root);
            }
        }
    }

    /** Exercises ordinary and stale squash messages with built-in and explicit committed templates. */
    public static function squashModes(): iterable
    {
        yield 'missing metadata' => [false, false];
        yield 'stale metadata' => [true, false];
        yield 'committed custom template' => [false, true];
        yield 'hostile mixed-case inherited Git environment' => [false, false, true];
        yield 'literal Markdown and references survive publication reconstruction' => [
            false,
            false,
            false,
            'tab-fenced',
        ];
        yield 'independent fragment boundaries survive publication reconstruction' => [
            false,
            false,
            false,
            'presentation-boundaries',
        ];
    }

    /** Preserves the complete Markdown corpus at the real planner and history boundary, independently of publication transport. */
    public static function markdownModes(): iterable
    {
        foreach (['mixed-indent', 'raw-html', 'sibling-inline', 'root-block-gaps', 'html-tab'] as $description) {
            yield $description => [false, false, false, $description];
        }
        yield 'authored list notes survive planning' => [
            false,
            false,
            false,
            'prose',
        ];
        yield 'list-item code fences survive planning' => [false, false, false, 'fenced'];
        yield 'normalized list indent survives planning' => [false, false, false, 'indented'];
        yield 'same-line nested fence survives planning' => [false, false, false, 'nested'];
        yield 'indented literal HTML keeps references visible' => [false, false, false, 'code-comment'];
        yield 'fenced literal definition cannot replace the release footer' => [
            false,
            false,
            false,
            'reference-example',
        ];
        yield 'closed comment keeps fence examples literal' => [false, false, false, 'comment-fence'];
        yield 'quoted code keeps comment markers literal' => [false, false, false, 'quoted'];
        yield 'indentation tabs preserve literal HTML and internal payload' => [false, false, false, 'tab-code'];
        yield 'single-line indented code keeps its delivery reference outside literal content' => [
            false,
            false,
            false,
            'indented-single',
        ];
        yield 'nested quoted code and terminal definitions need no delivery metadata' => [
            false,
            false,
            false,
            'nested-definition',
        ];
        yield 'indented recipe tabs and terminal HTML retain independent fragment boundaries' => [
            false,
            false,
            false,
            'presentation-boundaries',
        ];
        yield 'fenced payload tabs and literal references survive explicit and incremental formatting' => [
            false,
            false,
            false,
            'tab-fenced',
        ];
        yield 'multiline inline code keeps references visible' => [false, false, false, 'inline'];
        yield 'multiline HTML comment keeps references visible' => [false, false, false, 'comment'];
        yield 'ordered list has a block boundary after prose' => [false, false, false, 'ordered'];
    }

    /** Restores write access within the exact temporary tree without following symbolic links, then removes it. */
    private static function removeFixture(string $root): void
    {
        $filesystem = new Filesystem();
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ) as $file) {
            if (! $file->isLink()) {
                $filesystem->chmod($file->getPathname(), 0700);
            }
        }
        $filesystem->remove($root);
    }

    /** Whitelists the complete fixed read query; no other POST or GraphQL operation can reach the synthetic API. */
    private static function mergedQuery(): string
    {
        return <<<'GRAPHQL'
query ChangelogMergedPullRequest($owner: String!, $name: String!, $number: Int!) {
  repository(owner: $owner, name: $name) {
    nameWithOwner
    id
    pullRequest(number: $number) {
      number
      state
      merged
      headRefOid
      headRefName
      baseRefName
      mergeCommit { oid }
    }
  }
}
GRAPHQL;
    }
}
