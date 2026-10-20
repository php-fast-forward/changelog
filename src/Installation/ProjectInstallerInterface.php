<?php

declare(strict_types=1);

namespace FastForward\Changelog\Installation;

/** Inspects an explicit consumer repository and creates only a missing, reviewed workflow integration. */
interface ProjectInstallerInterface
{
    /**
     * Detects existing or ambiguous automation before querying any published release.
     *
     * @param  string                                                                                                                                       $cwd Explicit existing Git consumer directory.
     * @return array{root:string,base_branch:string,workflows:list<array{path:string,kind:string,reference:?string}>,state:string,diagnostics:list<string>} Local assessment.
     * @throws \RuntimeException                                                                                                                            When repository or filesystem evidence cannot be verified.
     */
    public function inspect(string $cwd = '.'): array;

    /**
     * Preserves existing automation and validates both proposed installations before the first write.
     *
     * @param  string                                                                                                                                $cwd              Explicit existing Git consumer directory.
     * @param  ?string                                                                                                                               $reference        Optional published workflow tag; null delegates release discovery to the resolver.
     * @param  array<string,mixed>                                                                                                                   $settings         Reusable input names and literal values; only non-default values are emitted.
     * @param  bool                                                                                                                                  $skill            Whether to offer the native bundled skill without taking ownership from another installer.
     * @param  bool                                                                                                                                  $dryRun           Whether all operations must remain read-only, including directory creation and locks.
     * @param  ?string                                                                                                                               $expectedWorkflow SHA-256 of JSON_THROW_ON_ERROR encoded preview workflow; mismatch blocks every write.
     * @return array{state:string,workflow:array{state:string,path:?string,content:?string,reference:?string},skill:?array,diagnostics:list<string>} Explicit plan or observed result.
     * @throws \InvalidArgumentException                                                                                                             When selected input names, types or branch syntax are invalid.
     * @throws \RuntimeException                                                                                                                     When local evidence or the selected published contract cannot be verified.
     */
    public function run(
        string $cwd,
        ?string $reference,
        array $settings,
        bool $skill = true,
        bool $dryRun = false,
        ?string $expectedWorkflow = null,
    ): array;
}
