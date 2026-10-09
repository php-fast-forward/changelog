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
     */
    public function validate(string $directory, bool $waived = false, bool $requireFragment = true): ValidationReport
    {
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
     * @param list<string> $paths exact candidates selected by the caller
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

        return $this->reportFactory->create($changesets, $errors, $waived, ...([] === $hashes ? [] : [$hashes]));
    }

    /**
     * Determines whether a discovered path is one direct child of the root.
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
