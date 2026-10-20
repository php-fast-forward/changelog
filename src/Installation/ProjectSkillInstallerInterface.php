<?php

declare(strict_types=1);

namespace FastForward\Changelog\Installation;

/** Delegates project skill setup to its selected manager without assuming native payload ownership. */
interface ProjectSkillInstallerInterface
{
    /**
     * Previews or applies explicit project metadata and Composer commands for the changelog donor.
     *
     * Dry runs MUST NOT create files, acquire locks or start processes. Existing manager
     * configuration and unknown skill ownership MUST remain authoritative.
     *
     * @param string  $root                Guarded absolute Git root supplied by the project installer.
     * @param bool    $dryRun              Whether to return the read-only plan instead of applying it.
     * @param ?string $expectedFingerprint Optional SHA-256 of the reviewed skill plan, checked before any effect.
     *
     * @return array<string,mixed> Manager, state, relative target, commands, metadata changes and plan fingerprint.
     *
     * @throws \RuntimeException When project metadata, guarded filesystem access or delegated execution fails.
     */
    public function run(string $root, bool $dryRun = false, ?string $expectedFingerprint = null): array;
}
