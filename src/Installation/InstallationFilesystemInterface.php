<?php

declare(strict_types=1);

namespace FastForward\Changelog\Installation;

/** Defines the local, create-only boundary used by guided project adoption. */
interface InstallationFilesystemInterface
{
    /**
     * Inventories exact workflow bytes and the locally recorded origin default branch without network access.
     *
     * @param  string                                                                                    $cwd Explicit consumer directory, resolved to an existing Git worktree.
     * @return array{root:string,base_branch:string,files:array<string,string>,diagnostics:list<string>} Guarded snapshot.
     * @throws \RuntimeException                                                                         When Git, ancestry or regular-file evidence cannot be verified.
     */
    public function inspect(string $cwd): array;

    /**
     * Reads one guarded project-relative metadata file without creating directories.
     *
     * @param  string            $root Previously inspected absolute consumer root.
     * @param  string            $path Fixed project-relative metadata path.
     * @return ?string           Exact regular-file bytes, or null when absent.
     * @throws \RuntimeException When path ancestry or file readability is unsafe.
     */
    public function read(string $root, string $path): ?string;

    /**
     * Publishes complete bytes only if the target remains absent and the workflow snapshot remains unchanged.
     *
     * @param  string               $root     Previously inspected absolute consumer root.
     * @param  string               $path     Fixed project-relative workflow destination.
     * @param  string               $contents Complete validated workflow bytes.
     * @param  array<string,string> $expected Exact previously accepted workflow inventory.
     * @return void                 Completion after exclusive creation and verification; existing files are never replaced.
     * @throws \RuntimeException    When drift, a concurrent creator or I/O failure prevents safe installation.
     */
    public function create(string $root, string $path, string $contents, array $expected): void;
}
