<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Integration;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Filesystem\PackagePathResolver;
use FastForward\Changelog\Git\GitRepository;
use FastForward\Changelog\GitHub\GitHubClient;
use FastForward\Changelog\Process\Factory\ProcessFactoryInterface;
use FastForward\Changelog\Release\Reference\PullRequestReferenceResolver;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Version\VersionImpact;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

#[CoversNothing]
#[Group('windows-provenance')]
final class GitFragmentOriginTest extends TestCase
{
    private string $temporary;
    private string $directory;
    private array $environment;
    private const string PATH = '.changelog/fix.md';
    private const string BYTES = "---\ncategory: fixed\ntype: patch\n---\n\nExact current fragment.  \n";

    protected function setUp(): void
    {
        $this->temporary = realpath(sys_get_temp_dir()) . '/changelog-origin-' . bin2hex(random_bytes(8));
        $this->directory = $this->temporary . '/repository';
        mkdir($this->directory, 0700, true);
        mkdir($this->temporary . '/home', 0700);
        $this->environment = [
            'HOME' => $this->temporary . '/home',
            'XDG_CONFIG_HOME' => $this->temporary . '/home',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_AUTHOR_NAME' => 'Synthetic Fixture',
            'GIT_AUTHOR_EMAIL' => 'fixture@example.invalid',
            'GIT_COMMITTER_NAME' => 'Synthetic Fixture',
            'GIT_COMMITTER_EMAIL' => 'fixture@example.invalid',
            'GIT_AUTHOR_DATE' => '2026-10-17T12:00:00+00:00',
            'GIT_COMMITTER_DATE' => '2026-10-17T12:00:00+00:00',
            'GIT_CONFIG_COUNT' => false,
            'GIT_CONFIG_PARAMETERS' => false,
            'GIT_CONFIG_SYSTEM' => false,
            'GIT_CONFIG' => false,
            'GIT_TEMPLATE_DIR' => false,
            'GIT_SHALLOW_FILE' => false,
            'GIT_NAMESPACE' => false,
            'GIT_DIR' => false,
            'GIT_WORK_TREE' => false,
            'GIT_INDEX_FILE' => false,
            'GIT_OBJECT_DIRECTORY' => false,
            'GIT_ALTERNATE_OBJECT_DIRECTORIES' => false,
            'GIT_COMMON_DIR' => false,
            'GIT_REPLACE_REF_BASE' => false,
            'GIT_GRAFT_FILE' => false,
            'GITHUB_TOKEN' => false,
            'GH_TOKEN' => false,
            'FF_CHANGELOG_TOKEN' => false,
        ];
        $this->git('init', '-b', 'main');
        $this->write('README.md', 'Initial fixture.');
        $this->commit('README.md');
    }

    protected function tearDown(): void
    {
        if (isset($this->temporary)) {
            $filesystem = new Filesystem();
            $filesystem->chmod($this->temporary, 0777, 0000, true);
            $filesystem->remove($this->temporary);
        }
    }

    #[TestWith(['merge'])]
    #[TestWith(['squash'])]
    #[TestWith(['rebase'])]
    #[TestWith(['direct'])]
    public function testIntroductionsFollowTheIntegratedFirstParentHistoryWithoutCommitTextGuessing(
        string $strategy,
    ): void {
        if ('direct' === $strategy) {
            $this->write(self::PATH, self::BYTES);
            $origin = $this->commit(self::PATH);
        } else {
            $this->git('switch', '-c', 'feature');
            $this->write(self::PATH, self::BYTES);
            $featureOrigin = $this->commit(self::PATH);
            $this->write('feature-extra.txt', 'Another change in the same contribution.');
            $this->commit('feature-extra.txt');
            $this->git('switch', 'main');
            $this->write('trunk.txt', 'A concurrent trunk change.');
            $this->commit('trunk.txt');
            if ('rebase' === $strategy) {
                $this->git('switch', 'feature');
                $this->git('rebase', 'main');
                $origin = trim($this->git('log', '-1', '--format=%H', '--', self::PATH));
                $this->git('switch', 'main');
                $this->git('merge', '--ff-only', 'feature');
                self::assertNotSame($origin, $this->head());
            } elseif ('squash' === $strategy) {
                $this->git('merge', '--squash', 'feature');
                $this->git('commit', '-m', 'Untrusted prose mentioning #9999 is not origin evidence.');
                $origin = $this->head();
            } else {
                $this->git('merge', '--no-ff', 'feature', '-m', 'Unrelated editable title #9999.');
                $origin = $this->head();
            }
            self::assertNotSame($featureOrigin, $origin);
        }
        $this->write('later.txt', 'A later unrelated change.');
        $base = $this->commit('later.txt');
        $this->write('README.md', 'Unrelated dirty state must not prevent this path proof.');
        $repository = $this->repository();

        self::assertSame(
            $origin,
            $repository->fragmentOrigin($this->directory, $base, self::PATH, hash('sha256', self::BYTES)),
        );
        $this->git('rm', '--', self::PATH);
        $this->git('commit', '-m', 'Consume the fragment in a later committed release.');
        self::assertFileDoesNotExist($this->directory . '/' . self::PATH);
        self::assertSame(
            $origin,
            $repository->fragmentOrigin($this->directory, $base, self::PATH, hash('sha256', self::BYTES)),
        );
        $this->write(self::PATH, 'A new unrelated untracked fragment with the same path.');
        self::assertSame(
            $origin,
            $repository->fragmentOrigin($this->directory, $base, self::PATH, hash('sha256', self::BYTES)),
        );
        $this->git('add', '--', self::PATH);
        self::assertSame(
            $origin,
            $repository->fragmentOrigin($this->directory, $base, self::PATH, hash('sha256', self::BYTES)),
        );
    }

    /** A full main-only clone lacks squash heads; exact acquisition restores proof without changing refs or files. */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testMissingSquashedHeadIsAcquiredOnlyAfterApiVerification(bool $denied): void
    {
        $this->git('switch', '-c', 'feature');
        $this->write(self::PATH, self::BYTES);
        $head = $this->commit(self::PATH);
        $this->git('switch', 'main');
        $this->git('merge', '--squash', 'feature');
        $this->git('commit', '-m', 'Squash implementation');
        $merge = $this->head();
        $clone = $this->temporary . '/clone';
        $this->git('clone', '--no-local', '--single-branch', '--branch=main', $this->directory, $clone);
        $this->process(['git', '-C', $clone, 'remote', 'set-url', 'origin', 'https://github.com/owner/project.git']);
        $probe = new Process(['git', '-C', $clone, 'cat-file', '-e', $head], $clone, $this->environment);
        self::assertNotSame(0, $probe->run());
        $refs = $this->process(['git', '-C', $clone, 'show-ref']);
        $factory = new readonly class (
            $clone,
            $this->environment,
        ) implements ProcessFactoryInterface {
            public function __construct(
                private string $clone,
                private array $environment,
            ) {}

            public function create(array $command): Process
            {
                TestCase::assertStringNotContainsString('synthetic-private-token', implode(' ', $command));

                return new Process($command, $this->clone, $this->environment);
            }
        };
        $repo = ['id' => 42, 'node_id' => 'synthetic-node', 'full_name' => 'owner/project'];
        $pr = ['number' => 94, 'state' => 'closed', 'merged' => true, 'changed_files' => 1, 'commits' => 1,
            'head' => ['sha' => $head, 'ref' => 'feature'], 'base' => ['ref' => 'main', 'repo' => $repo]];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (
            $head,
            $merge,
            $repo,
            $pr,
            $denied
        ): MockResponse {
            self::assertStringNotContainsString('synthetic-private-token', $url);
            if (str_starts_with($url, 'https://github.com/owner/project.git/')) {
                self::assertSame([
                    'Authorization: Basic ' . base64_encode('x-access-token:synthetic-private-token')],
                    $options['normalized_headers']['authorization'],
                );
                self::assertSame(0, $options['max_redirects']);
                if ($denied) {
                    return new MockResponse('synthetic-private-token raw error', ['http_code' => 401]);
                }
                if ('GET' === $method) {
                    $bytes = "001e# service=git-upload-pack\n0000"
                        . $this->process(
                            ['git', 'upload-pack', '--stateless-rpc', '--advertise-refs', $this->directory],
                        );
                    $type = 'advertisement';
                } else {
                    $want = 'want ' . $head . "\n";
                    self::assertSame(sprintf('%04x', strlen($want) + 4) . $want . "00000009done\n", $options['body']);
                    $bytes = $this->process(
                        ['git', 'upload-pack', '--stateless-rpc', $this->directory],
                        $options['body'],
                    );
                    $type = 'result';
                }

                return new MockResponse($bytes, ['response_headers' => ['content-type' => 'application/x-git-upload-pack-' . $type]]);
            }
            self::assertSame([
                'Authorization: Bearer synthetic-private-token'],
                $options['normalized_headers']['authorization'],
            );
            $path = substr($url, strlen('https://api.github.com'));
            $path = explode('?', $path)[0];
            $data = match ($path) {
                '/repos/owner/project' => $repo,
                '/repos/owner/project/pulls/94' => $pr,
                '/graphql' => ['data' => ['repository' => ['id' => $repo['node_id'], 'nameWithOwner' => $repo['full_name'],
                    'pullRequest' => ['number' => 94, 'state' => 'MERGED', 'merged' => true, 'headRefOid' => $head,
                        'headRefName' => 'feature', 'baseRefName' => 'main', 'mergeCommit' => ['oid' => $merge]]]]],
                '/repos/owner/project/commits/' . $merge . '/pulls' => [['number' => 94]],
                '/repos/owner/project/pulls/94/commits' => [['sha' => $head]],
                '/repos/owner/project/pulls/94/files' => [['filename' => self::PATH, 'status' => 'added',
                    'sha' => hash('sha1', 'blob ' . strlen(self::BYTES) . "\0" . self::BYTES)]],
                default => throw new RuntimeException('Unexpected synthetic API request'),
            };

            return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR));
        });
        $github = new GitHubClient($http, 'synthetic-private-token');
        $resolver = new PullRequestReferenceResolver(
            new GitRepository($factory), $github, new PackagePathResolver($clone),
        );
        try {
            $result = $resolver->resolve(new ReleaseOptions(
                $clone, repository: 'owner/project', resolvePullRequests: true,
            ), $merge, [new Changeset(
                'fix.md', Category::Fixed, null, null, null, 'Exact current fragment.',
                VersionImpact::Patch,
            )]);
            self::assertFalse($denied);
            self::assertSame(94, $result[0]->pullRequest);
        } catch (RuntimeException $failure) {
            self::assertTrue($denied);
            self::assertStringContainsString('details were withheld', $failure->getMessage());
            self::assertStringNotContainsString('synthetic-private-token', $failure->getMessage());
        }
        self::assertSame($refs, $this->process(['git', '-C', $clone, 'show-ref']));
        self::assertSame($merge, trim($this->process(['git', '-C', $clone, 'rev-parse', 'HEAD'])));
        self::assertSame('', $this->process(['git', '-C', $clone, 'status', '--porcelain']));
        self::assertSame(self::BYTES, file_get_contents($clone . '/' . self::PATH));
        self::assertFileDoesNotExist($clone . '/.git/FETCH_HEAD');
        self::assertStringNotContainsString('synthetic-private-token', file_get_contents($clone . '/.git/config'));
    }

    /** A root PR can contain an implementation merge without being the fragment's own implementation. */
    #[TestWith([''])]
    #[TestWith(['package/'])]
    public function testStackedMergePreservesIntegrationOriginButRefusesUmbrellaImplementationAttribution(
        string $prefix,
    ): void {
        $this->git('switch', '-c', 'umbrella');
        $this->write('umbrella.txt', 'Root integration work.');
        $originalUmbrella = $this->commit('umbrella.txt');
        $this->git('switch', '-c', 'implementation');
        $this->write($prefix . self::PATH, self::BYTES);
        $implementation = $this->commit($prefix . self::PATH);
        $this->write('later.txt', 'Another reviewed implementation change.');
        $implementationHead = $this->commit('later.txt');
        $this->git('switch', 'umbrella');
        $this->git('merge', '--no-ff', 'implementation', '-m', 'Nested implementation, misleading #9999.');
        $umbrellaHead = $this->head();
        $this->git('switch', 'main');
        $this->git('merge', '--no-ff', 'umbrella', '-m', 'Integrate the complete stack.');
        $merge = $this->head();
        $repository = $this->repository();
        $hash = hash('sha256', self::BYTES);
        $directory = $this->directory . ('' === $prefix ? '' : '/' . rtrim($prefix, '/'));

        self::assertSame($merge, $repository->fragmentOrigin($directory, $merge, self::PATH, $hash));
        self::assertSame(
            $implementation,
            $repository->fragmentOrigin($directory, $implementationHead, self::PATH, $hash),
        );
        self::assertTrue(
            $repository->fragmentHasDirectOrigin($directory, $implementationHead, self::PATH, $hash),
        );
        self::assertFalse($repository->fragmentHasDirectOrigin($directory, $originalUmbrella, self::PATH, $hash));
        self::assertFalse($repository->fragmentHasDirectOrigin($directory, $umbrellaHead, self::PATH, $hash));
        self::assertFalse($repository->fragmentHasDirectOrigin($directory, $merge, self::PATH, $hash));

        $repo = ['id' => 42, 'node_id' => 'synthetic-node', 'full_name' => 'owner/project'];
        $pr = ['number' => 94, 'state' => 'closed', 'merged' => true, 'changed_files' => 1, 'commits' => 4,
            'head' => ['sha' => $umbrellaHead, 'ref' => 'umbrella'], 'base' => ['ref' => 'main', 'repo' => $repo]];
        $http = new MockHttpClient(static function (string $method, string $url) use (
            $repo, $pr, $merge, $prefix, $originalUmbrella, $implementation, $implementationHead, $umbrellaHead,
        ): MockResponse {
            $path = explode('?', substr($url, strlen('https://api.github.com')))[0];
            $data = match ($path) {
                '/repos/owner/project' => $repo,
                '/repos/owner/project/commits/' . $merge . '/pulls' => [['number' => 94]],
                '/repos/owner/project/pulls/94' => $pr,
                '/graphql' => ['data' => ['repository' => ['id' => $repo['node_id'], 'nameWithOwner' => $repo['full_name'],
                    'pullRequest' => ['number' => 94, 'state' => 'MERGED', 'merged' => true, 'headRefOid' => $umbrellaHead,
                        'headRefName' => 'umbrella', 'baseRefName' => 'main', 'mergeCommit' => ['oid' => $merge]]]]],
                '/repos/owner/project/pulls/94/files' => [['filename' => $prefix . self::PATH, 'status' => 'added',
                    'sha' => hash('sha1', 'blob ' . strlen(self::BYTES) . "\0" . self::BYTES)]],
                '/repos/owner/project/pulls/94/commits' => array_map(static fn(string $sha): array => ['sha' => $sha],
                    [$originalUmbrella, $implementation, $implementationHead, $umbrellaHead]),
                default => throw new RuntimeException('Unexpected synthetic API request'),
            };

            return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR));
        });
        $change = new Changeset(
            'fix.md',
            Category::Fixed,
            null,
            null,
            null,
            'Exact current fragment.',
            VersionImpact::Patch,
        );
        $resolver = new PullRequestReferenceResolver(
            $repository, new GitHubClient($http, 'synthetic-token'), new PackagePathResolver($directory),
        );
        self::assertSame([$change], $resolver->resolve(new ReleaseOptions(
            $directory, repository: 'owner/project', resolvePullRequests: true,
        ), $merge, [$change]));
        self::assertNull($change->pullRequest);
        $this->write($prefix . self::PATH, 'Untracked edits cannot change immutable attribution.');
        self::assertFalse($repository->fragmentHasDirectOrigin($directory, $umbrellaHead, self::PATH, $hash));
    }

    public function testDeletionReintroductionAndRenamesStartOriginsAtTheCurrentCanonicalPath(): void
    {
        $this->write(self::PATH, self::BYTES);
        $first = $this->commit(self::PATH);
        $this->git('rm', '--', self::PATH);
        $this->git('commit', '-m', 'Remove the old path.');
        $this->write(self::PATH, self::BYTES);
        $reintroduced = $this->commit(self::PATH);
        self::assertNotSame($first, $reintroduced);
        self::assertSame($reintroduced, $this->origin(self::PATH));
        $this->git('mv', self::PATH, '.changelog/renamed.md');
        $this->git('commit', '-m', 'Rename into another canonical path.');
        $renamed = $this->head();
        self::assertSame($renamed, $this->origin('.changelog/renamed.md'));
        self::assertNull($this->origin(self::PATH));
        $this->git('mv', '.changelog/renamed.md', self::PATH);
        $this->git('commit', '-m', 'Restore the original path by rename.');

        self::assertSame($this->head(), $this->origin(self::PATH));
        self::assertNotSame($reintroduced, $this->origin(self::PATH));
    }

    public function testRestoringOldBytesStartsANewOriginWhileRegularModeChangesDoNot(): void
    {
        $this->write(self::PATH, self::BYTES);
        $first = $this->commit(self::PATH);
        $this->write(self::PATH, 'Different committed bytes.');
        $this->commit(self::PATH);
        $this->write(self::PATH, self::BYTES);
        $restored = $this->commit(self::PATH);
        self::assertNotSame($first, $restored);
        self::assertSame($restored, $this->origin(self::PATH));
        $this->git('update-index', '--chmod=+x', '--', self::PATH);
        $this->git('commit', '-m', 'Regular executable mode only.');
        chmod($this->directory . '/' . self::PATH, 0755);

        self::assertSame($restored, $this->origin(self::PATH));
    }

    public function testLocalChangesCannotAlterTheSelectedCommittedOrigin(): void
    {
        $this->write(self::PATH, self::BYTES);
        self::assertNull($this->origin(self::PATH));
        $this->git('add', '--', self::PATH);
        self::assertNull($this->origin(self::PATH));
        $this->git('commit', '-m', 'A committed fragment.');
        $base = $this->head();
        $this->write(self::PATH, 'Unstaged changed bytes.');
        self::assertSame($base, $this->origin(self::PATH));
        $this->git('add', '--', self::PATH);
        $this->write(self::PATH, self::BYTES);
        self::assertSame(
            $base,
            $this->repository()->fragmentOrigin($this->directory, $base, self::PATH, hash('sha256', self::BYTES)),
        );
        $this->git('reset', '--hard', 'HEAD');
        self::assertNull(
            $this->repository()->fragmentOrigin($this->directory, $base, self::PATH, hash(
                'sha256',
                'mismatching accepted bytes',
            )),
        );
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testNestedConsumerAndGlobNamesAreLiteral(bool $newline): void
    {
        if ($newline && 'Windows' === PHP_OS_FAMILY) {
            self::markTestSkipped('Windows filenames cannot contain control characters.');
        }

        $name = 'fix[1]' . ($newline ? "\n" : '') . 'name.md';
        $path = 'packages/library/.changelog/' . $name;
        $this->write($path, self::BYTES);
        $origin = $this->commit($path);

        self::assertSame($origin, $this->repository()->fragmentOrigin(
            $this->directory . '/packages/library',
            $origin,
            '.changelog/' . $name,
            hash('sha256', self::BYTES),
        ));
    }

    public function testShallowHistoryIsRefusedEvenWhenItsBoundaryContainsTheExactBytes(): void
    {
        $this->write(self::PATH, self::BYTES);
        $base = $this->commit(self::PATH);
        $clone = $this->temporary . '/shallow';
        $this->git('clone', '--no-local', '--depth=1', $this->directory, $clone);
        $this->expectExceptionMessage('complete non-shallow Git history');

        $this->repository()->fragmentOrigin($clone, $base, self::PATH, hash('sha256', self::BYTES));
    }

    public function testMissingFirstParentObjectCannotPretendTheBoundaryWasARootIntroduction(): void
    {
        $parent = $this->head();
        $this->write(self::PATH, self::BYTES);
        $base = $this->commit(self::PATH);
        $object = $this->directory . '/.git/objects/' . substr($parent, 0, 2) . '/' . substr($parent, 2);
        chmod($object, 0600);
        self::assertTrue(unlink($object));
        $this->expectException(RuntimeException::class);

        $this->repository()->fragmentOrigin($this->directory, $base, self::PATH, hash('sha256', self::BYTES));
    }

    #[TestWith([1000])]
    #[TestWith([1001])]
    public function testPathChangeLimitHasAnExplicitOverflowSentinel(int $changes): void
    {
        $input = '';
        $initial = $this->head();
        for ($index = 1; $index <= $changes; ++$index) {
            $bytes = 'fragment-' . $index;
            $parent = 1 === $index ? $initial : ':' . ($index - 1);
            $input .= "commit refs/heads/main\nmark :" . $index
                . "\ncommitter Fixture <fixture@example.invalid> 1792238400 +0000\ndata 6\nchange\nfrom " . $parent . "\n"
                . 'M 100644 inline ' . self::PATH . "\ndata " . strlen($bytes) . "\n" . $bytes . "\n\n";
        }
        $this->process(['git', '-C', $this->directory, 'fast-import', '--quiet'], $input);
        $this->git('reset', '--hard', 'main');
        if ($changes > 1000) {
            $this->expectExceptionMessage('exceeds 1000 path changes');
        }
        $origin = $this->repository()->fragmentOrigin(
            $this->directory,
            $this->head(),
            self::PATH,
            hash('sha256', 'fragment-' . $changes),
        );

        self::assertSame($this->head(), $origin);
    }

    private function origin(string $path): ?string
    {
        return $this->repository()->fragmentOrigin($this->directory, $this->head(), $path, hash('sha256', self::BYTES));
    }

    private function repository(): GitRepository
    {
        $factory = new readonly class ($this->directory, $this->environment) implements ProcessFactoryInterface {
            public function __construct(
                private string $directory,
                private array $environment,
            ) {}

            public function create(array $command): Process
            {
                return new Process($command, $this->directory, $this->environment);
            }
        };

        return new GitRepository($factory);
    }

    private function write(string $path, string $bytes): void
    {
        $parent = dirname($this->directory . '/' . $path);
        if (! is_dir($parent)) {
            mkdir($parent, 0700, true);
        }
        file_put_contents($this->directory . '/' . $path, $bytes);
    }

    private function commit(string $path): string
    {
        $this->git('--literal-pathspecs', 'add', '--', $path);
        $this->git('commit', '-m', 'Synthetic editable prose #9999.');

        return $this->head();
    }

    private function head(): string
    {
        return trim($this->git('rev-parse', 'HEAD'));
    }

    private function git(string ...$arguments): string
    {
        return $this->process(['git', '-C', $this->directory, ...$arguments]);
    }

    private function process(array $command, ?string $input = null): string
    {
        $process = new Process($command, $this->directory, $this->environment, $input);
        $process->setTimeout(30);
        $process->mustRun();

        return $process->getOutput();
    }
}
