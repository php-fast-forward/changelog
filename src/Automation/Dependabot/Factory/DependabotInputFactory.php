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

use FastForward\Changelog\Automation\Dependabot\DependabotInput;
use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\GitHub\Factory\GitHubExceptionFactoryInterface;
use RuntimeException;

/** Concentrates validation and construction of the trusted metadata snapshot. */
final readonly class DependabotInputFactory implements DependabotInputFactoryInterface
{
    /**
     * Injects diagnostic construction, without loading environment, filesystem or GitHub state.
     *
     * @param GitHubExceptionFactoryInterface $exceptions Constructor for controlled API diagnostics that withhold raw
     *                                                    responses and credentials.
     */
    public function __construct(
        private GitHubExceptionFactoryInterface $exceptions,
    ) {}

    /**
     * Rejects unsafe metadata before returning deterministic names and alert numbers.
     *
     * Normalizes only the exact upstream github_actions ecosystem to canonical github-actions.
     *
     * @param  int              $pullRequest          Positive pull-request number associated with the approved automation snapshot.
     * @param  string           $expectedHeadSha      Complete Dependabot PR head bound to the verified metadata snapshot.
     * @param  list<string>     $packageNames         Explicit updated package identifiers collected from verified Dependabot
     *                                                metadata.
     * @param  string           $dependencyType       Verified production, development or indirect dependency scope.
     * @param  string           $ecosystem            Verified dependency ecosystem, with the upstream GitHub Actions alias normalized by the
     *                                                factory.
     * @param  list<int>        $securityAlertNumbers Positive PR-associated alert numbers that require current matching
     *                                                security evidence.
     * @param  bool             $includeDev           Whether direct development dependency updates may generate a fragment.
     * @param  bool             $includeActions       Whether GitHub Actions dependency updates may generate a fragment.
     * @return DependabotInput  Validated deterministic dependency metadata with deduplicated sorted packages and alerts.
     * @throws RuntimeException When trusted package, scope, ecosystem, PR/head or alert metadata is unsafe or
     *                          unsupported.
     */
    public function create(
        int $pullRequest,
        string $expectedHeadSha,
        array $packageNames,
        string $dependencyType,
        string $ecosystem,
        array $securityAlertNumbers = [],
        bool $includeDev = true,
        bool $includeActions = true,
    ): DependabotInput {
        $ecosystem = 'github_actions' === $ecosystem ? 'github-actions' : $ecosystem;

        if ($pullRequest < 1 || ! GitHubEvidence::sha($expectedHeadSha) || [] === $packageNames
            || ! in_array($dependencyType, ['direct:production', 'direct:development', 'indirect'], true)
            || 1 !== preg_match('/\A[a-z][a-z0-9-]*\z/', $ecosystem)
        ) {
            throw $this->exceptions->failure(
                'Dependabot metadata requires a positive PR, full head SHA and supported dependency scope/ecosystem.',
            );
        }

        foreach ($packageNames as $name) {
            if (! is_string($name) || 1 !== preg_match(
                '/\A[A-Za-z0-9@_][A-Za-z0-9@_\/.+-]*\z/',
                $name,
            ) || str_contains(
                $name,
                '..',
            )) {
                throw $this->exceptions->failure('Dependabot package names must be safe explicit package identifiers.');
            }
        }

        foreach ($securityAlertNumbers as $number) {
            if (! is_int($number) || $number < 1) {
                throw $this->exceptions->failure('Dependabot security evidence requires positive alert numbers.');
            }
        }
        $packageNames = array_values(array_unique($packageNames));
        sort($packageNames, SORT_STRING);
        $securityAlertNumbers = array_values(array_unique($securityAlertNumbers));
        sort($securityAlertNumbers, SORT_NUMERIC);

        return new DependabotInput(
            $pullRequest,
            $expectedHeadSha,
            $packageNames,
            $dependencyType,
            $ecosystem,
            $securityAlertNumbers,
            $includeDev,
            $includeActions,
        );
    }
}
