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

namespace FastForward\Changelog\Automation\Output;

use InvalidArgumentException;
use JsonException;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

/** Encodes one machine result and optionally writes its declared GitHub Action outputs. */
interface GitHubOutputWriterInterface
{
    /**
     * Returns exact JSON; malformed scalar outputs or failed output-file writes MUST throw before reporting success.
     *
     * @param  array<string,mixed>      $result Stable operation result map encoded as JSON and, where configured, guarded
     *                                          Action outputs.
     * @return string                   Single-line JSON representation; configured file outputs are appended together under a lock.
     * @throws InvalidArgumentException When a declared scalar or configured output-file path cannot be written safely.
     * @throws JsonException            When the complete result cannot be encoded as JSON.
     * @throws IOExceptionInterface     When the locked caller-owned output append fails.
     */
    public function write(array $result): string;
}
