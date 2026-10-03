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

namespace FastForward\Changelog\Automation\VersionPullRequest\Factory;

use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestException;

/** Concentrates immutable automation outcome and diagnostic construction without external effects. */
final class VersionPullRequestExceptionFactory implements VersionPullRequestExceptionFactoryInterface
{
    /** Retains explicitly validated data without inspecting host or GitHub state. */
    public function create(string $message): VersionPullRequestException
    {
        return new VersionPullRequestException($message);
    }
}
