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

namespace FastForward\Changelog\Template;

use FastForward\Changelog\Release\ReleaseOptions;

/** Selects the built-in presentation or an explicit trusted project PHP template. */
interface TemplateResolverInterface
{
    /** Resolves presentation without requiring initialization or a local defaults file. */
    public function resolve(ReleaseOptions $options): TemplateInterface;
}
