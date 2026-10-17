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

namespace FastForward\Changelog\Release\Reference;

use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Release\ReleaseOptions;
use InvalidArgumentException;
use RuntimeException;

/** Resolves optional presentation references without rewriting fragments or inferring contributor credit. */
interface PullRequestReferenceResolverInterface
{
    /**
     * Enriches absent PR numbers only through committed fragment and merged GitHub evidence.
     *
     * Disabled resolution preserves the input array and performs no I/O. Positive references
     * are recomputed during publication; missing or changed evidence never authorizes a write.
     *
     * @param  ReleaseOptions           $options        Trusted consumer paths, explicit repository and opt-in resolution policy.
     * @param  ?string                  $baseSha        Immutable source base, or null when no committed origin is available.
     * @param  list<Changeset>          $changesets     Validated fragments whose explicit metadata remains authoritative.
     * @param  array<string,string>     $acceptedHashes Optional accepted inventory keyed by absolute fragment path; an empty map denotes committed reconstruction.
     * @return list<Changeset>          Original records, with new immutable records only for uniquely proved absent PR numbers.
     * @throws InvalidArgumentException When enabled resolution receives an invalid repository or immutable base.
     * @throws RuntimeException         When evidence is incomplete, unavailable, inconsistent or ambiguously identifies multiple PRs.
     */
    public function resolve(
        ReleaseOptions $options,
        ?string $baseSha,
        array $changesets,
        array $acceptedHashes = [],
    ): array;
}
