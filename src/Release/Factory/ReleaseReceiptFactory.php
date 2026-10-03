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

use FastForward\Changelog\Release\ReleaseReceipt;

/** Creates typed release evidence at the explicit construction boundary. */
final readonly class ReleaseReceiptFactory implements ReleaseReceiptFactoryInterface
{
    /** Captures validated data without generating or consulting host state. */
    public function create(array $data): ReleaseReceipt
    {
        return new ReleaseReceipt($data);
    }
}
