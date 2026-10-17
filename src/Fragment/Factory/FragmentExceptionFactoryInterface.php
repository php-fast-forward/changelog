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

/** Creates authoring failures while preserving useful paths and original causes. */
interface FragmentExceptionFactoryInterface
{
    /**
     * Returns a schema/argument error before fragment mutation.
     *
     * @param string $message Diagnostic text retained in the domain exception.
     *
     * @return InvalidArgumentException Invalid-input exception retaining the explicit domain diagnostic.
     */
    public function invalid(string $message): InvalidArgumentException;

    /**
     * Returns a recoverable integration failure with its original cause when present.
     *
     * @param string         $message  Diagnostic text retained in the domain exception.
     * @param Throwable|null $previous Original integration failure retained as the exception cause, when supplied.
     *
     * @return RuntimeException Recoverable domain failure retaining the diagnostic and optional original cause.
     */
    public function failure(string $message, ?Throwable $previous = null): RuntimeException;
}
