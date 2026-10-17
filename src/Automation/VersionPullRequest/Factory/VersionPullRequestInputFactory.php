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

namespace FastForward\Changelog\Automation\VersionPullRequest\Factory;

use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestException;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestInput;

/** Concentrates input validation and immutable settings construction. */
final readonly class VersionPullRequestInputFactory implements VersionPullRequestInputFactoryInterface
{
    /**
     * Rejects unsafe branches, identities and presentation controls before planning.
     *
     * Literal titles retain the 256-byte bound; known placeholder templates may use 1024 bytes before resolution.
     *
     * @param  string                      $baseBranch      Trusted release-line branch that supplies the fresh source base.
     * @param  string                      $managedBranch   Exclusive automation branch reserved for the generated version PR.
     * @param  string                      $automationActor Canonical GitHub Bot login required to own and sign generated commits.
     * @param  string                      $title           Single-line PR title or supported version-placeholder template; never publication authority.
     * @param  bool                        $dryRun          Whether to return the proposed transaction without creating or updating remote content.
     * @return VersionPullRequestInput     Validated immutable branch, Bot, title and preview settings.
     * @throws VersionPullRequestException When branch, Bot identity or bounded single-line title settings are invalid.
     */
    public function create(
        string $baseBranch = 'main',
        string $managedBranch = 'changelog/version',
        string $automationActor = 'github-actions[bot]',
        string $title = 'chore: update changelog',
        bool $dryRun = false,
    ): VersionPullRequestInput {
        $template = str_contains($title, '{current_version}') || str_contains($title, '{next_version}');
        $maximum = $template ? VersionPullRequestInput::MAX_TEMPLATE_BYTES : VersionPullRequestInput::MAX_TITLE_BYTES;

        if (! $this->branch($baseBranch) || ! $this->branch($managedBranch) || $baseBranch === $managedBranch
            || 1 !== preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})\[bot\]\z/', $automationActor)
            || '' === trim($title) || strlen($title) > $maximum || preg_match('/[\x00-\x1f\x7f]/', $title)
        ) {
            throw new VersionPullRequestException(
                'Version PR requires distinct safe Git branches, a Bot account and a single-line title of at most 256 bytes or version template of at most 1024 bytes.',
            );
        }

        return new VersionPullRequestInput($baseBranch, $managedBranch, $automationActor, $title, $dryRun);
    }

    /**
     * Implements the supported safe subset of Git branch refs without invoking Git on user input.
     *
     * @param  string $branch Validated branch name used for the exact remote reference or release line.
     * @return bool   Whether the name belongs to the supported safe subset of Git branch refs.
     */
    private function branch(string $branch): bool
    {
        if (strlen($branch) > 255 || 1 !== preg_match('~\A[A-Za-z0-9_][A-Za-z0-9_/.+-]*\z~', $branch)
            || str_contains($branch, '..') || str_contains($branch, '//') || str_ends_with(
                $branch,
                '/',
            ) || str_ends_with(
                $branch,
                '.',
            )
        ) {
            return false;
        }

        return array_all(
            explode('/', $branch),
            fn($part) => !(str_starts_with($part, '.') || str_ends_with($part, '.lock')),
        );
    }
}
