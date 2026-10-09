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

namespace FastForward\Changelog\Validation;

use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Validation\Factory\ValidationReportFactoryInterface;
use FastForward\Changelog\Validator\ChangesetValidatorInterface;
use Throwable;

/** Enforces fragment contribution without trusting existing pending files as additions. */
final readonly class CheckService implements CheckServiceInterface
{
    /** Injects schema checking, Git discovery and aggregate construction boundaries. */
    public function __construct(
        private ChangesetValidatorInterface $validator,
        private GitRepositoryInterface $git,
        private ValidationReportFactoryInterface $reports,
    ) {}

    /**
     * Preserves all inventory diagnostics and accumulates independent diff failures.
     *
     * New A/C paths and R paths from outside the fragment scope are contributions.
     * Renaming/mutating inherited fragments is rejected. Only verified managed
     * version transactions may change their receipt or consume pending fragments;
     * central-history maintenance MUST preserve both generated evidence and inputs.
     */
    public function check(
        ReleaseOptions $options,
        ?string $since = null,
        bool $centralChangeAuthorized = false,
        bool $waiverAuthorized = false,
        string $authorizationKind = 'ordinary',
    ): ValidationReport {
        $fragmentRoot = trim(str_replace('\\', '/', $options->fragmentDirectory), '/');
        $directory = rtrim(str_replace('\\', '/', $options->workingDirectory), '/') . '/' . $fragmentRoot;
        $inventory = $this->validator->validate($directory, false, false);
        $errors = $inventory->errors;

        if (null === $since) {
            return $this->reports->create($inventory->changesets, $errors, $waiverAuthorized, $inventory->hashes);
        }

        try {
            $changes = $this->git->changesSince($options->workingDirectory, $since);
        } catch (Throwable $failure) {
            $errors['@git'][] = 'Git comparison is unavailable: ' . $failure->getMessage();

            return $this->reports->create($inventory->changesets, $errors, $waiverAuthorized, $inventory->hashes);
        }

        $added = [];
        $central = str_replace('\\', '/', $options->changelogFile);
        $receipt = $fragmentRoot . '/release-plan.json';
        $managedVersion = $centralChangeAuthorized && 'managed-version' === $authorizationKind;

        foreach ($changes as $change) {
            $path = $this->normalizeGitPath($change['path']);
            $previous = null === $change['previous'] ? null : $this->normalizeGitPath($change['previous']);
            $status = $change['status'][0] ?? '';

            if (($central === $path || $central === $previous) && ! $centralChangeAuthorized) {
                $errors['@changelog'][] = 'Ordinary pull requests must not modify the consolidated changelog.';
            }

            if (($receipt === $path || $receipt === $previous) && ! $managedVersion) {
                $errors['@receipt'][] = 'Release receipts may change only in a verified managed version transaction.';
            }

            if ($fragmentRoot === $path || $fragmentRoot === $previous) {
                $errors['@directory'][] = 'The fragment root must remain a regular directory.';

                continue;
            }

            $currentFragment = $this->isFragmentPath($path, $fragmentRoot);
            $previousFragment = null !== $previous && $this->isFragmentPath($previous, $fragmentRoot);

            if (! $currentFragment && ! $previousFragment) {
                continue;
            }

            if (($currentFragment && in_array($status, ['A', 'C'], true))
                || ('R' === $status && $currentFragment && ! $previousFragment)
            ) {
                $added[] = $directory . '/' . substr($path, strlen($fragmentRoot) + 1);

                continue;
            }

            if (('D' === $status && $managedVersion)
                || ('C' === $status && ! $currentFragment)
            ) {
                continue;
            }

            $errors[$path][] = sprintf(
                'Inherited fragments must not be modified, deleted, renamed or changed in type (status %s).',
                $change['status'],
            );
        }

        $added = array_values(array_unique($added));
        $selected = $this->validator->validatePaths($directory, $added, false, false);

        foreach ($selected->errors as $id => $messages) {
            $errors[$id] = array_values(array_unique([...($errors[$id] ?? []), ...$messages]));
        }

        if ([] === $selected->changesets && ! $waiverAuthorized && ! $centralChangeAuthorized) {
            $errors['@contribution'][] = 'A pull request must add a valid new fragment or have a verified maintainer waiver; existing pending fragments do not satisfy this gate.';
        }

        return $this->reports->create($inventory->changesets, $errors, $waiverAuthorized, $inventory->hashes);
    }

    /**
     * Identifies Markdown candidates in the configured scope, including invalid layouts.
     */
    private function isFragmentPath(string $path, string $root): bool
    {
        return str_starts_with($path, $root . '/')
            && str_ends_with($path, '.md')
            && $path !== $root . '/AGENTS.md';
    }

    /**
     * Removes a harmless Git current-prefix without resolving paths through host state.
     */
    private function normalizeGitPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, './') ? substr($path, 2) : $path;
    }
}
