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

namespace FastForward\Changelog\Version;

use FastForward\Changelog\Changeset\VersionImpact;

/**
 * Reports a resolved next version or deterministic validation diagnostics.
 */
final readonly class VersionResolution
{
    /**
     * Stores the normalized calculation result.
     *
     * @param string|null        $nextVersion resolved numeric semantic version
     * @param VersionImpact|null $impact      greatest effective fragment impact
     * @param list<string>       $errors      calculation diagnostics
     */
    public function __construct(
        public ?string $nextVersion,
        public ?VersionImpact $impact,
        public array $errors,
    ) {}

    /**
     * Determines whether version resolution succeeded.
     */
    public function isValid(): bool
    {
        return null !== $this->nextVersion
            && $this->impact instanceof VersionImpact
            && [] === $this->errors;
    }
}
