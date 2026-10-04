<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see       https://github.com/php-fast-forward/changelog
 * @see       https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Git;

use FastForward\Changelog\Git\Factory\ProcessFactoryInterface;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;

/** Runs structured Git argv without shell interpolation or global cwd mutation. */
final readonly class GitRepository implements GitRepositoryInterface
{
    /** Injects process construction so unit tests never start Git. */
    public function __construct(private ProcessFactoryInterface $processes, private ReleaseExceptionFactoryInterface $exceptions) {}

    /** Probes repository availability without treating a non-Git project as an error. */
    public function isRepository(string $directory): bool
    {
        return null !== $this->run($directory, ['rev-parse', '--is-inside-work-tree'], true);
    }

    /** Resolves the repository root so GitHub file APIs cannot accidentally target a nested project's names. */
    public function repositoryRoot(string $directory): string
    {
        $root = rtrim(trim($this->run($directory, ['rev-parse', '--show-toplevel'])), '/\\');
        if ('' === $root) {
            throw $this->exceptions->failure('Git did not return a repository root.');
        }
        return str_replace('\\', '/', $root);
    }

    /**
     * Resolves the first fetch URL and Git URL rewrites without contacting the server.
     * Missing origin is distinct from a failed Git/configuration probe.
     * @see https://git-scm.com/docs/git-remote#Documentation/git-remote.txt-get-url
     */
    public function originUrl(string $directory): ?string
    {
        $process = $this->processes->create(['git', '-C', $directory, 'remote', 'get-url', 'origin']);
        $process->run();
        if (! $process->isSuccessful()) {
            if (2 === $process->getExitCode()) {
                return null;
            }
            throw $this->exceptions->failure('Cannot read the repository-local Git origin: ' . trim($process->getErrorOutput()));
        }
        $url = rtrim($process->getOutput(), "\r\n");
        return '' === $url ? null : $url;
    }

    /** Verifies a commit identity using Git's option separator. */
    public function resolveRef(string $directory, string $reference = 'HEAD'): string
    {
        $this->validateReference($reference);
        $sha = trim($this->run($directory, ['rev-parse', '--verify', '--end-of-options', $reference . '^{commit}']));
        if (1 !== preg_match('/^[0-9a-f]{40}(?:[0-9a-f]{24})?$/D', $sha)) {
            throw $this->exceptions->failure('Git returned an invalid commit identity.');
        }
        return $sha;
    }

    /** Distinguishes unrelated history from a Git execution failure without accepting an invalid ref. */
    public function isAncestor(string $directory, string $ancestor, string $descendant = 'HEAD'): bool
    {
        $base = $this->resolveRef($directory, $ancestor);
        $target = $this->resolveRef($directory, $descendant);
        $process = $this->processes->create(['git', '-C', $directory, 'merge-base', '--is-ancestor', $base, $target]);
        $process->run();
        if ($process->isSuccessful()) {
            return true;
        }
        if (1 === $process->getExitCode()) {
            return false;
        }
        throw $this->exceptions->failure('Git ancestry verification failed: ' . trim($process->getErrorOutput()));
    }

    /** Reads lightweight and annotated identities and keeps the provenance of known dates. */
    public function tags(string $directory): array
    {
        $output = $this->run($directory, ['for-each-ref', '--format=%(refname:strip=2)%00%(objecttype)%00%(taggerdate:iso-strict)%00%(objectname)%00%(*objectname)%00%(*objecttype)', 'refs/tags/']);
        $tags = [];
        foreach (explode("\n", rtrim($output, "\n")) as $line) {
            if ('' === $line) {
                continue;
            }
            $fields = explode("\0", $line);
            if (6 !== count($fields)) {
                throw $this->exceptions->failure('Git returned unsupported tag metadata.');
            }
            if ('commit' !== $fields[1] && ('tag' !== $fields[1] || ! in_array($fields[5], ['commit', 'tag'], true))) {
                continue;
            }
            $sha = 'tag' === $fields[5]
                ? $this->resolveRef($directory, 'refs/tags/' . $fields[0])
                : ('tag' === $fields[1] ? $fields[4] : $fields[3]);
            if (1 !== preg_match('/^[0-9a-f]{40}(?:[0-9a-f]{24})?$/D', $sha)) {
                throw $this->exceptions->failure('Git returned an invalid tag commit identity.');
            }
            $date = 'tag' === $fields[1] && '' !== $fields[2] ? substr($fields[2], 0, 10) : null;
            $tags[] = ['name' => $fields[0], 'sha' => $sha,
                'date' => $date, 'date_source' => null === $date ? null : 'annotated-tag'];
        }
        return $tags;
    }

    /** Retains added, modified, deleted, copied and renamed paths explicitly. */
    public function changesSince(string $directory, string $reference): array
    {
        $base = $this->resolveRef($directory, $reference);
        $raw = $this->run($directory, ['diff', '--no-ext-diff', '--no-textconv', '--relative', '--name-status', '-z', '--find-renames', $base . '...HEAD', '--']);
        $tokens = explode("\0", rtrim($raw, "\0"));
        $changes = [];
        for ($index = 0; $index < count($tokens) && '' !== $tokens[$index]; ++$index) {
            $status = $tokens[$index];
            $first = $tokens[++$index] ?? '';
            $previous = null;
            if (str_starts_with($status, 'R') || str_starts_with($status, 'C')) {
                $previous = $first;
                $first = $tokens[++$index] ?? '';
            }
            if (1 !== preg_match('/^(?:[AMDTUXB]|[RC]\d{1,3})$/D', $status) || '' === $first) {
                throw $this->exceptions->failure('Git returned a malformed changed-path record.');
            }
            $changes[] = ['status' => $status, 'path' => $first, 'previous' => $previous];
        }
        return $changes;
    }

    /** Confirms the revision first and distinguishes a missing tree entry from a Git error. */
    public function readFileAt(string $directory, string $reference, string $path): ?string
    {
        $sha = $this->resolveRef($directory, $reference);
        $this->validatePath($path);
        if ('' === $this->run($directory, ['ls-tree', '--name-only', $sha, '--', $path])) {
            return null;
        }
        return $this->run($directory, ['show', $sha . ':./' . $path]);
    }

    /** Parses NUL-delimited Git tree entries while retaining file modes and unusual path bytes. */
    public function filesAt(string $directory, string $reference, string $path): array
    {
        $sha = $this->resolveRef($directory, $reference);
        $this->validatePath($path);
        $output = $this->run($directory, ['ls-tree', '-r', '-z', $sha, '--', $path]);
        $files = [];
        foreach (explode("\0", rtrim($output, "\0")) as $record) {
            if ('' === $record) {
                continue;
            }
            if (1 !== preg_match('~^([0-9]{6}) (?:blob|commit) (?:[a-f0-9]{40}|[a-f0-9]{64})\t(.+)$~sD', $record, $parts)
                || ($parts[2] !== $path && ! str_starts_with($parts[2], $path . '/'))) {
                throw $this->exceptions->failure('Git returned a malformed committed-path record.');
            }
            $files[] = ['path' => $parts[2], 'mode' => $parts[1]];
        }
        return $files;
    }

    /** Leaves the fragment staged and intact after a commit failure, for explicit recovery. */
    public function commitFragment(string $directory, string $path, string $message): string
    {
        if ('' === trim($message) || '' === $path || str_contains($path, "\0")) {
            throw $this->exceptions->invalid('A fragment path and nonempty commit message are required.');
        }
        $this->run($directory, ['add', '--', $path]);
        $this->run($directory, ['commit', '--only', '--message', $message, '--', $path]);
        return $this->resolveRef($directory);
    }

    /** Rejects empty or option-shaped revisions before starting a process. */
    private function validateReference(string $reference): void
    {
        if ('' === trim($reference) || str_starts_with($reference, '-') || preg_match('/[\x00\r\n]/', $reference)) {
            throw $this->exceptions->invalid('A nonempty Git revision is required.');
        }
    }

    /** Restricts baseline queries to canonical paths within the explicit consumer root. */
    private function validatePath(string $path): void
    {
        if ('' === $path || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '-') || str_starts_with($path, '/')
            || 1 === preg_match('~^[A-Za-z]:~', $path)
            || [] !== array_intersect(explode('/', $path), ['', '.', '..'])) {
            throw $this->exceptions->invalid('Baseline path must identify a canonical project-relative file or directory.');
        }
    }

    /**
     * Executes one argv vector and propagates stderr on failure.
     * @param  list<string> $arguments arguments passed directly to Git
     * @return string|null  null only for an explicitly optional failed probe
     */
    private function run(string $directory, array $arguments, bool $allowFailure = false): ?string
    {
        $process = $this->processes->create(['git', '-C', $directory, ...$arguments]);
        $process->run();
        if (! $process->isSuccessful()) {
            if ($allowFailure) {
                return null;
            }
            throw $this->exceptions->failure('Git failed: ' . trim($process->getErrorOutput()));
        }
        return $process->getOutput();
    }
}
