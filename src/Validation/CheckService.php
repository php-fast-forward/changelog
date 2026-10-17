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
    /**
     * Injects schema checking, Git discovery and aggregate construction boundaries.
     *
     * @param ChangesetValidatorInterface      $validator Checks the complete fragment inventory, canonical paths and accepted bytes without fail-fast loss.
     * @param GitRepositoryInterface           $git       Observes repository identities, committed bytes and scoped operations through an injected boundary.
     * @param ValidationReportFactoryInterface $reports   Constructs aggregate validation results retaining successful siblings and independent diagnostics.
     */
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
     * version transactions may consume pending fragments or pass the legacy tracked
     * receipt-path guard; new transactions create no tracked receipt. Maintenance
     * preserves pending descriptions, except
     * for removing one optional author line from otherwise identical regular blobs.
     *
     * @param ReleaseOptions $options                 Validated consumer paths, presentation settings and source-selection policy.
     * @param string|null    $since                   Optional committed Git baseline used to inspect this contribution's exact delta.
     * @param bool           $centralChangeAuthorized Whether independently verified policy permits central-history edits for this exact checkout.
     * @param bool           $waiverAuthorized        Whether verified maintainer policy permits no new fragment without hiding other validation failures.
     * @param string         $authorizationKind       Verified policy kind; managed-version alone permits consumption and maintenance permits only its narrow attribution correction.
     *
     * @return ValidationReport Complete valid pending inventory, accepted hashes, applied waiver state and all independent schema or contribution-delta diagnostics.
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

        [$added, $errors] = $this->inspectChanges(
            $options,
            $since,
            $changes,
            $inventory,
            $centralChangeAuthorized,
            $authorizationKind,
        );
        $selected = $this->validator->validatePaths($directory, array_values(array_unique($added)), false, false);

        foreach ($selected->errors as $id => $messages) {
            $errors[$id] = array_values(array_unique([...($errors[$id] ?? []), ...$messages]));
        }

        if ([] === $selected->changesets && ! $waiverAuthorized && ! $centralChangeAuthorized) {
            $errors['@contribution'][] = 'A pull request must add a valid new fragment or have a verified maintainer waiver; existing pending fragments do not satisfy this gate.';
        }

        return $this->reports->create($inventory->changesets, $errors, $waiverAuthorized, $inventory->hashes);
    }

    /**
     * Classifies committed paths against the verified contribution or maintenance policy.
     *
     * @param  ReleaseOptions                                          $options                 Consumer paths used to distinguish central history and pending notes.
     * @param  string                                                  $since                   Exact committed baseline for the narrowly allowed attribution correction.
     * @param  list<array{path:string,previous:?string,status:string}> $changes                 Git changes from that baseline to HEAD.
     * @param  ValidationReport                                        $inventory               Existing diagnostics and hashes of the accepted pending bytes.
     * @param  bool                                                    $centralChangeAuthorized Whether independently verified policy permits central-history edits.
     * @param  string                                                  $authorizationKind       Verified transaction kind controlling consumption or attribution correction.
     * @return array{0:list<string>,1:array<string,list<string>>}      Added absolute fragment paths and accumulated inventory/delta diagnostics.
     */
    private function inspectChanges(
        ReleaseOptions $options,
        string $since,
        array $changes,
        ValidationReport $inventory,
        bool $centralChangeAuthorized,
        string $authorizationKind,
    ): array {
        $fragmentRoot = trim(str_replace('\\', '/', $options->fragmentDirectory), '/');
        $directory = rtrim(str_replace('\\', '/', $options->workingDirectory), '/') . '/' . $fragmentRoot;
        $errors = $inventory->errors;
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

            if ('M' === $status && $centralChangeAuthorized && 'maintenance' === $authorizationKind
                && [] === $inventory->errors && $this->isAuthorRemoval(
                    $options, $since, $path,
                    $inventory->hashes[$directory . '/' . substr($path, strlen($fragmentRoot) + 1)] ?? '',
                )
            ) {
                continue;
            }

            $errors[$path][] = sprintf(
                'Inherited fragments must not be modified, deleted, renamed or changed in type (status %s).',
                $change['status'],
            );
        }

        return [$added, $errors];
    }

    /**
     * Accepts only removal of one frontmatter author line from committed regular
     * blobs of identical mode. Accepted parser bytes must equal HEAD; missing Git
     * evidence, other metadata, body, path or whitespace changes fail closed.
     *
     * @param ReleaseOptions $options      Validated consumer paths, presentation settings and source-selection policy.
     * @param string         $base         Committed Git baseline supplying the inherited fragment bytes and mode.
     * @param string         $path         Repository-relative committed Git path whose contribution or maintenance scope is inspected.
     * @param string         $acceptedHash SHA-256 of the exact fragment bytes already accepted by validation at HEAD.
     *
     * @return bool Whether committed regular blobs of identical mode differ only by removing exactly one frontmatter author line and HEAD matches accepted bytes.
     */
    private function isAuthorRemoval(ReleaseOptions $options, string $base, string $path, string $acceptedHash): bool
    {
        try {
            $before = $this->git->readFileAt($options->workingDirectory, $base, $path);
            $after = $this->git->readFileAt($options->workingDirectory, 'HEAD', $path);
            $oldEntries = $this->git->filesAt($options->workingDirectory, $base, $path);
            $newEntries = $this->git->filesAt($options->workingDirectory, 'HEAD', $path);
        } catch (Throwable) {
            return false;
        }

        if (null === $before || null === $after || ! hash_equals($acceptedHash, hash('sha256', $after))
            || $oldEntries !== $newEntries || 1 !== count($newEntries)
            || $newEntries[0]['path'] !== $path || ! in_array($newEntries[0]['mode'], ['100644', '100755'], true)
        ) {
            return false;
        }

        $lines = preg_split('/(\r\n|\n|\r)/', $before, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ('---' !== $lines[0]) {
            return false;
        }

        $removed = 0;

        for ($index = 2; $index < count($lines); $index += 2) {
            if ('---' === $lines[$index]) {
                return 1 === $removed && $after === implode('', $lines);
            }

            if (str_starts_with($lines[$index], 'author:')) {
                $lines[$index] = '';
                $lines[$index + 1] = '';
                ++$removed;
            }
        }

        return false;
    }

    /**
     * Identifies Markdown candidates in the configured scope, including invalid layouts.
     *
     * @param string $path Repository-relative committed Git path whose contribution or maintenance scope is inspected.
     * @param string $root Configured repository-relative fragment directory, without surrounding slashes.
     *
     * @return bool Whether the Git path is a Markdown candidate below the configured scope, excluding only the reserved root AGENTS.md.
     */
    private function isFragmentPath(string $path, string $root): bool
    {
        return str_starts_with($path, $root . '/')
            && str_ends_with($path, '.md')
            && $path !== $root . '/AGENTS.md';
    }

    /**
     * Removes a harmless Git current-prefix without resolving paths through host state.
     *
     * @param string $path Repository-relative committed Git path whose contribution or maintenance scope is inspected.
     *
     * @return string Git path using forward slashes with at most one harmless leading ./ removed, without filesystem resolution.
     */
    private function normalizeGitPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, './') ? substr($path, 2) : $path;
    }
}
