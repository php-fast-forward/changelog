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

use InvalidArgumentException;
use Symfony\Component\Console\Attribute\Option;

/** Maps the shared public settings without loading config or observing host state. */
final class ReleaseInput
{
    #[Option(description: 'Consumer project directory.', name: 'cwd', shortcut: 'C')]
    public string $workingDirectory = '.';

    #[Option(description: 'Project-relative fragment directory.')]
    public string $fragmentDirectory = '.changelog';

    #[Option(description: 'Project-relative central changelog file.')]
    public string $changelogFile = 'CHANGELOG.md';

    #[Option(description: 'Structural language: en or pt-BR.', shortcut: 'l')]
    public string $locale = 'en';

    #[Option(description: 'keep-a-changelog or an explicitly trusted project PHP template.', shortcut: 'T')]
    public string $template = 'keep-a-changelog';

    #[Option(description: 'Git revision used as the release baseline.', shortcut: 'b')]
    public string $baseRef = 'HEAD';

    #[Option(description: 'Prefix of stable release tags.')]
    public string $tagPrefix = 'v';

    #[Option(description: 'GitHub repository in owner/name form.', shortcut: 'r')]
    public ?string $repository = null;

    #[Option(description: 'Historical evidence source: auto, github or tags.', shortcut: 's')]
    public string $source = 'auto';

    #[Option(
        description: 'Prove missing PR links through GitHub: optional true or false, independently of the history source.',
    )]
    public bool|string $resolvePullRequests = false;

    /**
     * Returns exactly the settings accepted by the shared options factory.
     *
     * @return array<string,string|bool|null> Shared options-factory settings using their canonical camelCase keys.
     *
     * @throws InvalidArgumentException When the optional boolean flag receives a value other than true or false.
     */
    public function values(): array
    {
        $values = get_object_vars($this);
        $values['resolvePullRequests'] = match ($this->resolvePullRequests) {
            true, 'true' => true,
            false, 'false' => false,
            default => throw new InvalidArgumentException('Resolve pull requests must be true or false.'),
        };

        return $values;
    }
}
