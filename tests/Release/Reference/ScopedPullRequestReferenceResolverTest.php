<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release\Reference;

use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Filesystem\PackagePathResolver;
use FastForward\Changelog\Git\GitObjectScope;
use FastForward\Changelog\Git\GitRepository;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\GitHub\GitHubFilteredTransportInterface;
use FastForward\Changelog\Process\Factory\ProcessFactoryInterface;
use FastForward\Changelog\Release\Reference\PullRequestReferenceResolver;
use FastForward\Changelog\Release\ReleaseMetadataParser;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Version\VersionImpact;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(PullRequestReferenceResolver::class)]
#[UsesClass(GitObjectScope::class)]
#[UsesClass(GitRepository::class)]
#[UsesClass(PackagePathResolver::class)]
#[UsesClass(ReleaseMetadataParser::class)]
#[UsesClass(GitHubEvidence::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(Category::class)]
#[UsesClass(VersionImpact::class)]
#[UsesClass(ReleaseOptions::class)]
final class ScopedPullRequestReferenceResolverTest extends TestCase
{
    private const string BASE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string MERGE = 'cccccccccccccccccccccccccccccccccccccccc';
    private const string HEAD = 'dddddddddddddddddddddddddddddddddddddddd';
    private const string ROOT = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
    private const string PATH = '.changelog/a.md';
    private const string BLOB = "---\ncategory: fixed\ntype: patch\n---\n\nFix the fixture.\n";
    private const string PACK = 'PACK012345678901234567890123456789';

    /** Native filtered imports are confined to the scope and are rechecked against independent API evidence. */
    #[TestWith([''])]
    #[TestWith(['repository'])]
    #[TestWith(['head'])]
    public function testNativeScopeProvesTheExactOriginalHead(string $changedEvidence): void
    {
        $imported = false;
        $scopedProcesses = 0;
        $paths = new PackagePathResolver('/repo');
        $factory = $this->processes($imported, $scopedProcesses);
        $github = $this->createMockForIntersectionOfInterfaces(
            [GitHubClientInterface::class, GitHubFilteredTransportInterface::class],
        );
        $github->expects(self::once())->method('usesPublicGitHub')->willReturn(true);
        $github->expects(self::once())->method('filteredCommitPack')->with('owner/fixture', self::HEAD)->willReturn(
            self::PACK,
        );
        $github->expects(self::never())->method('commitPack');
        $github->method('request')->willReturnCallback(
            static function (string $method, string $path, ?array $body = null) use (
                &$imported,
                $changedEvidence
            ): ?array {
                $repo = ['id' => 42, 'node_id' => 'opaque-fixture', 'full_name' => 'owner/fixture'];
                $head = $imported && 'head' === $changedEvidence ? str_repeat('f', 40) : self::HEAD;
                if ('GET' === $method && '/repos/owner/fixture' === $path) {
                    return [...$repo, 'id' => $imported && 'repository' === $changedEvidence ? 43 : 42];
                }
                if ('GET' === $method && '/repos/owner/fixture/pulls/108' === $path) {
                    return ['number' => 108, 'state' => 'closed', 'merged' => true, 'changed_files' => 1, 'commits' => 1,
                        'head' => ['sha' => $head, 'ref' => 'implementation'], 'base' => ['ref' => 'main', 'repo' => $repo]];
                }
                if ('POST' === $method && '/graphql' === $path) {
                    self::assertSame(['owner' => 'owner', 'name' => 'fixture', 'number' => 108], $body['variables']);

                    return ['data' => ['repository' => ['id' => $repo['node_id'], 'nameWithOwner' => $repo['full_name'],
                        'pullRequest' => ['number' => 108, 'state' => 'MERGED', 'merged' => true, 'headRefOid' => $head,
                            'headRefName' => 'implementation', 'baseRefName' => 'main', 'mergeCommit' => ['oid' => self::MERGE]]]]];
                }

                throw new RuntimeException('Unexpected fixture API request: ' . $method . ' ' . $path);
            },
        );
        $github->method('paginate')->willReturnCallback(static fn(string $path): array => match ($path) {
            '/repos/owner/fixture/commits/' . self::MERGE . '/pulls' => [['number' => 108]],
            '/repos/owner/fixture/pulls/108/files' => [['filename' => self::PATH, 'status' => 'added', 'sha' => hash(
                'sha1',
                'blob ' . strlen(self::BLOB) . "\0" . self::BLOB,
            )]],
            '/repos/owner/fixture/pulls/108/commits' => [['sha' => self::HEAD]],
            default => throw new RuntimeException('Unexpected fixture API list: ' . $path),
        });
        $random = new Randomizer(new Mt19937(42));
        $objects = '/private-scopes/fast-forward-changelog-objects-' . bin2hex(
            new Randomizer(new Mt19937(42))->getBytes(16),
        );
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())->method('exists')->with($objects)->willReturn(false);
        $created = 0;
        $filesystem->expects(self::exactly(2))->method('mkdir')->willReturnCallback(
            static function (string|iterable $directories, int $mode) use (&$created, $objects): void {
                self::assertSame(0700, $mode);
                self::assertSame(0 === $created++ ? $objects : [$objects . '/pack', $objects . '/info'], $directories);
            },
        );
        $filesystem->expects(self::once())->method('chmod')->with($objects, 0700, 0000, true);
        $filesystem->expects(self::once())->method('remove')->with($objects)->willReturnCallback(static function () use (
            &$imported,
            &$scopedProcesses
        ): void {
            self::assertTrue($imported, 'The modeled original head must be imported before scope cleanup.');
            self::assertGreaterThan(0, $scopedProcesses);
        });
        $scope = new GitObjectScope($factory, $filesystem, $random, '/private-scopes', $paths);
        $resolver = new PullRequestReferenceResolver(new GitRepository($factory), $github, $paths, $scope);
        $change = new Changeset('a.md', Category::Fixed, null, null, null, 'Fix the fixture.', VersionImpact::Patch);
        if ('' !== $changedEvidence) {
            $this->expectExceptionMessage('Automatic implementation PR provenance could not be verified');
        }
        $result = $resolver->resolve(
            new ReleaseOptions('/repo', repository: 'owner/fixture', resolvePullRequests: true),
            self::BASE,
            [$change],
            ['/repo/' . self::PATH => hash('sha256', self::BLOB)],
        );
        self::assertTrue($imported);
        self::assertGreaterThan(0, $scopedProcesses);
        self::assertSame(108, $result[0]->pullRequest);
        self::assertSame($change->description, $result[0]->description);
        self::assertSame($change->id, $result[0]->id);
    }

    /** Supplies the native Git adapter with modeled process results, never starting a process or reading host state. */
    private function processes(bool &$imported, int &$scopedProcesses): ProcessFactoryInterface
    {
        $factory = $this->createMock(ProcessFactoryInterface::class);
        $factory->expects(self::atLeastOnce())->method('create')->willReturnCallback(function (array $command) use (
            &$imported,
            &$scopedProcesses
        ): Process {
            self::assertSame(['git', '-C', '/repo'], array_slice($command, 0, 3));
            $arguments = array_slice($command, 3);
            if (['--no-replace-objects', '--literal-pathspecs'] === array_slice($arguments, 0, 2)) {
                $arguments = array_slice($arguments, 2);
            }
            $success = true;
            if (['rev-parse', '--path-format=absolute', '--git-path', 'objects'] === $arguments) {
                $output = "/repo/.git/objects\n";
            } elseif (['rev-parse', '--show-toplevel'] === $arguments) {
                $output = "/repo\n";
            } elseif (['rev-parse', '--show-prefix'] === $arguments) {
                $output = '';
            } elseif (['rev-parse', '--is-shallow-repository'] === $arguments) {
                $output = "false\n";
            } elseif ('rev-parse' === $arguments[0] && '--verify' === $arguments[1]) {
                $sha = substr($arguments[3], 0, -strlen('^{commit}'));
                self::assertContains($sha, [self::BASE, self::MERGE, self::HEAD, self::ROOT]);
                $success = self::HEAD !== $sha || $imported;
                $output = $sha . "\n";
            } elseif (['remote', 'get-url', 'origin'] === $arguments) {
                $output = "https://github.com/owner/fixture.git\n";
            } elseif ('ls-tree' === $arguments[0]) {
                self::assertSame(self::PATH, $arguments[4]);
                $sha = $arguments[2];
                $output = self::ROOT === $sha ? '' : ('--name-only' === $arguments[1] ? self::PATH . "\n" : '100644 blob ' . hash(
                    'sha1',
                    'blob ' . strlen(self::BLOB) . "\0" . self::BLOB,
                ) . "\t" . self::PATH . "\0");
            } elseif ('show' === $arguments[0] && '-s' === $arguments[1]) {
                self::assertContains('--no-renames', $arguments);
                self::assertContains('--no-notes', $arguments);
                self::assertContains('--no-show-signature', $arguments);
                self::assertContains('--format=%P', $arguments);
                $output = self::ROOT . "\n";
            } elseif ('show' === $arguments[0]) {
                self::assertStringEndsWith(':./' . self::PATH, $arguments[1]);
                $output = self::BLOB;
            } elseif (['cat-file', 'blob'] === array_slice($arguments, 0, 2)) {
                self::assertSame(hash('sha1', 'blob ' . strlen(self::BLOB) . "\0" . self::BLOB), $arguments[2]);
                $output = self::BLOB;
            } elseif ('log' === $arguments[0]) {
                self::assertContains('--no-renames', $arguments);
                self::assertContains('--no-patch', $arguments);
                $output = (in_array(self::HEAD, $arguments, true) ? self::HEAD : self::MERGE) . "\0";
            } elseif (['merge-base', '--is-ancestor'] === array_slice($arguments, 0, 2)) {
                $output = '';
            } elseif ('index-pack' === $arguments[0]) {
                self::assertSame(['index-pack', '--stdin', '--fsck-objects', '--promisor'], $arguments);
                $output = '';
            } elseif ('rev-list' === $arguments[0]) {
                self::assertSame([
                    'rev-list', '--objects', '--filter=blob:none', '--missing=error', self::HEAD, '--'],
                    $arguments,
                );
                $output = self::HEAD . "\n";
            } else {
                throw new RuntimeException('Unexpected modeled Git argv: ' . implode(' ', $arguments));
            }
            $process = $this->createMock(Process::class);
            $process->expects(self::once())->method('run')->willReturnCallback(static function () use (
                &$imported,
                $arguments,
                $success
            ): int {
                if ('index-pack' === $arguments[0]) {
                    $imported = true;
                }

                return $success ? 0 : 1;
            });
            $process->expects(self::once())->method('isSuccessful')->willReturn($success);
            $process->method('getOutput')->willReturn($output);
            $process->method('getErrorOutput')->willReturn('Synthetic missing original head.');
            $process->method('getEnv')->willReturn(['FIXTURE_PRESERVED' => 'value', 'GH_TOKEN' => 'synthetic-secret']);
            $process->method('setEnv')->willReturnCallback(static function (array $environment) use (
                &$scopedProcesses,
                $process
            ): Process {
                ++$scopedProcesses;
                self::assertSame('value', $environment['FIXTURE_PRESERVED']);
                self::assertSame('1', $environment['GIT_NO_LAZY_FETCH']);
                self::assertSame('', $environment['GIT_ALLOW_PROTOCOL']);
                self::assertSame('0', $environment['GIT_TERMINAL_PROMPT']);
                self::assertSame('"/repo/.git/objects"', $environment['GIT_ALTERNATE_OBJECT_DIRECTORIES']);
                self::assertStringStartsWith(
                    '/private-scopes/fast-forward-changelog-objects-',
                    $environment['GIT_OBJECT_DIRECTORY'],
                );
                self::assertFalse($environment['GH_TOKEN']);
                self::assertFalse($environment['GITHUB_TOKEN']);
                self::assertFalse($environment['FF_CHANGELOG_TOKEN']);
                self::assertFalse($environment['FF_CHANGELOG_INPUTS']);

                return $process;
            });
            if ('index-pack' === $arguments[0]) {
                $process->expects(self::once())->method('setInput')->with(self::PACK)->willReturnSelf();
            }

            return $process;
        });

        return $factory;
    }
}
