<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 * @see       https://github.com/php-fast-forward/changelog
 */

namespace FastForward\Changelog\Automation;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** Connects typed package services to explicit Action inputs without reading runner state. */
interface AutomationRunnerInterface
{
    /**
     * Returns stable machine data; invalid inputs and unavailable authority MUST fail.
     *
     * PR feedback returns status=invalid for contribution errors so the CLI can persist feedback before exiting 1.
     *
     * @param  string                   $operation Supported package operation to execute with only its declared input surface.
     * @param  array<string,mixed>      $inputs    Explicit Action input map; values are validated without reading process
     *                                             environment.
     * @return array                    Stable operation-specific output map after complete explicit-input validation.
     * @throws InvalidArgumentException When explicit operation inputs or selected consumer settings are invalid.
     * @throws RuntimeException         When required authority, transaction evidence or a successful operation outcome cannot
     *                                  be established.
     * @throws JsonException            When explicit JSON metadata or machine-readable validation output cannot be encoded or
     *                                  decoded.
     */
    public function run(string $operation, array $inputs): array;
}
