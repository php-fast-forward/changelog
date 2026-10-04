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

namespace FastForward\Changelog\Fragment\Factory;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Constructs exceptions only at the explicit construction boundary. */
final readonly class FragmentExceptionFactory implements FragmentExceptionFactoryInterface
{
    /** Describes invalid author input without reading host state or writing files. */
    public function invalid(string $message): InvalidArgumentException
    {
        return new InvalidArgumentException($message);
    }

    /** Retains an integration error as the previous exception for diagnosis. */
    public function failure(string $message, ?Throwable $previous = null): RuntimeException
    {
        return new RuntimeException($message, previous: $previous);
    }
}
