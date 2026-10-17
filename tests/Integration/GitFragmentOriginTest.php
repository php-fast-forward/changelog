<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Integration;

use FastForward\Changelog\Git\GitRepository;
use FastForward\Changelog\Process\Factory\ProcessFactoryInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
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
            new Filesystem()->remove($this->temporary);
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

    public function testNestedConsumerAndGlobNewlineNamesAreLiteral(): void
    {
        $path = "packages/library/.changelog/fix[1]\nname.md";
        $this->write($path, self::BYTES);
        $origin = $this->commit($path);

        self::assertSame($origin, $this->repository()->fragmentOrigin(
            $this->directory . '/packages/library',
            $origin,
            ".changelog/fix[1]\nname.md",
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
        unlink($this->directory . '/.git/objects/' . substr($parent, 0, 2) . '/' . substr($parent, 2));
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
