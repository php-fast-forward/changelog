<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license https://opensource.org/licenses/MIT MIT License
 * @see https://github.com/php-fast-forward/changelog
 */

namespace FastForward\Changelog\Release;

use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubBlobTransportInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use RuntimeException;

/** Proves only explicitly selected replacements of existing regular source-base files. */
final readonly class GeneratedFileEvidence
{
    /**
     * Captures the existing data boundaries; no source, hook or consumer executable is loaded.
     *
     * @param GitRepositoryInterface       $git    Exact local committed-file metadata and bytes.
     * @param ManagedFileStoreInterface    $files  Existing symbolic-ancestry guarded file reader.
     * @param PackagePathResolverInterface $paths  Canonical consumer-root path resolver.
     * @param GitHubClientInterface        $github Scoped immutable remote tree/blob evidence.
     */
    public function __construct(
        private GitRepositoryInterface $git,
        private ManagedFileStoreInterface $files,
        private PackagePathResolverInterface $paths,
        private GitHubClientInterface $github,
    ) {}

    /**
     * Hashes prepared checkout bytes or exact committed output, preserving source-base file modes.
     *
     * @param  ReleaseOptions       $options Trusted explicit generated path selection.
     * @param  string               $base    Immutable source-base commit that owns every original regular-file mode.
     * @param  ?string              $head    Optional immutable output commit; null selects the prepared filesystem bytes.
     * @return array<string,string> Sorted generated file paths and SHA-256 identities.
     * @throws RuntimeException     When a selected file is absent, symbolic, out of bounds or has changed mode.
     */
    public function local(ReleaseOptions $options, string $base, ?string $head = null): array
    {
        $result = [];
        foreach ($options->generatedFiles as $path) {
            $mode = $this->mode($options, $base, $path);
            if (null !== $head && $this->mode($options, $head, $path) !== $mode) {
                throw new RuntimeException('Generated output must preserve its source-base file mode.');
            }
            $bytes = null === $head ? $this->contents($options, $path) : $this->git->readFileAt(
                $options->workingDirectory,
                $head,
                $path,
            );
            if (null === $bytes || strlen($bytes) > 67_108_864) {
                throw new RuntimeException('Generated output requires an existing bounded regular file.');
            }
            $result[$path] = hash('sha256', $bytes);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Reads prepared artifact data through the existing guarded managed-file boundary.
     *
     * @param  ReleaseOptions   $options Consumer root selected independently of the native runtime.
     * @param  string           $path    Explicit selected project-relative file path.
     * @return string           Exact prepared bytes, never loaded or executed.
     * @throws RuntimeException When the selected bytes are unavailable or exceed the domain's existing 64 MiB bound.
     */
    public function contents(ReleaseOptions $options, string $path): string
    {
        $bytes = $this->files->read($this->paths->absolutePath($path, $options->workingDirectory));
        if (null === $bytes || strlen($bytes) > 67_108_864) {
            throw new RuntimeException('Generated output requires existing bytes of at most 64 MiB.');
        }

        return $bytes;
    }

    /**
     * Requires one exact regular blob, excluding new paths, directories, symlinks and submodules.
     *
     * @param  ReleaseOptions   $options Consumer Git root.
     * @param  string           $sha     Immutable base or output identity.
     * @param  string           $path    Exact selected path.
     * @return string           Existing executable or non-executable regular-file mode.
     * @throws RuntimeException When the selected committed path is not a unique regular file.
     */
    public function mode(ReleaseOptions $options, string $sha, string $path): string
    {
        $files = $this->git->filesAt($options->workingDirectory, $sha, $path);
        if (1 !== count($files) || ($files[0]['path'] ?? null) !== $path || ! in_array(
            $files[0]['mode'] ?? null,
            ['100644', '100755'],
            true,
        )) {
            throw new RuntimeException('Generated paths must already be exact regular source-base files.');
        }

        return $files[0]['mode'];
    }

    /**
     * Reconstructs committed identities from small Git tree metadata and bounded raw blob streams.
     *
     * @param  ReleaseOptions       $options Trusted allowlist and repository identity.
     * @param  string               $base    Original immutable source-base identity.
     * @param  string               $head    Signed generated commit whose selected bytes must be proved.
     * @return array<string,string> Actual committed SHA-256 values, never self-reported trailer metadata.
     * @throws RuntimeException     When remote mode, scope, bounded content or Git blob identity cannot be proved.
     */
    public function remote(ReleaseOptions $options, string $base, string $head): array
    {
        if ([] === $options->generatedFiles) {
            return [];
        }
        if (! $this->github instanceof GitHubBlobTransportInterface || ! GitHubEvidence::repository(
            $options->repository,
        )) {
            throw new RuntimeException('Generated output proof requires the trusted native blob transport.');
        }
        $result = [];
        foreach ($options->generatedFiles as $path) {
            $before = $this->treeEntry($options->repository, $base, $path);
            $after = $this->treeEntry($options->repository, $head, $path);
            if (! in_array(
                $before['mode'] ?? null,
                ['100644', '100755'],
                true,
            ) || ($after['mode'] ?? null) !== $before['mode']
                || ($before['type'] ?? null) !== 'blob' || ($after['type'] ?? null) !== 'blob'
                || ! is_int($after['size'] ?? null)) {
                throw new RuntimeException('Generated remote paths must preserve existing regular-file modes.');
            }
            $result[$path] = $this->github->blobDigest($options->repository, $after['sha'], $after['size']);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Reads the existing immutable source blob identity so byte-identical builds need no upload.
     *
     * @param  ReleaseOptions   $options Trusted repository and selected generated path policy.
     * @param  string           $base    Immutable source commit already proved fresh by the native writer.
     * @param  string           $path    Explicit selected existing regular source-base file.
     * @return string           Complete original Git blob SHA, independent of prepared artifact bytes.
     * @throws RuntimeException When the immutable regular source blob cannot be proved.
     */
    public function baseBlob(ReleaseOptions $options, string $base, string $path): string
    {
        $entry = $this->treeEntry($options->repository, $base, $path);
        if ('blob' !== ($entry['type'] ?? null) || ! in_array($entry['mode'] ?? null, ['100644', '100755'], true)
            || ! is_string($entry['sha'] ?? null) || ! GitHubEvidence::sha($entry['sha'])) {
            throw new RuntimeException('Generated source requires an existing immutable regular blob.');
        }

        return $entry['sha'];
    }
    /**
     * Traverses immutable nonrecursive trees without downloading unrelated or base64-encoded content.
     *
     * @param  string              $repository Previously validated trusted owner/name.
     * @param  string              $commit     Previously validated source-base or signed generated commit.
     * @param  string              $path       Canonical selected relative file path.
     * @return array<string,mixed> Exact final tree entry with mode, blob identity and size.
     * @throws RuntimeException    When an object, path component or complete tree identity is unavailable.
     */
    private function treeEntry(string $repository, string $commit, string $path): array
    {
        $object = $this->github->request('GET', '/repos/' . $repository . '/git/commits/' . $commit);
        $sha = $object['tree']['sha'] ?? null;
        foreach (explode('/', $path) as $component) {
            if (! is_string($sha) || ! GitHubEvidence::sha($sha)) {
                throw new RuntimeException('Generated file tree identity is unavailable.');
            }
            $tree = $this->github->request('GET', '/repos/' . $repository . '/git/trees/' . $sha);
            if (($tree['sha'] ?? null) !== $sha || true === ($tree['truncated'] ?? null) || ! is_array(
                $tree['tree'] ?? null,
            )) {
                throw new RuntimeException('Generated file tree evidence is incomplete.');
            }
            $matches = array_values(
                array_filter($tree['tree'], static fn(array $entry): bool => ($entry['path'] ?? null) === $component),
            );
            if (1 !== count($matches)) {
                throw new RuntimeException('Generated file path has no unique committed tree entry.');
            }
            $entry = $matches[0];
            $sha = $entry['sha'] ?? null;
        }

        return $entry;
    }
}
