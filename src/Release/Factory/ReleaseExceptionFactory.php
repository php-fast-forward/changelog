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

/** Provides replaceable construction for release diagnostics. */
final readonly class ReleaseExceptionFactory implements ReleaseExceptionFactoryInterface
{
    /**
     * Returns the supplied explicit-input diagnostic unchanged.
     *
     * @param string         $message  Diagnostic text retained in the domain exception.
     * @param Throwable|null $previous Original integration failure retained as the exception cause, when supplied.
     *
     * @return InvalidArgumentException Invalid-input exception retaining the explicit domain diagnostic.
     */
    public function invalid(string $message, ?Throwable $previous = null): InvalidArgumentException
    {
        return new InvalidArgumentException($message, previous: $previous);
    }

    /**
     * Constructs a recoverable transaction failure, retaining its original cause.
     *
     * @param string         $message  Diagnostic text retained in the domain exception.
     * @param Throwable|null $previous Original integration failure retained as the exception cause, when supplied.
     *
     * @return RuntimeException Recoverable domain failure retaining the diagnostic and optional original cause.
     */
    public function failure(string $message, ?Throwable $previous = null): RuntimeException
    {
        return new RuntimeException($message, 0, $previous);
    }
}
