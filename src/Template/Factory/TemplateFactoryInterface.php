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

namespace FastForward\Changelog\Template\Factory;

use FastForward\Changelog\Template\TemplateInterface;

/** Constructs one validated presentation from explicit caller-owned settings. */
interface TemplateFactoryInterface
{
    /**
     * Creates the built-in template with optional trusted PHP-array overrides.
     *
     * @param  array<string,mixed>       $overrides explicit presentation overrides
     * @throws \InvalidArgumentException when a locale or override is unsupported
     */
    public function create(string $locale = 'en', array $overrides = []): TemplateInterface;
}
