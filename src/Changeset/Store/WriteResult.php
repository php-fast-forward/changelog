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

namespace FastForward\Changelog\Changeset\Store;

/**
 * Describes every outcome of inspecting or executing an exclusive write.
 *
 * Callers MUST distinguish an existing identifier, an unsafe symbolic path,
 * and unavailable serialization so each condition receives the correct exit
 * code and diagnostic.
 */
enum WriteResult: string
{
    case Available = 'available';
    case Created = 'created';
    case Existing = 'existing';
    case UnsafePath = 'unsafe-path';
    case LockUnavailable = 'lock-unavailable';
}
