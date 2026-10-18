<?php

declare(strict_types=1);

namespace FastForward\Changelog\Installation;

use Closure;
use FastForward\Changelog\Filesystem\Factory\FinderFactoryInterface;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Process\Factory\ProcessFactoryInterface;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/** Creates a complete caller without replacing a concurrent human or installer-owned file. */
final readonly class InstallationFilesystem implements InstallationFilesystemInterface
{
    /**
     * Captures replaceable local boundaries; construction performs no filesystem or Git operation.
     *
     * @param Filesystem                   $filesystem Directory, link, staging and cleanup operations.
     * @param ManagedFileStoreInterface    $files      Guarded regular-file reads.
     * @param PackagePathResolverInterface $paths      Deterministic consumer path composition.
     * @param FinderFactoryInterface       $finders    Fresh non-recursive workflow inventory.
     * @param GitRepositoryInterface       $git        Existing Git worktree discovery.
     * @param ProcessFactoryInterface      $processes  Read-only local symbolic-ref process construction.
     * @param Closure(string,string):bool  $createLink Exclusive native hard-link operation; MUST NOT remove existing targets.
     */
    public function __construct(
        private Filesystem $filesystem,
        private ManagedFileStoreInterface $files,
        private PackagePathResolverInterface $paths,
        private FinderFactoryInterface $finders,
        private GitRepositoryInterface $git,
        private ProcessFactoryInterface $processes,
        private Closure $createLink,
    ) {}

    /**
     * Resolves a repository and its local origin/HEAD, falling back visibly to main when that local ref is absent.
     *
     * @param  string                                                                                    $cwd Explicit project directory; no package-cache inference is performed.
     * @return array{root:string,base_branch:string,files:array<string,string>,diagnostics:list<string>} Read-only snapshot.
     * @throws RuntimeException                                                                          When the directory is not a safe Git worktree or workflows cannot be inventoried.
     */
    public function inspect(string $cwd): array
    {
        $directory = $this->paths->absolutePath($cwd);
        $this->guard($directory);
        if (! $this->git->isRepository($directory)) {
            throw new RuntimeException('Project installation requires an existing Git repository.');
        }

        $root = $this->git->repositoryRoot($directory);
        $this->guard($root);
        $process = $this->processes->create(
            ['git', '-C', $root, 'symbolic-ref', '--quiet', '--short', 'refs/remotes/origin/HEAD'],
        );
        $process->run();
        $branch = trim($process->getOutput());
        $known = $process->isSuccessful() && str_starts_with($branch, 'origin/') && strlen($branch) > 7;

        return ['root' => $root, 'base_branch' => $known ? substr($branch, 7) : 'main',
            'files' => $this->workflows($root),
            'diagnostics' => $known ? [] : [
                'Local origin/HEAD is unavailable; main is suggested. Confirm the release branch.',
            ]];
    }

    /**
     * Delegates exact metadata reads to the existing guarded regular-file adapter.
     *
     * @param  string           $root Previously inspected absolute project root.
     * @param  string           $path Fixed project-relative metadata path.
     * @return ?string          Exact bytes, or null when the guarded file does not exist.
     * @throws RuntimeException When path syntax, ancestry or readability is unsafe.
     */
    public function read(string $root, string $path): ?string
    {
        return $this->files->read($this->target($root, $path));
    }

    /**
     * Stages on the destination filesystem and uses an exclusive hard link instead of replacement-capable rename.
     *
     * @param  string               $root     Previously inspected absolute project root.
     * @param  string               $path     Fixed project-relative workflow destination.
     * @param  string               $contents Complete validated caller bytes.
     * @param  array<string,string> $expected Previously accepted workflow inventory.
     * @return void                 Completion after publishing and verifying the full file; temporary bytes are always cleaned up.
     * @throws RuntimeException     When the workflow inventory changes or exclusive creation/verification fails.
     */
    public function create(string $root, string $path, string $contents, array $expected): void
    {
        $target = $this->target($root, $path);
        if ($this->workflows($root) !== $expected || null !== $this->files->read($target)) {
            throw new RuntimeException('Workflow configuration changed after inspection; no file was replaced.');
        }

        $this->filesystem->mkdir(Path::getDirectory($target));
        $this->guard($target);
        $stage = $this->filesystem->tempnam(Path::getDirectory($target), '.fast-forward-install-');
        try {
            $this->guard($stage);
            $this->filesystem->dumpFile($stage, $contents);
            if ($this->workflows($root) !== $expected) {
                throw new RuntimeException('Workflow configuration changed before creation; no file was replaced.');
            }

            $this->guard($target);
            if (! @($this->createLink)($stage, $target)) {
                throw new RuntimeException(
                    'The workflow could not be created exclusively; preserve any concurrently created file.',
                );
            }
            if ($this->files->read($target) !== $contents) {
                throw new RuntimeException(
                    'The created workflow changed during verification; inspect it before retrying.',
                );
            }
        } finally {
            $this->filesystem->remove($stage);
        }
    }

    /**
     * Reads immediate YAML workflow files without following symbolic entries or silently ignoring unreadable files.
     *
     * @param  string               $root Guarded absolute project root.
     * @return array<string,string> Byte-sorted project-relative workflow paths and exact contents.
     * @throws RuntimeException     When ancestry, entry type or inventory consistency is unsafe.
     */
    private function workflows(string $root): array
    {
        $directory = $this->target($root, '.github/workflows');
        if (! $this->filesystem->exists($directory)) {
            return [];
        }

        $files = [];
        foreach ($this->finders->create()->in($directory)->depth('== 0')->ignoreDotFiles(
            false,
        )->sortByName() as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('Workflow discovery does not follow symbolic links.');
            }
            $name = $entry->getFilename();
            if (! $entry->isFile() || 1 !== preg_match('/\.ya?ml\z/i', $name)) {
                continue;
            }

            $path = '.github/workflows/' . $name;
            $bytes = $this->read($root, $path);
            if (null === $bytes) {
                throw new RuntimeException(
                    'A workflow disappeared during inspection; retry after reviewing local changes.',
                );
            }
            $files[$path] = $bytes;
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Validates fixed relative paths before composing and guarding their absolute destination.
     *
     * @param  string           $root Previously inspected absolute project root.
     * @param  string           $path Relative metadata or workflow path selected by the owning service.
     * @return string           Guarded absolute target within the explicit project root.
     * @throws RuntimeException When syntax could escape or alias the declared project scope.
     */
    private function target(string $root, string $path): string
    {
        if ('' === $path || $this->paths->isAbsolute($path) || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $path)
            || [] !== array_intersect(explode('/', $path), ['', '.', '..'])) {
            throw new RuntimeException('Installation paths must be relative and remain inside the project.');
        }

        $target = $this->paths->absolutePath($path, $root);
        $this->guard($target);

        return $target;
    }

    /**
     * Rejects symbolic targets and every symbolic ancestor, including Windows drive and share roots.
     *
     * @param  string           $path Absolute composed project path.
     * @return void             All existing ancestry has been checked without creating or resolving links.
     * @throws RuntimeException When lexical syntax or symbolic ancestry violates the installation boundary.
     */
    private function guard(string $path): void
    {
        if (! $this->paths->isAbsolute($path) || str_contains($path, "\0")
            || [] !== array_intersect(explode('/', str_replace('\\', '/', $path)), ['.', '..'])) {
            throw new RuntimeException('Installation requires an absolute path without traversal.');
        }

        do {
            if (null !== $this->filesystem->readlink($path)) {
                throw new RuntimeException('Installation paths and ancestors must not be symbolic links.');
            }
            if (1 === preg_match('~\A(?:[A-Za-z]:/|//[^/]+/[^/]+/?)\z~', $path)) {
                break;
            }
            $unc = str_starts_with($path, '//');
            $parent = ($unc ? '/' : '') . Path::getDirectory($unc ? substr($path, 1) : $path);
            if ($parent === $path) {
                break;
            }
            $path = $parent;
        } while (true);
    }
}
