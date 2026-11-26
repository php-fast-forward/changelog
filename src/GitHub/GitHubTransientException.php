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

use RuntimeException;

/** Identifies a redacted transport or transient HTTP failure without authorizing a write retry. */
final class GitHubTransientException extends RuntimeException
{
    /**
     * Retains only a controlled diagnostic and bounded retry timing evidence.
     *
     * @param string $message           Controlled diagnostic without credentials, URLs or raw response bytes.
     * @param ?int   $retryAfterSeconds Explicit delta seconds; null means an unsupported Retry-After value forbids retry.
     */
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = 0,
    ) {
        parent::__construct($message);
    }
}
