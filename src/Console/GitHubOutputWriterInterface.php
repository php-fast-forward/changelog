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

namespace FastForward\Changelog\Console;

/** Encodes one machine result and optionally writes its declared GitHub Action outputs. */
interface GitHubOutputWriterInterface
{
    /** Returns exact JSON; malformed scalar outputs or failed output-file writes MUST throw before reporting success. */
    public function write(array $result): string;
}
