<?php

declare(strict_types=1);

namespace FastForward\Changelog\Skill;

/** Installs or inspects only the skill bundled with the executing package, without network access. */
interface SkillInstallerInterface
{
    /**
     * Resolves one explicit scope and preserves unknown or locally changed installations.
     *
     * @param  string                    $cwd    Existing project directory; never the package installation/cache directory by inference.
     * @param  string                    $target Relative discovery root to which the fixed package skill name is appended.
     * @param  bool                      $global Whether the explicitly configured home replaces the project scope.
     * @param  string                    $mode   install, check or update; updates require intact native ownership evidence.
     * @param  bool                      $dryRun Whether to inspect the proposed action without locks, directory creation or writes.
     * @return array<string,mixed>       Local state, relative destination, provenance and any proposed/performed action.
     * @throws \InvalidArgumentException When modes or relative scope paths are invalid.
     * @throws \RuntimeException         When payload, filesystem, locking or recovery evidence cannot be verified.
     */
    public function run(
        string $cwd = '.',
        string $target = '.agents/skills',
        bool $global = false,
        string $mode = 'install',
        bool $dryRun = false,
    ): array;
}
