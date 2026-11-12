<?php

declare(strict_types=1);

namespace FastForward\Changelog\Git;

use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Process\Factory\ProcessFactoryInterface;
use Random\Randomizer;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Limits filtered Git objects to one private operation without changing the consumer's object DB or config. */
final readonly class GitObjectScope
{
    /**
     * Injects process, filesystem, entropy and canonical temporary-root boundaries without observing host state.
     *
     * @param ProcessFactoryInterface      $processes          Existing credential-filtered process construction boundary.
     * @param Filesystem                   $filesystem         Creates and removes only this operation's private object directory.
     * @param Randomizer                   $random             Injected secure entropy for collision-resistant private directory names.
     * @param string                       $temporaryDirectory Trusted canonical temporary root supplied by composition.
     * @param PackagePathResolverInterface $paths              Pure cross-platform path syntax, independent of the host OS.
     */
    public function __construct(
        private ProcessFactoryInterface $processes,
        private Filesystem $filesystem,
        private Randomizer $random,
        private string $temporaryDirectory,
        private PackagePathResolverInterface $paths,
    ) {}

    /**
     * Reads base objects through alternates, writes partial objects privately, and cleans up on success or failure.
     *
     * @param  string                        $directory Existing consumer Git root, already selected by the native resolver.
     * @param  callable(GitRepository):mixed $operation Exact-head proof executed while private objects exist.
     * @return mixed                         The operation's result; no scoped Git adapter may be used after this callback returns.
     * @throws RuntimeException              When Git's original object directory is unavailable or the private target already exists.
     */
    public function run(string $directory, callable $operation): mixed
    {
        $process = $this->processes->create(
            ['git', '-C', $directory, 'rev-parse', '--path-format=absolute', '--git-path', 'objects'],
        );
        $process->run();
        $original = rtrim($process->getOutput(), "\r\n");
        if (!$process->isSuccessful() || !$this->paths->isAbsolute($original)) {
            throw new RuntimeException('The consumer Git object directory could not be verified.');
        }
        $objects = rtrim($this->temporaryDirectory, '/\\') . '/fast-forward-changelog-objects-' . bin2hex(
            $this->random->getBytes(16),
        );
        if ($this->filesystem->exists($objects)) {
            throw new RuntimeException('The private Git object scope already exists.');
        }
        $this->filesystem->mkdir($objects, 0700);
        try {
            $this->filesystem->mkdir([$objects . '/pack', $objects . '/info'], 0700);
            $environment = [
                'GIT_OBJECT_DIRECTORY' => $objects,
                'GIT_ALTERNATE_OBJECT_DIRECTORIES' => json_encode(
                    $original,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
                'GIT_NO_LAZY_FETCH' => '1', 'GIT_TERMINAL_PROMPT' => '0',
                'GIT_ALLOW_PROTOCOL' => '',
                'GITHUB_TOKEN' => false, 'GH_TOKEN' => false, 'FF_CHANGELOG_TOKEN' => false, 'FF_CHANGELOG_INPUTS' => false,
            ];
            $factory = new readonly class ($this->processes, $environment) implements ProcessFactoryInterface {
                /**
                 * Captures one operation's explicit environment, without changing ambient process state.
                 *
                 * @param ProcessFactoryInterface    $inner       Existing process constructor retaining caller cwd and argv.
                 * @param array<string,string|false> $environment Private Git object and HTTP-only credential policy.
                 */
                public function __construct(
                    private ProcessFactoryInterface $inner,
                    private array $environment,
                ) {}

                /**
                 * Applies the scoped object view to an unstarted process; never configures a remote or lazy fetch.
                 *
                 * @param  list<string> $command Structured argv from the native Git adapter.
                 * @return Process      Unstarted process retaining its original environment plus the bounded private scope.
                 */
                public function create(array $command): Process
                {
                    $process = $this->inner->create($command);
                    $process->setEnv([...($process->getEnv() ?? []), ...$this->environment]);

                    return $process;
                }
            };

            return $operation(new GitRepository($factory, quarantined: true));
        } finally {
            $this->filesystem->chmod($objects, 0700, 0000, true);
            $this->filesystem->remove($objects);
        }
    }
}
