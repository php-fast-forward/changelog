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
use FastForward\Changelog\Changeset\VersionImpact;

/**
 * Provides the sole construction boundary for changeset values.
 */
final readonly class ChangesetFactory implements ChangesetFactoryInterface
{
    /**
     * Creates one immutable changeset from already validated data.
     */
    public function create(
        string $id,
        Category $category,
        ?int $issue,
        ?int $pullRequest,
        ?string $author,
        string $description,
        ?VersionImpact $type = null,
    ): Changeset {
        return new Changeset($id, $category, $issue, $pullRequest, $author, $description, $type);
    }
}
