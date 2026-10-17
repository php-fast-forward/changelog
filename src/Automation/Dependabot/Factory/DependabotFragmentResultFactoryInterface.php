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

namespace FastForward\Changelog\Automation\Dependabot\Factory;

use FastForward\Changelog\Automation\Dependabot\DependabotFragmentResult;

/** Concentrates immutable API-operation result construction. */
interface DependabotFragmentResultFactoryInterface
{
    /**
     * Preserves whether a mutation occurred and any safe actionable diagnostic.
     *
     * @param  string                   $status      Operation outcome used to distinguish success from refusal or a possible partial write.
     * @param  string                   $path        Canonical repository-relative managed path selected for this operation.
     * @param  ?string                  $commitSha   Confirmed fragment commit identity, or null when no commit is known to have been
     *                                               created.
     * @param  list<string>             $diagnostics Controlled explanations for refusal, conflict or exceptional authorization
     *                                               state.
     * @return DependabotFragmentResult Typed create-only fragment outcome and any confirmed commit identity.
     */
    public function create(
        string $status,
        string $path,
        ?string $commitSha = null,
        array $diagnostics = [],
    ): DependabotFragmentResult;
}
