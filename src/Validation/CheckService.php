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

use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\Parser\ChangesetParserInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Validator\ChangesetValidatorInterface;
use Throwable;

/** Enforces fragment contribution without trusting existing pending files as additions. */
final readonly class CheckService implements CheckServiceInterface
{
    /**
     * Injects schema checking, Git discovery and aggregate construction boundaries.
     *
     * @param ChangesetValidatorInterface $validator Checks the complete fragment inventory, canonical paths and accepted bytes without fail-fast loss.
     * @param GitRepositoryInterface      $git       Observes repository identities, committed bytes and scoped operations through an injected boundary.
     */
    public function __construct(
        private ChangesetValidatorInterface $validator,
        private GitRepositoryInterface $git,
    ) {}

    /**
     * Preserves all inventory diagnostics and accumulates independent diff failures.
     *
     * New A/C paths and R paths from outside the fragment scope are contributions.
     * Ordinary contributions cannot mutate inherited fragments. Verified human
     * maintenance may curate regular pending files when the same committed PR
     * adds a validated replacement. Author-only corrections need no replacement.
     * Managed version transactions retain their independent consumption proof;
     * only those transactions may pass the legacy tracked receipt-path guard.
     *
     * @param ReleaseOptions $options                 Validated consumer paths, presentation settings and source-selection policy.
     * @param string|null    $since                   Optional committed Git baseline used to inspect this contribution's exact delta.
     * @param bool           $centralChangeAuthorized Whether independently verified policy permits central-history edits for this exact checkout.
     * @param bool           $waiverAuthorized        Whether verified maintainer policy permits no new fragment without hiding other validation failures.
     * @param string         $authorizationKind       Verified policy kind; maintenance permits explicit pending-note curation while managed-version retains release-consumption authority.
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
            return new ValidationReport($inventory->changesets, $errors, $waiverAuthorized, $inventory->hashes);
        }

        try {
            $changes = $this->git->changesSince($options->workingDirectory, $since);
        } catch (Throwable $failure) {
            $errors['@git'][] = 'Git comparison is unavailable: ' . $failure->getMessage();

            return new ValidationReport($inventory->changesets, $errors, $waiverAuthorized, $inventory->hashes);
        }

        $added = $this->contributionPaths($options, $changes);
        $selected = $this->validator->validatePaths($directory, $added, false, false);
        $curation = $centralChangeAuthorized && 'maintenance' === $authorizationKind
            && [] === $inventory->errors && [] === $selected->errors
            && $this->hasCommittedReplacement($options, $since, $changes, $selected, $inventory);
        $errors = $this->inspectChanges(
            $options,
            $since,
            $changes,
            $inventory,
            $centralChangeAuthorized,
            $authorizationKind,
            $curation,
        );

        foreach ($selected->errors as $id => $messages) {
            $errors[$id] = array_values(array_unique([...($errors[$id] ?? []), ...$messages]));
        }

        if ([] === $selected->changesets && ! $waiverAuthorized && ! $centralChangeAuthorized) {
            $errors['@contribution'][] = 'A pull request must add a valid new fragment or have a verified maintainer waiver; existing pending fragments do not satisfy this gate.';
        }

        return new ValidationReport($inventory->changesets, $errors, $waiverAuthorized, $inventory->hashes);
    }

    /**
     * Selects new contribution paths without authorizing changes to inherited notes.
     *
     * @param  ReleaseOptions                                          $options Consumer-relative fragment root and working directory.
     * @param  list<array{path:string,previous:?string,status:string}> $changes Exact committed comparison, including rename sources.
     * @return list<string>                                            Unique absolute paths for the existing new-fragment validator.
     */
    private function contributionPaths(ReleaseOptions $options, array $changes): array
    {
        $root = trim(str_replace('\\', '/', $options->fragmentDirectory), '/');
        $directory = rtrim(str_replace('\\', '/', $options->workingDirectory), '/') . '/' . $root;
        $paths = [];

        foreach ($changes as $change) {
            $path = $this->normalizeGitPath($change['path']);
            $previous = null === $change['previous'] ? null : $this->normalizeGitPath($change['previous']);
            $status = $change['status'][0] ?? '';

            if ($this->isFragmentPath($path, $root)
                && (in_array($status, ['A', 'C'], true)
                    || ('R' === $status && (null === $previous || !$this->isFragmentPath($previous, $root))))) {
                $paths[] = $directory . '/' . substr($path, strlen($root) + 1);
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Requires an actual new regular committed fragment before allowing editorial replacement.
     *
     * Copies and renames are not replacement authority. Validation must have accepted
     * the new path and its exact HEAD bytes; a dirty/untracked note cannot unlock curation.
     *
     * @param  ReleaseOptions                                          $options   Validated consumer paths.
     * @param  string                                                  $since     Immutable comparison base containing the original pending notes.
     * @param  list<array{path:string,previous:?string,status:string}> $changes   Exact committed delta.
     * @param  ValidationReport                                        $selected  Valid new contributions already selected from that delta.
     * @param  ValidationReport                                        $inventory Accepted full inventory with exact absolute-path hashes.
     * @return bool                                                    Whether at least one newly added validated regular blob is absent from the base.
     */
    private function hasCommittedReplacement(
        ReleaseOptions $options,
        string $since,
        array $changes,
        ValidationReport $selected,
        ValidationReport $inventory,
    ): bool {
        $root = trim(str_replace('\\', '/', $options->fragmentDirectory), '/');
        $directory = rtrim(str_replace('\\', '/', $options->workingDirectory), '/') . '/' . $root;
        $ids = array_map(static fn(Changeset $fragment): string => $fragment->id, $selected->changesets);

        foreach ($changes as $change) {
            $path = $this->normalizeGitPath($change['path']);
            $id = substr($path, strlen($root) + 1);

            if ('A' === $change['status'] && null === $change['previous'] && $this->isFragmentPath($path, $root)
                && in_array($id, $ids, true)
                && $this->isCommittedCurationChange(
                    $options, $since, $path, 'A', $inventory->hashes[$directory . '/' . $id] ?? '',
                )) {
                return true;
            }
        }

        return false;
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
     * @param  bool                                                    $curation                Whether verified human maintenance also has a committed validated replacement.
     * @return array<string,list<string>>                              Accumulated inventory and contribution-delta diagnostics.
     */
    private function inspectChanges(
        ReleaseOptions $options,
        string $since,
        array $changes,
        ValidationReport $inventory,
        bool $centralChangeAuthorized,
        string $authorizationKind,
        bool $curation,
    ): array {
        $fragmentRoot = trim(str_replace('\\', '/', $options->fragmentDirectory), '/');
        $directory = rtrim(str_replace('\\', '/', $options->workingDirectory), '/') . '/' . $fragmentRoot;
        $errors = $inventory->errors;
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
                continue;
            }

            if (('D' === $status && $managedVersion)
                || ('C' === $status && ! $currentFragment)
            ) {
                continue;
            }

            if ($curation && in_array($status, ['M', 'D'], true)
                && $this->isCommittedCurationChange(
                    $options, $since, $path, $status,
                    $inventory->hashes[$directory . '/' . substr($path, strlen($fragmentRoot) + 1)] ?? '',
                )) {
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

        return $errors;
    }

    /**
     * Confirms a direct canonical fragment A/M/D against exact regular Git entries and validated bytes.
     *
     * @param  ReleaseOptions $options      Consumer directory used for all Git observations.
     * @param  string         $base         Immutable comparison base.
     * @param  string         $path         Normalized repository-relative fragment path.
     * @param  string         $status       Internally selected A, M or D; other delta kinds never reach this method.
     * @param  string         $acceptedHash Accepted HEAD SHA-256; deletions have no remaining bytes to validate.
     * @return bool           Whether addition, modification or removal has complete committed regular-file evidence.
     */
    private function isCommittedCurationChange(
        ReleaseOptions $options,
        string $base,
        string $path,
        string $status,
        string $acceptedHash,
    ): bool {
        $root = trim(str_replace('\\', '/', $options->fragmentDirectory), '/');

        if (1 !== preg_match(ChangesetParserInterface::FILENAME_PATTERN, substr($path, strlen($root) + 1))) {
            return false;
        }

        try {
            $before = $this->git->readFileAt($options->workingDirectory, $base, $path);
            $after = $this->git->readFileAt($options->workingDirectory, 'HEAD', $path);
            $old = $this->git->filesAt($options->workingDirectory, $base, $path);
            $new = $this->git->filesAt($options->workingDirectory, 'HEAD', $path);
        } catch (Throwable) {
            return false;
        }

        $oldRegular = 1 === count($old) && ($old[0]['path'] ?? null) === $path
            && in_array($old[0]['mode'] ?? null, ['100644', '100755'], true);
        $newRegular = 1 === count($new) && ($new[0]['path'] ?? null) === $path
            && in_array($new[0]['mode'] ?? null, ['100644', '100755'], true);

        if ('D' === $status) {
            return null !== $before && $oldRegular && null === $after && [] === $new;
        }

        if (null === $after || !$newRegular || !hash_equals($acceptedHash, hash('sha256', $after))) {
            return false;
        }

        return 'A' === $status
            ? null === $before && [] === $old
            : null !== $before && $oldRegular && $old === $new;
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
