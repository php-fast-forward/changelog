<?php

declare(strict_types=1);

namespace FastForward\Changelog\Skill;

use FastForward\Changelog\Filesystem\Factory\FinderFactoryInterface;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Version\PackageVersionResolverInterface;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Lock\LockFactory;
use Throwable;

/** Owns one conservative skill-copy transaction; receipts are local integrity evidence, never signatures. */
final readonly class SkillInstaller implements SkillInstallerInterface
{
    public const string NAME = 'fast-forward-changelog';
    public const string RECEIPT = '.fast-forward-install.json';
    private const string OWNER = 'fast-forward/changelog';

    /**
     * Captures existing replaceable I/O boundaries and package metadata without observing any path.
     *
     * @param Filesystem                      $filesystem Directory creation, link observation and same-filesystem renames.
     * @param ManagedFileStoreInterface       $files      Guarded regular-file reads and atomic file replacement.
     * @param FinderFactoryInterface          $finders    Fresh bounded-to-directory inventories, including hidden entries.
     * @param PackagePathResolverInterface    $paths      Consumer-root-relative lexical path composition.
     * @param LockFactory                     $locks      Cooperative target-scoped lock shared by native installation operations.
     * @param PackageVersionResolverInterface $version    Version of the executing package, not a remote latest version.
     * @param string                          $source     Absolute canonical donor directory distributed with this runtime.
     * @param string|null                     $home       Explicitly composed home directory; consulted only with global scope.
     * @param string|null                     $reference  Known Composer source commit, or null when unavailable.
     */
    public function __construct(
        private Filesystem $filesystem,
        private ManagedFileStoreInterface $files,
        private FinderFactoryInterface $finders,
        private PackagePathResolverInterface $paths,
        private LockFactory $locks,
        private PackageVersionResolverInterface $version,
        private string $source,
        private ?string $home,
        private ?string $reference,
    ) {}

    /**
     * Inspects read-only modes or applies a revalidated, exclusively owned directory transaction.
     *
     * @param  string                   $cwd    Existing project scope resolved independently from the executing package.
     * @param  string                   $target Safe relative discovery root; the skill leaf is fixed.
     * @param  bool                     $global Whether to use the explicitly composed home scope.
     * @param  string                   $mode   install, check or update.
     * @param  bool                     $dryRun Whether all mutation and lock acquisition must be suppressed.
     * @return array<string,mixed>      Explicit local state and proposed/performed action, without network-derived claims.
     * @throws InvalidArgumentException When a scope or mode is invalid.
     * @throws RuntimeException         When payload, I/O, locking or rollback evidence fails.
     */
    public function run(
        string $cwd = '.',
        string $target = '.agents/skills',
        bool $global = false,
        string $mode = 'install',
        bool $dryRun = false,
    ): array {
        if (! in_array($mode, ['install', 'check', 'update'], true)) {
            throw new InvalidArgumentException('Skill mode must be install, check or update.');
        }
        if ($global && (null === $this->home || '' === $this->home)) {
            throw new InvalidArgumentException(
                'Global skill installation requires an explicitly configured home directory.',
            );
        }
        $root = $this->paths->absolutePath($global ? $this->home : $cwd);
        $this->guard($root);
        $this->finders->create()->in($root);
        $relative = $this->relative($target) . '/' . self::NAME;
        $destination = $this->paths->absolutePath($relative, $root);
        $scope = $global ? 'global' : 'project';
        $payload = $this->payload();
        if ('check' === $mode || $dryRun) {
            return $this->inspect($destination, $relative, $scope, $payload, $mode);
        }
        $lock = $this->locks->createLock('changelog-skill:' . hash('sha256', $destination));
        if (! $lock->acquire(true)) {
            throw new RuntimeException('Could not acquire the native skill installation lock.');
        }
        try {
            $result = $this->inspect($destination, $relative, $scope, $payload, $mode);
            if (null === $result['action']) {
                return $result;
            }
            $this->replace($destination, $scope, $relative, $payload, $result['installed_sha256']);
            $result['state'] = 'install' === $result['action'] ? 'installed' : 'updated';

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Reads the complete donor and rejects technical receipts or incomplete packaged instructions.
     *
     * @return array<string,string> Sorted relative payload paths mapped to exact bytes.
     * @throws RuntimeException     When donor files are missing, unsafe or already contain installation state.
     */
    private function payload(): array
    {
        $payload = $this->snapshot($this->source);
        if (null === $payload || ! isset($payload['SKILL.md'], $payload['LICENSE']) || isset($payload[self::RECEIPT])) {
            throw new RuntimeException('The executing package does not contain a complete canonical skill payload.');
        }

        return $payload;
    }

    /**
     * Classifies native integrity separately from equality with this runtime's payload.
     *
     * @param  string               $destination Absolute guarded installation leaf.
     * @param  string               $relative    Portable target relative to the selected scope root.
     * @param  string               $scope       project or global.
     * @param  array<string,string> $payload     Current complete donor bytes.
     * @param  string               $mode        Selected operation controlling whether drift proposes an update.
     * @return array<string,mixed>  Non-mutating local assessment with a permitted action or null.
     * @throws RuntimeException     When filesystem/link evidence cannot be read safely.
     */
    private function inspect(string $destination, string $relative, string $scope, array $payload, string $mode): array
    {
        [$stage, $backup] = $this->temporaryPaths($destination, $relative);
        $this->guard($stage);
        $this->guard($backup);
        $result = ['state' => 'absent', 'scope' => $scope, 'target' => $relative, 'installed_sha256' => null,
            'source' => $this->sourceIdentity(), 'payload_sha256' => $this->digest(
                $this->hashes($payload),
            ), 'action' => null];
        if ($this->filesystem->exists($stage) || $this->filesystem->exists($backup)) {
            return array_replace($result, ['state' => 'recovery-required']);
        }
        $tree = $this->snapshot($destination);
        if (null === $tree) {
            return array_replace($result, ['action' => 'install' === $mode ? 'install' : null]);
        }
        $result['installed_sha256'] = $this->digest($this->hashes($tree));
        $receipt = $this->receipt($tree[self::RECEIPT] ?? null, $scope, $relative);
        if (null === $receipt) {
            return array_replace($result, ['state' => 'unmanaged']);
        }
        unset($tree[self::RECEIPT]);
        $actual = $this->hashes($tree);
        if ($actual !== $receipt['files']) {
            return array_replace($result, ['state' => 'diverged']);
        }
        if ($actual === $this->hashes($payload)) {
            return array_replace($result, ['state' => 'current']);
        }
        $older = $this->stable($receipt['source']['version']);
        $current = $this->stable($this->version->resolve());
        $state = null !== $older && null !== $current && version_compare(
            $older,
            $current,
            '<',
        ) ? 'outdated' : 'different';
        $downgrade = null !== $older && null !== $current && version_compare($older, $current, '>');

        return array_replace(
            $result,
            ['state' => $state, 'action' => 'update' === $mode && ! $downgrade ? 'update' : null],
        );
    }

    /**
     * Validates only this installer's portable, content-bound receipt format.
     *
     * @param  string|null              $contents Installed receipt bytes, absent for third-party/manual copies.
     * @param  string                   $scope    Selected scope whose ownership must match.
     * @param  string                   $target   Safe portable destination whose ownership must match.
     * @return array<string,mixed>|null Complete native evidence, or null for unknown/corrupt provenance.
     */
    private function receipt(?string $contents, string $scope, string $target): ?array
    {
        try {
            $data = null === $contents ? null : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($data) || 1 !== ($data['schema'] ?? null) || self::OWNER !== ($data['owner'] ?? null)
            || self::NAME !== ($data['name'] ?? null) || $scope !== ($data['scope'] ?? null)
            || $target !== ($data['target'] ?? null) || self::OWNER !== ($data['source']['package'] ?? null)
            || ! is_string($data['source']['version'] ?? null) || '' === $data['source']['version']
            || ! array_key_exists('reference', $data['source']) || (null !== $data['source']['reference']
                && (! is_string($data['source']['reference']) || 1 !== preg_match(
                    '/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/',
                    $data['source']['reference'],
                )))
            || ! is_array($data['files'] ?? null) || [] === $data['files'] || array_is_list($data['files'])) {
            return null;
        }
        foreach ($data['files'] as $path => $hash) {
            if (! is_string($path) || ! is_string($hash) || 1 !== preg_match('/\A[a-f0-9]{64}\z/', $hash)
                || self::RECEIPT === $path) {
                return null;
            }
            try {
                if ($this->relative($path) !== $path) {
                    return null;
                }
            } catch (InvalidArgumentException) {
                return null;
            }
        }
        $sorted = $data['files'];
        ksort($sorted, SORT_STRING);

        return $sorted === $data['files'] && ($data['payload_sha256'] ?? null) === $this->digest($sorted)
            && isset($sorted['SKILL.md'], $sorted['LICENSE']) ? $data : null;
    }

    /**
     * Stages full bytes and receipt, then swaps directories with exception rollback.
     *
     * A process crash can leave stage/backup evidence; subsequent operations refuse takeover.
     * Rollback preserves the rejected replacement in staging rather than deleting unknown bytes.
     *
     * @param  string               $destination  Absolute guarded skill leaf.
     * @param  string               $scope        Selected project/global ownership.
     * @param  string               $relative     Portable target stored without host paths.
     * @param  array<string,string> $payload      Exact current donor bytes.
     * @param  string|null          $acceptedHash Complete installed-tree fingerprint accepted under the lock, or null for absence.
     * @return void                 Complete verified copy is exposed and its previous backup removed.
     * @throws RuntimeException     When staging, revalidation, swap or rollback fails; retain recovery evidence.
     */
    private function replace(
        string $destination,
        string $scope,
        string $relative,
        array $payload,
        ?string $acceptedHash,
    ): void {
        [$stage, $backup] = $this->temporaryPaths($destination, $relative);
        $before = $this->snapshot($destination);
        if ((null === $before ? null : $this->digest($this->hashes($before))) !== $acceptedHash) {
            throw new RuntimeException('The skill destination changed after its ownership was inspected.');
        }
        $expected = $payload + [self::RECEIPT => json_encode([
            'schema' => 1, 'owner' => self::OWNER, 'name' => self::NAME, 'scope' => $scope, 'target' => $relative,
            'source' => $this->sourceIdentity(), 'files' => $this->hashes($payload),
            'payload_sha256' => $this->digest($this->hashes($payload)),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n"];
        ksort($expected, SORT_STRING);
        $backedUp = $promoted = false;
        try {
            $this->guard($stage);
            $this->filesystem->mkdir($stage);
            foreach ($expected as $path => $contents) {
                $this->files->write($stage . '/' . $path, $contents);
            }
            if ($this->snapshot($stage) !== $expected || $this->snapshot($destination) !== $before) {
                throw new RuntimeException('Skill staging or destination changed before the directory swap.');
            }
            $this->guard($destination);
            $this->filesystem->mkdir(dirname($destination));
            $this->guard($destination);
            if (null !== $before) {
                $this->guard($backup);
                $this->filesystem->rename($destination, $backup, false);
                $backedUp = true;
                if ($this->snapshot($backup) !== $before) {
                    throw new RuntimeException('The previous skill changed while it was moved to the recovery backup.');
                }
            }
            $this->filesystem->rename($stage, $destination, false);
            $promoted = true;
            if ($this->snapshot($destination) !== $expected) {
                throw new RuntimeException(
                    'The installed skill could not be confirmed as the complete staged payload.',
                );
            }
        } catch (Throwable $failure) {
            $this->rollback($destination, $stage, $backup, $promoted, $backedUp, $before, $failure);
        }

        if ($backedUp) {
            $this->finishBackup($backup, $before);
        }
    }

    /**
     * Restores previous ownership while retaining rejected replacement bytes for inspection.
     *
     * @param  string                    $destination Absolute installation leaf selected by the guarded transaction.
     * @param  string                    $stage       Recovery path reserved for the rejected replacement.
     * @param  string                    $backup      Recovery path containing the previous complete tree and receipt.
     * @param  bool                      $promoted    Whether replacement bytes already occupy the installation leaf.
     * @param  bool                      $backedUp    Whether the previous tree was successfully moved into backup.
     * @param  array<string,string>|null $before      Accepted original bytes or absence when no backup was completed.
     * @param  Throwable                 $failure     Original transaction error retained as diagnostic context.
     * @return never                     Always reports the failed operation after restoration or a distinct rollback failure.
     * @throws RuntimeException          When reporting either the restored transaction or incomplete rollback.
     */
    private function rollback(
        string $destination,
        string $stage,
        string $backup,
        bool $promoted,
        bool $backedUp,
        ?array $before,
        Throwable $failure,
    ): never {
        try {
            $restored = $backedUp ? $this->snapshot($backup) : $before;
            if ($promoted) {
                $this->guard($destination);
                $this->filesystem->rename($destination, $stage, false);
            }
            if ($backedUp) {
                $this->guard($backup);
                $this->filesystem->rename($backup, $destination, false);
            }
            if ($this->snapshot($destination) !== $restored) {
                throw new RuntimeException('The previous skill could not be confirmed after rollback.');
            }
        } catch (Throwable $rollback) {
            throw new RuntimeException(
                'Skill update and rollback failed; preserve the staging and backup directories for inspection.',
                previous: $rollback,
            );
        }

        throw new RuntimeException(
            'Skill installation failed; previous bytes were preserved. Inspect the retained staging directory before retrying.',
            previous: $failure,
        );
    }

    /**
     * Removes a verified unchanged previous tree only after the replacement is confirmed installed.
     *
     * @param  string               $backup Absolute previous-tree location outside skill discovery.
     * @param  array<string,string> $before Exact previous bytes accepted before the swap.
     * @return void                 Previous backup is removed after a final link and byte revalidation.
     * @throws RuntimeException     When backup changed or cleanup failed; the new installation remains committed.
     */
    private function finishBackup(string $backup, array $before): void
    {
        try {
            if ($this->snapshot($backup) !== $before) {
                throw new RuntimeException('The recovery backup changed after the new skill was installed.');
            }
            $this->filesystem->remove($backup);
        } catch (Throwable $cleanup) {
            throw new RuntimeException(
                'The new skill is installed, but its recovery backup was retained. Inspect both directories before retrying.',
                previous: $cleanup,
            );
        }
    }

    /**
     * Inventories every entry through injected discovery, rejecting links and special files.
     *
     * @param  string                    $directory Absolute source, stage or destination selected by this operation.
     * @return array<string,string>|null Sorted relative regular-file bytes, or null for an absent tree.
     * @throws RuntimeException          When a link, special/empty directory, unsafe name or disappearing file is encountered.
     */
    private function snapshot(string $directory): ?array
    {
        $this->guard($directory);
        if (! $this->filesystem->exists($directory)) {
            return null;
        }
        $entries = $this->finders->create()->in($directory)->ignoreDotFiles(false)->ignoreVCS(false)->sortByName();
        $files = $directories = [];
        foreach ($entries as $entry) {
            $path = str_replace('\\', '/', $entry->getRelativePathname());
            $this->relative($path);
            if ($entry->isLink() || (! $entry->isDir() && ! $entry->isFile())) {
                throw new RuntimeException('Skill trees require regular files and directories without links.');
            }
            if ($entry->isDir()) {
                $directories[] = $path;

                continue;
            }
            $contents = $this->files->read($directory . '/' . $path);
            if (null === $contents || isset($files[$path])) {
                throw new RuntimeException('Skill inventory changed or contains duplicate paths.');
            }
            $files[$path] = $contents;
        }
        foreach ($directories as $path) {
            if (! array_any(array_keys($files), static fn(string $file): bool => str_starts_with($file, $path . '/'))) {
                throw new RuntimeException('Unexpected empty directories cannot establish an intact skill tree.');
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Rejects lexical escape and symbolic ancestry before any directory operation.
     *
     * @param  string           $path Absolute managed path selected by composed scope resolution.
     * @return void             Path and every existing ancestor are non-symbolic.
     * @throws RuntimeException When path syntax or a symbolic ancestor is unsafe.
     */
    private function guard(string $path): void
    {
        if (! $this->paths->isAbsolute($path) || str_contains($path, "\0") || [] !== array_intersect(
            explode('/', str_replace('\\', '/', $path)),
            ['.', '..'],
        )) {
            throw new RuntimeException('Skill paths must stay within an explicit absolute scope.');
        }
        do {
            if (null !== $this->filesystem->readlink($path)) {
                throw new RuntimeException('Skill source, destination and ancestors must not be symbolic links.');
            }
            $normalized = str_replace('\\', '/', $path);
            if (1 === preg_match('~\A(?:[A-Za-z]:/|//[^/]+/[^/]+/?)\z~', $normalized)) {
                break;
            }
            // Symfony treats UNC roots as POSIX paths on POSIX; preserve their leading separator explicitly.
            $unc = str_starts_with($normalized, '//');
            $parent = ($unc ? '/' : '') . Path::getDirectory($unc ? substr($normalized, 1) : $normalized);
            if ($parent === $path) {
                break;
            }
            $path = $parent;
        } while (true);
    }

    /**
     * Accepts only nonempty project-relative paths without traversal or control characters.
     *
     * @param  string                   $path Raw option or receipt-relative path.
     * @return string                   Portable forward-slash relative path.
     * @throws InvalidArgumentException When the path could escape or alias its declared scope.
     */
    private function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ('' === $path || $this->paths->isAbsolute($path) || preg_match('/[\x00-\x1f\x7f]/', $path)
            || [] !== array_intersect(explode('/', $path), ['', '.', '..'])) {
            throw new InvalidArgumentException(
                'Skill target must be a relative path without traversal or empty segments.',
            );
        }

        return $path;
    }

    /**
     * Places portable recovery evidence at the scope root, outside every selected discovery root.
     *
     * @param  string               $destination Absolute fixed-name installation leaf.
     * @param  string               $relative    Validated scope-relative installation leaf, including its fixed skill name.
     * @return array{string,string} Deterministic staging and previous-tree paths, serialized by the target lock.
     */
    private function temporaryPaths(string $destination, string $relative): array
    {
        $scopeRoot = rtrim(Path::normalize(dirname($destination, substr_count($relative, '/') + 1)), '/');
        $prefix = $scopeRoot . '/.fast-forward-changelog-' . substr(hash('sha256', $relative), 0, 16);

        return [$prefix . '.stage', $prefix . '.backup'];
    }

    /**
     * Fingerprints exact bytes in locale-independent relative-path order.
     *
     * @param  array<string,string> $files Complete regular-file bytes without the installation receipt.
     * @return array<string,string> Sorted relative paths mapped to lowercase SHA-256 hashes.
     */
    private function hashes(array $files): array
    {
        $hashes = array_map(static fn(string $bytes): string => hash('sha256', $bytes), $files);
        ksort($hashes, SORT_STRING);

        return $hashes;
    }

    /**
     * Defines a reproducible inventory serialization distinct from third-party lock formats.
     *
     * @param  array<string,string> $hashes Byte-sorted paths and lowercase file hashes.
     * @return string               SHA-256 of JSON with unescaped slashes and canonical sorted keys.
     */
    private function digest(array $hashes): string
    {
        return hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Retains executing-package metadata without inventing unavailable source commits.
     *
     * @return array{package:string,version:string,reference:?string} Explicit local provenance for the native receipt.
     * @throws RuntimeException                                       When the injected package version is empty and cannot establish self-consistent provenance.
     */
    private function sourceIdentity(): array
    {
        $version = $this->version->resolve();
        if ('' === $version) {
            throw new RuntimeException('The executing package version must not be empty.');
        }
        $reference = null !== $this->reference && 1 === preg_match(
            '/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/',
            $this->reference,
        )
            ? $this->reference : null;

        return ['package' => self::OWNER, 'version' => $version, 'reference' => $reference];
    }

    /**
     * Extracts comparable stable numeric cores; development and unknown versions establish no age.
     *
     * @param  string      $version Composer pretty version from current or recorded provenance.
     * @return string|null Stable numeric core, or null when relative age is unproved.
     */
    private function stable(string $version): ?string
    {
        return 1 === preg_match(
            '/\Av?((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/',
            $version,
            $matches,
        )
            ? $matches[1] : null;
    }
}
