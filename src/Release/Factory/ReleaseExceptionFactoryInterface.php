<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see       https://github.com/php-fast-forward/changelog
 * @see       https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Release\Factory;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Constructs validation and transaction failures without side effects. */
interface ReleaseExceptionFactoryInterface
{
    /** Describes an invalid explicit setting before mutation is attempted. */
    public function invalid(string $message, ?Throwable $previous = null): InvalidArgumentException;

    /** Retains a failed collaborator as the cause without converting failure into success. */
    public function failure(string $message, ?Throwable $previous = null): RuntimeException;
}
