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

/** Constructs redacted diagnostics without retaining secret-bearing transport exceptions. */
interface GitHubExceptionFactoryInterface
{
    /**
     * Creates a failure from an application-controlled message without chained response data.
     *
     * @param  string           $message Controlled diagnostic or commit message supplied by the owning operation.
     * @return RuntimeException Controlled RuntimeException containing only the supplied diagnostic, without API I/O.
     */
    public function failure(string $message): RuntimeException;
}
