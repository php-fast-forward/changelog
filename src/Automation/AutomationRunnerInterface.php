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

/** Connects typed package services to explicit Action inputs without reading runner state. */
interface AutomationRunnerInterface
{
    /** Returns stable machine data; invalid, unauthorized or conflicted operations MUST fail. */
    public function run(string $operation, array $inputs): array;
}
