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
 * @see https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Automation\Dependabot;

/** Records a create-only operation; conflict may include a commit already created by the Contents API. */
final readonly class DependabotFragmentResult
{
    /**
     * Status is created, unchanged, filtered, refused or conflict; diagnostics contain no raw server details.
     *
     * @param string       $status      Operation outcome used to distinguish success from refusal or a possible partial write.
     * @param string       $path        Canonical repository-relative managed path selected for this operation.
     * @param ?string      $commitSha   Confirmed fragment commit identity, or null when no commit is known to have been
     *                                  created.
     * @param list<string> $diagnostics Controlled explanations for refusal, conflict or exceptional authorization
     *                                  state.
     */
    public function __construct(
        public string $status,
        public string $path,
        public ?string $commitSha,
        public array $diagnostics,
    ) {}
}
