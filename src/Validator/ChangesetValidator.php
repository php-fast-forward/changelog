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

namespace FastForward\Changelog\Validator;

use FastForward\Changelog\Changeset\Parser\ChangesetParserInterface;
use FastForward\Changelog\Changeset\Store\ChangesetStoreInterface;
use FastForward\Changelog\Validation\Factory\ValidationReportFactoryInterface;
use FastForward\Changelog\Validation\ValidationReport;
use RuntimeException;

/**
 * Coordinates discovery, reading, and independent fragment parsing.
 *
 * The validator MUST process every discovered path. One invalid fragment fails
 * the aggregate gate but MUST NOT erase valid sibling results or diagnostics.
 */
final readonly class ChangesetValidator implements ChangesetValidatorInterface
{
    /**
     * Composes mockable domain and I/O boundaries.
     *
     * @param ChangesetStoreInterface          $store         Replaceable fragment discovery and regular-file reading boundary.
     * @param ChangesetParserInterface         $parser        Schema parser that retains per-fragment diagnostics instead of silently
     *                                                        dropping invalid input.
     * @param ValidationReportFactoryInterface $reportFactory Constructor for aggregate accepted fragments, content
     *                                                        hashes and diagnostics.
     */
    public function __construct(
        private ChangesetStoreInterface $store,
        private ChangesetParserInterface $parser,
        private ValidationReportFactoryInterface $reportFactory,
    ) {}

    /**
     * Validates all fragments and applies the selected fragment-presence policy.
     *
     * Inventory validation may accept an empty directory; it never suppresses
     * diagnostics for a discovered invalid fragment.
     *
     * @param  string           $directory       Explicit consumer directory used for repository or fragment operations.
     * @param  bool             $waived          Whether the caller supplies an authorized fragment-presence waiver; malformed fragments still
     *                                           fail.
     * @param  bool             $requireFragment Whether an empty explicit fragment selection must produce a presence error.
     * @return ValidationReport Aggregate valid fragment values, exact content hashes and all discovered diagnostics.
     * @throws RuntimeException When the fragment discovery or file-reading boundary fails rather than returning a
     *                          validation result.
     */
    public function validate(
        string $directory,
        bool $waived = false,
        bool $requireFragment = true,
    ): ValidationReport {
        $paths = $this->store->paths($directory);

        if (null === $paths) {
            return $this->reportFactory->create(
                [],
                [$directory => ['Changeset directory MUST be regular rather than a symbolic link.']],
                $waived,
            );
        }

        return $this->validatePaths($directory, $paths, $waived, $requireFragment);
    }

    /**
     * Validates an explicit candidate list without consulting other pending files.
     *
     * This entrypoint MUST be used for ordinary pull requests so fragments that
     * already exist on the base cannot invalidate a waiver or enter the report.
     *
     * @param  string           $directory       Configured discovery root; explicit candidates must be direct children of this
     *                                           directory.
     * @param  list<string>     $paths           Exact fragment candidate paths selected by the caller, without adding inherited files.
     * @param  bool             $waived          Whether the caller supplies an authorized fragment-presence waiver; malformed fragments still
     *                                           fail.
     * @param  bool             $requireFragment Whether an empty explicit fragment selection must produce a presence error.
     * @return ValidationReport Validation of only the supplied candidates, preserving valid siblings and every failure.
     * @throws RuntimeException When the fragment discovery or file-reading boundary fails rather than returning a
     *                          validation result.
     */
    public function validatePaths(
        string $directory,
        array $paths,
        bool $waived = false,
        bool $requireFragment = true,
    ): ValidationReport {
        $changesets = [];
        $errors = [];
        $hashes = [];

        if ($waived && [] !== $paths) {
            $errors['@waiver'] = ['A waiver cannot be combined with changeset fragments.'];
        }

        if ($requireFragment && ! $waived && [] === $paths) {
            $errors['@directory'] = ['At least one changeset fragment or an explicit waiver is required.'];
        }

        foreach ($paths as $path) {
            if (! $this->isDirectFragmentPath($directory, $path)) {
                $errors[$path] = ['Changeset Markdown must be a direct child of its configured directory.'];

                continue;
            }

            $contents = $this->store->read($path);

            if (null === $contents) {
                $errors[$path] = ['Changeset fragments MUST be regular files rather than symbolic links.'];

                continue;
            }

            $result = $this->parser->parse($path, $contents);

            if ($result->isValid()) {
                $changesets[] = $result->changeset;
                $hashes[$path] = hash('sha256', $contents);

                continue;
            }

            $errors[$result->id] = $result->errors;
        }

        return $this->reportFactory->create(
            $changesets,
            $errors,
            $waived,
            ...([] === $hashes ? [] : [$hashes]),
        );
    }

    /**
     * Determines whether a discovered path is one direct child of the root.
     *
     * @param  string $directory Explicit consumer directory used for repository or fragment operations.
     * @param  string $path      Discovered candidate path, using the same consumer-root representation as the configured
     *                           directory.
     * @return bool   Whether the path is exactly one direct child after separator normalization.
     */
    private function isDirectFragmentPath(string $directory, string $path): bool
    {
        $root = rtrim(str_replace('\\', '/', $directory), '/');
        $candidate = str_replace('\\', '/', $path);
        $prefix = $root . '/';

        if (! str_starts_with($candidate, $prefix)) {
            return false;
        }

        $relative = substr($candidate, strlen($prefix));

        return '' !== $relative && ! str_contains($relative, '/');
    }
}
