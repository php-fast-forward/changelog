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
use FastForward\Changelog\Version\Factory\VersionResolutionFactoryInterface;

/**
 * Applies the greatest fragment impact to a strict semantic-version core.
 */
final readonly class NextVersionResolver implements NextVersionResolverInterface
{
    /**
     * Composes impact aggregation and result construction boundaries.
     */
    public function __construct(
        private VersionImpactResolverInterface $impactResolver,
        private VersionResolutionFactoryInterface $resultFactory,
    ) {}

    /**
     * Resolves a deterministic next version or returns validation diagnostics.
     *
     * Build metadata MUST NOT leak into the next numeric release. Prerelease
     * inputs MUST be rejected until their promotion behavior is specified.
     */
    public function resolve(string $currentVersion, array $changesets): VersionResolution
    {
        $pattern = '/^[vV]?(?<major>0|[1-9]\d*)\.(?<minor>0|[1-9]\d*)\.(?<patch>0|[1-9]\d*)'
            . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/';

        if (1 !== preg_match($pattern, $currentVersion, $matches)) {
            return $this->resultFactory->invalid([
                sprintf('Current version "%s" is not a supported semantic version.', $currentVersion),
            ]);
        }

        $impact = $this->impactResolver->resolve($changesets);

        if (! $impact instanceof VersionImpact) {
            return $this->resultFactory->invalid(['At least one changeset is required to resolve a version.']);
        }

        $major = $matches['major'];
        $minor = $matches['minor'];
        $patch = $matches['patch'];
        $nextVersion = match ($impact) {
            VersionImpact::Major => sprintf('%s.0.0', $this->increment($major)),
            VersionImpact::Minor => sprintf('%s.%s.0', $major, $this->increment($minor)),
            VersionImpact::Patch => sprintf('%s.%s.%s', $major, $minor, $this->increment($patch)),
        };

        return $this->resultFactory->resolved($nextVersion, $impact);
    }

    /**
     * Increments an unsigned decimal component without machine-integer limits.
     *
     * Semantic-version components MAY exceed the platform integer size, so
     * callers MUST retain them as strings throughout the calculation.
     */
    private function increment(string $component): string
    {
        $digits = str_split($component);

        for ($index = count($digits) - 1; 0 <= $index; --$index) {
            if ('9' === $digits[$index]) {
                $digits[$index] = '0';

                continue;
            }

            $digits[$index] = (string) ((int) $digits[$index] + 1);

            return implode('', $digits);
        }

        return '1' . implode('', $digits);
    }
}
