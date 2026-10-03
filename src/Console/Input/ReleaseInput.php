<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 * @see       https://github.com/php-fast-forward/changelog
 */

namespace FastForward\Changelog\Console\Input;

use Symfony\Component\Console\Attribute\Option;

/** Maps the shared public settings without loading config or observing host state. */
final class ReleaseInput
{
    #[Option(description: 'Consumer project directory.', name: 'cwd')]
    public string $workingDirectory = '.';
    #[Option(description: 'Project-relative fragment directory.')]
    public string $fragmentDirectory = '.changelog';
    #[Option(description: 'Project-relative central changelog file.')]
    public string $changelogFile = 'CHANGELOG.md';
    #[Option(description: 'Structural language: en or pt-BR.')]
    public string $locale = 'en';
    #[Option(description: 'keep-a-changelog or an explicitly trusted project PHP template.')]
    public string $template = 'keep-a-changelog';
    #[Option(description: 'Git revision used as the release baseline.')]
    public string $baseRef = 'HEAD';
    #[Option(description: 'Prefix of stable release tags.')]
    public string $tagPrefix = 'v';
    #[Option(description: 'GitHub repository in owner/name form.')]
    public ?string $repository = null;
    #[Option(description: 'Historical evidence source: auto, github or tags.')]
    public string $source = 'auto';

    /** Returns exactly the settings accepted by the shared options factory. */
    public function values(): array
    {
        return get_object_vars($this);
    }
}
