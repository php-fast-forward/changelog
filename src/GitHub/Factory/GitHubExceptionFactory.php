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

namespace FastForward\Changelog\GitHub\Factory;

use RuntimeException;

/** Keeps GitHub failures independent from raw headers, bodies and access tokens. */
final class GitHubExceptionFactory implements GitHubExceptionFactoryInterface
{
    /** Constructs a diagnostic containing only the caller's controlled explanation. */
    public function failure(string $message): RuntimeException
    {
        return new RuntimeException($message);
    }
}
