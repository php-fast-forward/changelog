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

namespace FastForward\Changelog\Automation\Policy\Factory;

use FastForward\Changelog\Automation\Policy\PullRequestAuthorization;

/** Concentrates immutable authorization construction without resolving policy or contacting GitHub. */
interface PullRequestAuthorizationFactoryInterface
{
    /**
     * Retains inspected authority and diagnostic evidence; a missing head grants no reusable skip.
     *
     * @param  bool                     $waiverAuthorized        Whether a verified human grant authorizes the fragment-presence exception.
     * @param  bool                     $centralChangeAuthorized Whether verified maintenance or managed-version policy authorizes
     *                                                           central-history changes.
     * @param  string                   $kind                    Verified policy classification used by contribution validation rather than editable PR text.
     * @param  list<string>             $diagnostics             Controlled explanations for refusal, conflict or exceptional authorization
     *                                                           state.
     * @param  ?string                  $headSha                 Immutable generated PR head whose ownership and committed output must be proved.
     * @return PullRequestAuthorization Immutable authorization value with the observed head and controlled diagnostics.
     */
    public function create(
        bool $waiverAuthorized,
        bool $centralChangeAuthorized,
        string $kind,
        array $diagnostics,
        ?string $headSha = null,
    ): PullRequestAuthorization;
}
