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

namespace FastForward\Changelog\Automation\Policy;

/** Binds exception authority to the exact head inspected, with safe diagnostics for absent proof. */
final readonly class PullRequestAuthorization
{
    /**
     * The caller MUST compare headSha to its checked-out head before applying either permission.
     *
     * @param bool         $waiverAuthorized        Whether a verified human grant authorizes the fragment-presence exception.
     * @param bool         $centralChangeAuthorized Whether verified maintenance or managed-version policy authorizes
     *                                              central-history changes.
     * @param string       $kind                    Verified policy classification used by contribution validation rather than editable PR text.
     * @param list<string> $diagnostics             Controlled explanations for refusal, conflict or exceptional authorization
     *                                              state.
     * @param ?string      $headSha                 Immutable generated PR head whose ownership and committed output must be proved.
     */
    public function __construct(
        public bool $waiverAuthorized,
        public bool $centralChangeAuthorized,
        public string $kind,
        public array $diagnostics,
        public ?string $headSha = null,
    ) {}
}
