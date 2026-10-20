<?php

declare(strict_types=1);

namespace FastForward\Changelog\Installation;

/** Executes an independently verified manager without loading consumer plugins or autoloaders. */
interface SkillManagerRuntimeInterface
{
    /**
     * Verifies the fixed official runtime before executing a selected manager command outside consumer code.
     *
     * @param string       $root            Guarded absolute consumer root used only as working directory and declarative configuration.
     * @param string       $vendorDirectory Reviewed relative Composer vendor directory.
     * @param list<string> $arguments       Manager-owned command and literal options, excluding a runtime executable.
     *
     * @return void Completion after manager success and temporary-runtime cleanup.
     *
     * @throws \RuntimeException When runtime acquisition, integrity, containment or execution cannot be proved.
     */
    public function run(string $root, string $vendorDirectory, array $arguments): void;
}
