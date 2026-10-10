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

namespace FastForward\Changelog\Changeset;

use FastForward\Changelog\Version\VersionImpact;

/**
 * Holds one validated and independently identified release fragment.
 *
 * The complete filename is the canonical identifier. Pull-request and author
 * metadata MAY repeat across fragments because one PR can describe multiple
 * independently consumable changes.
 */
final readonly class Changeset
{
    /**
     * Stores normalized fragment data after schema validation.
     *
     * @param string             $id          canonical fragment filename, including `.md`
     * @param Category           $category    Keep a Changelog category
     * @param positive-int|null  $issue       related issue number, when one exists
     * @param positive-int|null  $pullRequest related pull-request number
     * @param string|null        $author      optional GitHub login without a leading `@`
     * @param string             $description non-empty Markdown body
     * @param VersionImpact|null $type        explicit impact; an omitted value uses the category default
     */
    public function __construct(
        public string $id,
        public Category $category,
        public ?int $issue,
        public ?int $pullRequest,
        public ?string $author,
        public string $description,
        ?VersionImpact $type = null,
    ) {
        $this->type = $type ?? $category->inferredImpact();
    }

    /**
     * The impact is materialized once so serialized fragments retain their meaning.
     */
    public VersionImpact $type;

    /**
     * Returns the persisted effective impact; an explicit value overrides its category.
     */
    public function effectiveImpact(): VersionImpact
    {
        return $this->type;
    }
}
