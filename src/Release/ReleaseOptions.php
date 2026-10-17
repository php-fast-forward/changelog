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

/** Carries validated business settings shared by CLI commands and Actions. */
final readonly class ReleaseOptions
{
    /**
     * Captures explicit consumer paths and release policy without reading host state.
     * The factory MUST validate these settings before any side effect is attempted.
     *
     * @param string      $workingDirectory    Consumer project root used to resolve managed paths independently of the installed package.
     * @param string      $fragmentDirectory   Project-relative directory containing independent pending Markdown fragments.
     * @param string      $changelogFile       Project-relative path of the sole consolidated history file.
     * @param string      $locale              Supported structural language identifier, en or pt-BR.
     * @param string      $template            Built-in presentation name or explicitly trusted project-relative PHP template path.
     * @param string      $baseRef             Git revision from which committed release evidence is selected.
     * @param string      $tagPrefix           Exact prefix removed from candidate stable Git tag identities.
     * @param string|null $repository          Optional GitHub owner/name used for source selection and reference links.
     * @param string      $source              History evidence policy: auto, github or tags.
     * @param bool        $resolvePullRequests Whether consolidation proves missing PR references through Git and GitHub; independent of the history source.
     */
    public function __construct(
        public string $workingDirectory,
        public string $fragmentDirectory = '.changelog',
        public string $changelogFile = 'CHANGELOG.md',
        public string $locale = 'en',
        public string $template = 'keep-a-changelog',
        public string $baseRef = 'HEAD',
        public string $tagPrefix = 'v',
        public ?string $repository = null,
        public string $source = 'auto',
        public bool $resolvePullRequests = false,
    ) {}

    /**
     * Binds publication policy to trusted caller settings without persisting JSON in the repository.
     *
     * @return string SHA-256 fingerprint of the trusted settings that affect publication reconstruction.
     */
    public function evidenceHash(): string
    {
        $settings = [
            'repository' => $this->repository, 'changelog_file' => $this->changelogFile,
            'fragment_directory' => $this->fragmentDirectory, 'locale' => $this->locale,
            'template' => $this->template, 'tag_prefix' => $this->tagPrefix, 'source' => $this->source,
        ];

        // Keep already approved offline transactions byte-compatible with their original settings fingerprint.
        if ($this->resolvePullRequests) {
            $settings['resolve_pull_requests'] = true;
        }

        return hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
