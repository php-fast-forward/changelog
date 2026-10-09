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

namespace FastForward\Changelog\Release;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Template\TemplateInterface;

/** Builds readable localized release notes while preserving descriptions and useful links. */
final readonly class ReleaseNotesRenderer implements ReleaseNotesRendererInterface
{
    /**
     * Renders descriptions and references without leaking technical fragment metadata.
     * @param list<Changeset> $changesets independent author records
     */
    public function render(array $changesets, TemplateInterface $template, ?string $repository = null): string
    {
        usort($changesets, static fn(Changeset $left, Changeset $right): int => strcmp($left->id, $right->id));
        $sections = [];
        foreach (Category::cases() as $category) {
            $entries = [];
            foreach ($changesets as $change) {
                if ($change->category !== $category) {
                    continue;
                }
                $multiline = str_contains($change->description, "\n");
                $body = ($multiline ? "-\n  " : '- ') . str_replace("\n", "\n  ", $change->description);
                $references = $this->references($change, $repository);
                $suffix = '' === $references ? '' : ($multiline ? "\n\n  " : ' ') . '(' . $references . ')';
                $entries[] = $body . $suffix;
            }
            if ([] !== $entries) {
                $sections[] = $template->categoryHeading($category->value) . "\n\n" . implode("\n", $entries);
            }
        }

        return [] === $sections ? '' : implode("\n\n", $sections) . "\n";
    }

    /** Links trusted numeric references when a repository is known, retaining plain references otherwise. */
    private function references(Changeset $change, ?string $repository): string
    {
        $references = [];
        foreach (['pull' => $change->pullRequest, 'issues' => $change->issue] as $kind => $number) {
            if (null !== $number) {
                $references[] = null === $repository ? '#' . $number
                    : '[#' . $number . '](https://github.com/' . $repository . '/' . $kind . '/' . $number . ')';
            }
        }
        if (null !== $change->author) {
            $references[] = str_ends_with($change->author, '[bot]') ? '@' . $change->author
                : '[@' . $change->author . '](https://github.com/' . rawurlencode($change->author) . ')';
        }

        return implode(', ', $references);
    }
}
