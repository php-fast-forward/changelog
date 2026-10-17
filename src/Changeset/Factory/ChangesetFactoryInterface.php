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

namespace FastForward\Changelog\Changeset\Factory;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Version\VersionImpact;

/**
 * Creates validated changeset values for parsers and application services.
 */
interface ChangesetFactoryInterface
{
    /**
     * Creates one immutable changeset.
     *
     * Callers MUST validate the schema before invoking this factory.
     *
     * @param string             $id          canonical fragment filename, including `.md`
     * @param Category           $category    Canonical lowercase Keep a Changelog category identifier.
     * @param positive-int|null  $issue       related issue number
     * @param positive-int|null  $pullRequest related pull-request number
     * @param string|null        $author      Optional GitHub login to credit, normalized without a leading @.
     * @param string             $description Validated Markdown description whose meaningful whitespace is retained.
     * @param VersionImpact|null $type        Explicit semantic impact override, or null to materialize the category default.
     *
     * @return Changeset Immutable normalized fragment; schema validation remains the caller's responsibility.
     */
    public function create(
        string $id,
        Category $category,
        ?int $issue,
        ?int $pullRequest,
        ?string $author,
        string $description,
        ?VersionImpact $type = null,
    ): Changeset;
}
