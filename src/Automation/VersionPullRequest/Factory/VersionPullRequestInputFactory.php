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

use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestInput;

/** Concentrates input validation and immutable settings construction. */
final readonly class VersionPullRequestInputFactory implements VersionPullRequestInputFactoryInterface
{
    /** Injects controlled diagnostic construction without resolving environment or repository state. */
    public function __construct(
        private VersionPullRequestExceptionFactoryInterface $exceptions,
    ) {}

    /** Ensures managed branches cannot alias the base or inject ref syntax or presentation controls. */
    public function create(
        string $baseBranch = 'main',
        string $managedBranch = 'changelog/version',
        string $automationActor = 'github-actions[bot]',
        string $title = 'chore: update changelog',
        bool $dryRun = false,
    ): VersionPullRequestInput {
        if (! $this->branch($baseBranch) || ! $this->branch($managedBranch) || $baseBranch === $managedBranch
            || 1 !== preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})\[bot\]\z/', $automationActor)
            || '' === trim($title) || strlen($title) > 256 || preg_match('/[\x00-\x1f\x7f]/', $title)
        ) {
            throw $this->exceptions->create(
                'Version PR requires distinct safe Git branches, a Bot account and a single-line title of at most 256 bytes.',
            );
        }

        return new VersionPullRequestInput($baseBranch, $managedBranch, $automationActor, $title, $dryRun);
    }

    /** Implements the supported safe subset of Git branch refs without invoking Git on user input. */
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
