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

namespace FastForward\Changelog\GitHub;

use Closure;
use Throwable;

/** Bounds transient recovery to two extra attempts after the caller establishes retry safety. */
final readonly class GitHubRetryPolicy
{
    /**
     * Injects the delay boundary without sleeping during construction.
     *
     * @param Closure(int):void $delay Replaceable microsecond delay; unit tests supply a recording double.
     */
    public function __construct(
        private Closure $delay,
    ) {}

    /**
     * Waits only for a classified transient failure within the attempt and server-delay limits.
     *
     * Mutation callers must reconcile exact live state before invoking this method. A positive
     * return permits one further attempt, never a replay of the complete business operation.
     *
     * @param  ?Throwable $error   Classified failed request, or null when a response merely lacked matching state.
     * @param  int        $attempt One-based number of the request that just failed; at most three total attempts are allowed.
     * @return bool       Whether the caller may retry after the injected delay; false has no delay or other effects.
     */
    public function retry(?Throwable $error, int $attempt): bool
    {
        if (! $error instanceof GitHubTransientException || $attempt < 1 || $attempt >= 3
            || null === $error->retryAfterSeconds || $error->retryAfterSeconds < 0 || $error->retryAfterSeconds > 30) {
            return false;
        }
        ($this->delay)(max($attempt, $error->retryAfterSeconds) * 1_000_000);

        return true;
    }
}
