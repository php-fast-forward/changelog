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

namespace FastForward\Changelog\History\Factory;

use InvalidArgumentException;

/** Constructs history diagnostics at the explicit exception-construction boundary. */
final class HistoryExceptionFactory implements HistoryExceptionFactoryInterface
{
    /** Creates the requested deterministic diagnostic. */
    public function invalid(string $message): InvalidArgumentException
    {
        return new InvalidArgumentException($message);
    }
}
