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

namespace FastForward\Changelog\Automation\VersionPullRequest\Factory;

use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestResult;

/** Concentrates immutable automation outcome and diagnostic construction without external effects. */
final class VersionPullRequestResultFactory implements VersionPullRequestResultFactoryInterface
{
    /**
     * Retains explicitly validated data without inspecting host or GitHub state.
     *
     * @param  string                   $status      Operation outcome used to distinguish success from refusal or a possible partial write.
     * @param  ?int                     $prNumber    Positive pull-request number whose live authority and head are inspected.
     * @param  ?string                  $url         Confirmed HTTPS PR or release URL, or null when no remote object is available.
     * @param  ?string                  $headSha     Immutable generated PR head whose ownership and committed output must be proved.
     * @param  ?string                  $planId      Validated deterministic transaction hash, or null when no plan was constructed.
     * @param  ?string                  $version     Planned next semantic version, or null when the transaction does not prepare a release.
     * @param  bool                     $maintenance Whether the known transaction maintains history without publishing a new version.
     * @param  list<string>             $diagnostics Controlled explanations for refusal, conflict or exceptional authorization
     *                                               state.
     * @return VersionPullRequestResult Typed result preserving the known transaction and remote state.
     */
    public function create(
        string $status,
        ?int $prNumber = null,
        ?string $url = null,
        ?string $headSha = null,
        ?string $planId = null,
        ?string $version = null,
        bool $maintenance = false,
        array $diagnostics = [],
    ): VersionPullRequestResult {
        return new VersionPullRequestResult(
            $status,
            $prNumber,
            $url,
            $headSha,
            $planId,
            $version,
            $maintenance,
            $diagnostics,
        );
    }
}
