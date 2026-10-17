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

use FastForward\Changelog\Process\Factory\ProcessFactoryInterface;
use FastForward\Changelog\Release\ReleaseMetadataParser;
use InvalidArgumentException;
use RuntimeException;

/** Runs structured Git argv without shell interpolation or global cwd mutation. */
final readonly class GitRepository implements GitRepositoryInterface, GitCommitTreeReaderInterface
{
    private ReleaseMetadataParser $metadata;

    /**
     * Injects process construction so unit tests never start Git.
     *
     * @param ProcessFactoryInterface $processes Constructor for unstarted structured process using the configured
     *                                           consumer root and filtered automation credentials.
     * @param ?ReleaseMetadataParser  $metadata  Optional strict commit-trailer parser or contextual metadata proven by
     *                                           the approved PR head.
     */
    public function __construct(
        private ProcessFactoryInterface $processes,
        ?ReleaseMetadataParser $metadata = null,
    ) {
        $this->metadata = $metadata ?? new ReleaseMetadataParser();
    }

    /**
     * Probes repository availability without treating a non-Git project as an error.
     *
     * @param  string           $directory Explicit consumer directory used for repository or fragment operations.
     * @return bool             Whether Git recognizes the explicit directory as a working-tree repository.
     * @throws RuntimeException When Git cannot be started; a completed non-Git probe still returns false.
     */
    public function isRepository(string $directory): bool
    {
        return null !== $this->run($directory, ['rev-parse', '--is-inside-work-tree'], true);
    }

    /**
     * Resolves the repository root so GitHub file APIs cannot accidentally target a nested project's names.
     *
     * @param  string           $directory Explicit consumer directory used for repository or fragment operations.
     * @return string           Canonical absolute repository root with POSIX separators.
     * @throws RuntimeException When Git execution or the returned immutable repository evidence cannot be verified.
     */
    public function repositoryRoot(string $directory): string
    {
        $root = rtrim(trim($this->run($directory, ['rev-parse', '--show-toplevel'])), '/\\');

        if ('' === $root) {
            throw new RuntimeException('Git did not return a repository root.');
        }

        return str_replace('\\', '/', $root);
    }

    /**
     * Uses Git's actual private directory so linked worktrees never treat their .git file as a directory.
     *
     * @param  string           $directory Explicit consumer directory used for repository or fragment operations.
     * @return ?string          Private Git-directory recovery-journal path, or null for a non-Git consumer.
     * @throws RuntimeException When Git execution or the returned immutable repository evidence cannot be verified.
     */
    public function journalPath(string $directory): ?string
    {
        if (! $this->isRepository($directory)) {
            return null;
        }
        $path = str_replace(
            '\\',
            '/',
            rtrim($this->run($directory, ['rev-parse', '--absolute-git-dir']), "\r\n/\\"),
        );

        if ('' === $path || str_contains($path, "\0") || in_array('..', explode('/', $path), true)
            || in_array('.', explode('/', $path), true)
            || (! str_starts_with($path, '/') && 1 !== preg_match('~^[A-Za-z]:/~', $path))) {
            throw new RuntimeException('Git did not return a safe absolute private directory.');
        }

        return $path . '/changelog-release-plan.json';
    }

    /**
     * Returns only complete scalar evidence whose generated output matches the exact approved central blob.
     *
     * @param  string                   $directory     Explicit consumer directory used for repository or fragment operations.
     * @param  string                   $reference     Git revision to resolve within the explicit consumer repository.
     * @param  string                   $changelogFile Canonical project-relative path of the single central history blob.
     * @return ?array                   Unique complete transaction trailers matching the approved central blob, or null when absent.
     * @throws RuntimeException         When Git execution or the returned immutable repository evidence cannot be verified.
     * @throws InvalidArgumentException When a supplied revision cannot be accepted before the immutable Git query.
     */
    public function releaseMetadata(
        string $directory,
        string $reference,
        string $changelogFile = 'CHANGELOG.md',
    ): ?array {
        $sha = $this->resolveRef($directory, $reference);
        $direct = $this->metadata->parse($this->run($directory, ['show', '-s', '--format=%B', $sha, '--']));
        $central = $this->readFileAt($directory, $sha, $changelogFile);

        if (null === $central) {
            return null;
        }
        $output = hash('sha256', $central);

        if (null !== $direct) {
            if (! hash_equals($direct['output_sha256'], $output)) {
                throw new RuntimeException('Release commit metadata differs from its approved changelog blob.');
            }

            return $direct;
        }
        $parents = trim($this->run($directory, ['show', '-s', '--format=%P', $sha, '--']));
        $parents = '' === $parents ? [] : explode(' ', $parents);

        foreach ($parents as $parent) {
            if (1 !== preg_match('~^(?:[a-f0-9]{40}|[a-f0-9]{64})$~D', $parent)) {
                throw new RuntimeException('Git returned malformed release commit parents.');
            }
        }

        if (count($parents) < 2) {
            return null;
        }
        // The current generated head may supersede an older plan with identical Markdown bytes.
        // Exact matching direct parents identify the merged transaction before ancestor fallback.
        $selected = null;

        foreach ($parents as $parent) {
            $candidate = $this->metadata->parse($this->run(
                $directory,
                ['show', '-s', '--format=%B', $parent, '--'],
            ));

            if (null === $candidate || ! hash_equals($candidate['output_sha256'], $output)) {
                continue;
            }

            if (null !== $selected && $selected !== $candidate) {
                throw new RuntimeException(
                    'The approved merge has conflicting matching direct-parent release metadata.',
                );
            }
            $selected = $candidate;
        }

        if (null !== $selected) {
            return $selected;
        }
        $raw = trim(
            $this->run(
                $directory,
                ['rev-list', '--topo-order', '--max-count=101', $parents[0] . '..' . $sha, '--'],
            ),
        );
        $commits = '' === $raw ? [] : explode("\n", $raw);

        if (count($commits) > 100) {
            throw new RuntimeException('Merged release evidence exceeds the bounded commit inventory.');
        }
        $selected = null;

        foreach ($commits as $commit) {
            if (1 !== preg_match('~^(?:[a-f0-9]{40}|[a-f0-9]{64})$~D', $commit)) {
                throw new RuntimeException('Git returned malformed merged release evidence.');
            }
            $candidate = $this->metadata->parse($this->run(
                $directory,
                ['show', '-s', '--format=%B', $commit, '--'],
            ));

            if (null === $candidate || ! hash_equals($candidate['output_sha256'], $output)) {
                continue;
            }

            if (null !== $selected && $selected !== $candidate) {
                throw new RuntimeException('The approved merge contains ambiguous matching release metadata.');
            }
            $selected = $candidate;
        }

        return $selected;
    }

    /**
     * Binds an existing complete commit identity to its complete immutable tree without reading a working file.
     *
     * @param  string                   $directory Explicit consumer directory used for repository or fragment operations.
     * @param  string                   $reference Git revision to resolve within the explicit consumer repository.
     * @return string                   Complete immutable Git tree identity for the selected commit.
     * @throws RuntimeException         When Git execution or the returned immutable repository evidence cannot be verified.
     * @throws InvalidArgumentException When a supplied revision cannot be accepted before the immutable Git query.
     */
    public function treeSha(string $directory, string $reference): string
    {
        $sha = $this->resolveRef($directory, $reference);
        $tree = trim($this->run($directory, ['rev-parse', '--verify', '--end-of-options', $sha . '^{tree}']));

        if (1 !== preg_match('/^[0-9a-f]{40}(?:[0-9a-f]{24})?$/D', $tree)) {
            throw new RuntimeException('Git returned an invalid complete tree identity.');
        }

        return $tree;
    }

    /**
     * Resolves the first fetch URL and Git URL rewrites without contacting the server.
     *
     * Missing origin is distinct from a failed Git/configuration probe.
     *
     * @param  string  $directory Explicit consumer directory used for repository or fragment operations.
     * @return ?string First rewritten fetch URL for origin, or null when that remote is absent.
     * @see https://git-scm.com/docs/git-remote#Documentation/git-remote.txt-get-url
     * @throws RuntimeException When Git execution or the returned immutable repository evidence cannot be verified.
     */
    public function originUrl(string $directory): ?string
    {
        $process = $this->processes->create(['git', '-C', $directory, 'remote', 'get-url', 'origin']);
        $process->run();

        if (! $process->isSuccessful()) {
            if (2 === $process->getExitCode()) {
                return null;
            }

            throw new RuntimeException(
                'Cannot read the repository-local Git origin: ' . trim($process->getErrorOutput()),
            );
        }
        $url = rtrim($process->getOutput(), "\r\n");

        return '' === $url ? null : $url;
    }

    /**
     * Verifies a commit identity using Git's option separator.
     *
     * @param  string                   $directory Explicit consumer directory used for repository or fragment operations.
     * @param  string                   $reference Git revision to resolve within the explicit consumer repository.
     * @return string                   Complete SHA-1 or SHA-256 commit identity, never an abbreviated reference.
     * @throws RuntimeException         When Git execution or the returned immutable repository evidence cannot be verified.
     * @throws InvalidArgumentException When the supplied revision, managed path or commit input is not acceptable.
     */
    public function resolveRef(string $directory, string $reference = 'HEAD'): string
    {
        $this->validateReference($reference);
        $sha = trim($this->run(
            $directory,
            ['rev-parse', '--verify', '--end-of-options', $reference . '^{commit}'],
        ));

        if (1 !== preg_match('/^[0-9a-f]{40}(?:[0-9a-f]{24})?$/D', $sha)) {
            throw new RuntimeException('Git returned an invalid commit identity.');
        }

        return $sha;
    }

    /**
     * Distinguishes unrelated history from a Git execution failure without accepting an invalid ref.
     *
     * @param  string                   $directory  Explicit consumer directory used for repository or fragment operations.
     * @param  string                   $ancestor   Git revision whose ancestry must be established before accepting the descendant.
     * @param  string                   $descendant Git revision that must contain the selected ancestor in its commit history.
     * @return bool                     Whether the verified descendant contains the verified ancestor.
     * @throws RuntimeException         When Git execution or the returned immutable repository evidence cannot be verified.
     * @throws InvalidArgumentException When a supplied revision cannot be accepted before the immutable Git query.
     */
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

        throw new RuntimeException('Git ancestry verification failed: ' . trim($process->getErrorOutput()));
    }

    /**
     * Reads lightweight and annotated identities and keeps the provenance of known dates.
     *
     * @param  string                                                               $directory Explicit consumer directory used for repository or fragment operations.
     * @return list<array{name:string,sha:string,date:?string,date_source:?string}> Complete tag records with
     *                                                                              dereferenced commit identities and
     *                                                                              annotated-date provenance.
     * @throws RuntimeException                                                     When Git execution or the returned immutable repository evidence cannot be verified.
     */
    public function tags(string $directory): array
    {
        $output = $this->run(
            $directory,
            [
                'for-each-ref',
                '--format=%(refname:strip=2)%00%(objecttype)%00%(taggerdate:iso-strict)%00%(objectname)%00%(*objectname)%00%(*objecttype)',
                'refs/tags/',
            ],
        );
        $tags = [];

        foreach (explode("\n", rtrim($output, "\n")) as $line) {
            if ('' === $line) {
                continue;
            }
            $fields = explode("\0", $line);

            if (6 !== count($fields)) {
                throw new RuntimeException('Git returned unsupported tag metadata.');
            }

            if ('commit' !== $fields[1] && ('tag' !== $fields[1] || ! in_array(
                $fields[5],
                ['commit', 'tag'],
                true,
            ))) {
                continue;
            }
            $sha = 'tag' === $fields[5]
                ? $this->resolveRef($directory, 'refs/tags/' . $fields[0])
                : ('tag' === $fields[1] ? $fields[4] : $fields[3]);

            if (1 !== preg_match('/^[0-9a-f]{40}(?:[0-9a-f]{24})?$/D', $sha)) {
                throw new RuntimeException('Git returned an invalid tag commit identity.');
            }
            $date = 'tag' === $fields[1] && '' !== $fields[2] ? substr($fields[2], 0, 10) : null;
            $tags[] = ['name' => $fields[0], 'sha' => $sha,
                'date' => $date, 'date_source' => null === $date ? null : 'annotated-tag'];
        }

        return $tags;
    }

    /**
     * Retains added, modified, deleted, copied and renamed paths explicitly.
     *
     * @param  string                                                  $directory Explicit consumer directory used for repository or fragment operations.
     * @param  string                                                  $reference Git revision to resolve within the explicit consumer repository.
     * @return list<array{status:string,path:string,previous:?string}> Ordered status/path records retaining rename and
     *                                                                 copy source paths.
     * @throws RuntimeException                                        When Git execution or the returned immutable repository evidence cannot be verified.
     * @throws InvalidArgumentException                                When a supplied revision cannot be accepted before the immutable Git query.
     */
    public function changesSince(string $directory, string $reference): array
    {
        $base = $this->resolveRef($directory, $reference);
        $raw = $this->run(
            $directory,
            [
                'diff',
                '--no-ext-diff',
                '--no-textconv',
                '--relative',
                '--name-status',
                '-z',
                '--find-renames',
                $base . '...HEAD',
                '--',
            ],
        );
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
                throw new RuntimeException('Git returned a malformed changed-path record.');
            }
            $changes[] = ['status' => $status, 'path' => $first, 'previous' => $previous];
        }

        return $changes;
    }

    /**
     * Confirms the revision first and distinguishes a missing tree entry from a Git error.
     *
     * @param  string                   $directory Explicit consumer directory used for repository or fragment operations.
     * @param  string                   $reference Git revision to resolve within the explicit consumer repository.
     * @param  string                   $path      Canonical repository-relative managed path selected for this operation.
     * @return ?string                  Exact committed blob bytes, or null when the verified tree contains no selected entry.
     * @throws RuntimeException         When Git execution or the returned immutable repository evidence cannot be verified.
     * @throws InvalidArgumentException When the supplied revision, managed path or commit input is not acceptable.
     */
    public function readFileAt(string $directory, string $reference, string $path): ?string
    {
        $sha = $this->resolveRef($directory, $reference);
        $this->validatePath($path);

        if ('' === $this->run($directory, ['ls-tree', '--name-only', $sha, '--', $path])) {
            return null;
        }

        return $this->run($directory, ['show', $sha . ':./' . $path]);
    }

    /**
     * Parses NUL-delimited Git tree entries while retaining file modes and unusual path bytes.
     *
     * @param  string                               $directory Explicit consumer directory used for repository or fragment operations.
     * @param  string                               $reference Git revision to resolve within the explicit consumer repository.
     * @param  string                               $path      Canonical repository-relative managed path selected for this operation.
     * @return list<array{path:string,mode:string}> Committed path/mode records retaining unusual filename bytes.
     * @throws RuntimeException                     When Git execution or the returned immutable repository evidence cannot be verified.
     * @throws InvalidArgumentException             When the supplied revision, managed path or commit input is not acceptable.
     */
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

            if (1 !== preg_match(
                '~^([0-9]{6}) (?:blob|commit) (?:[a-f0-9]{40}|[a-f0-9]{64})\t(.+)$~sD',
                $record,
                $parts,
            )
                || ($parts[2] !== $path && ! str_starts_with($parts[2], $path . '/'))) {
                throw new RuntimeException('Git returned a malformed committed-path record.');
            }
            $files[] = ['path' => $parts[2], 'mode' => $parts[1]];
        }

        return $files;
    }

    /**
     * Leaves the fragment staged and intact after a commit failure, for explicit recovery.
     *
     * @param  string                   $directory Explicit consumer directory used for repository or fragment operations.
     * @param  string                   $path      Canonical repository-relative managed path selected for this operation.
     * @param  string                   $message   Controlled diagnostic or commit message supplied by the owning operation.
     * @return string                   New complete HEAD identity after staging and committing only the selected fragment.
     * @throws RuntimeException         When Git execution or the returned immutable repository evidence cannot be verified.
     * @throws InvalidArgumentException When the supplied revision, managed path or commit input is not acceptable.
     */
    public function commitFragment(string $directory, string $path, string $message): string
    {
        if ('' === trim($message) || '' === $path || str_contains($path, "\0")) {
            throw new InvalidArgumentException('A fragment path and nonempty commit message are required.');
        }
        $this->run($directory, ['add', '--', $path]);
        $this->run($directory, ['commit', '--only', '--message', $message, '--', $path]);

        return $this->resolveRef($directory);
    }

    /**
     * Rejects empty or option-shaped revisions before starting a process.
     *
     * @param  string                   $reference Git revision to resolve within the explicit consumer repository.
     * @return void                     No value; unsafe or option-shaped revision syntax is rejected before process execution.
     * @throws InvalidArgumentException When the supplied revision, managed path or commit input is not acceptable.
     */
    private function validateReference(string $reference): void
    {
        if ('' === trim($reference) || str_starts_with(
            $reference,
            '-',
        ) || preg_match(
            '/[\x00\r\n]/',
            $reference,
        )) {
            throw new InvalidArgumentException('A nonempty Git revision is required.');
        }
    }

    /**
     * Restricts baseline queries to canonical paths within the explicit consumer root.
     *
     * @param  string                   $path Canonical repository-relative managed path selected for this operation.
     * @return void                     No value; only canonical project-relative managed paths pass this guard.
     * @throws InvalidArgumentException When the supplied revision, managed path or commit input is not acceptable.
     */
    private function validatePath(string $path): void
    {
        if ('' === $path || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '-') || str_starts_with($path, '/')
            || 1 === preg_match('~^[A-Za-z]:~', $path)
            || [] !== array_intersect(explode('/', $path), ['', '.', '..'])) {
            throw new InvalidArgumentException(
                'Baseline path must identify a canonical project-relative file or directory.',
            );
        }
    }

    /**
     * Executes one argv vector and propagates stderr on failure.
     *
     * @param  string           $directory    Explicit consumer directory used for repository or fragment operations.
     * @param  list<string>     $arguments    Structured Git argv entries passed directly to the process factory without a
     *                                        shell.
     * @param  bool             $allowFailure Whether a failed optional Git probe returns null instead of throwing its controlled
     *                                        diagnostic.
     * @return string|null      Exact Git stdout, or null only for an explicitly optional failed probe.
     * @throws RuntimeException When Git execution or the returned immutable repository evidence cannot be verified.
     */
    private function run(string $directory, array $arguments, bool $allowFailure = false): ?string
    {
        $process = $this->processes->create(['git', '-C', $directory, ...$arguments]);
        $process->run();

        if (! $process->isSuccessful()) {
            if ($allowFailure) {
                return null;
            }

            throw new RuntimeException('Git failed: ' . trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }
}
