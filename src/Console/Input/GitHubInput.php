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

namespace FastForward\Changelog\Console\Input;

use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Attribute\Option;

/** Maps explicit automation flags without reading runner environment or credentials. */
final class GitHubInput
{
    #[MapInput]
    public ReleaseInput $release;

    #[Option(description: 'Contribution base revision.', shortcut: 'S')]
    public ?string $since = null;

    #[Option(description: 'Explicit trusted PR number.', shortcut: 'p')]
    public ?string $pullRequest = null;

    #[Option(description: 'Exclusive generated version branch.')]
    public ?string $managedBranch = null;

    #[Option(description: 'Trusted generated-commit Bot.')]
    public ?string $automationActor = null;

    #[Option(description: 'Current human-granted contribution waiver label.')]
    public ?string $waiverLabel = null;

    #[Option(description: 'Current human-granted history-maintenance label.')]
    public ?string $maintenanceLabel = null;

    #[Option(description: 'Exact signed PR head.')]
    public ?string $expectedHeadSha = null;

    #[Option(description: 'Comma-separated dependency names.')]
    public ?string $dependencyNames = null;

    #[Option(description: 'Verified dependency metadata type.')]
    public ?string $dependencyType = null;

    #[Option(description: 'Verified dependency ecosystem.')]
    public ?string $ecosystem = null;

    #[Option(description: 'JSON array of verified security alert numbers.')]
    public ?string $securityAlertNumbers = null;

    #[Option(description: 'Include development dependencies: true or false.')]
    public ?string $includeDev = null;

    #[Option(description: 'Include Actions dependencies: true or false.')]
    public ?string $includeActions = null;

    #[Option(description: 'Trusted source release-line branch.')]
    public ?string $baseBranch = null;

    #[Option(description: 'Generated version PR title.')]
    public ?string $title = null;

    #[Option(description: 'Inspect without remote writes: true or false.')]
    public ?string $dryRun = null;

    #[Option(description: 'Exact approved publication commit.', shortcut: 't')]
    public ?string $targetSha = null;

    #[Option(description: 'History operation: backfill or format.')]
    public ?string $operation = null;

    #[Option(description: 'Report pending historical maintenance: true or false.')]
    public ?string $check = null;

    /** Initializes shared value settings only; no host state or configuration is loaded. */
    public function __construct()
    {
        $this->release = new ReleaseInput();
    }

    /** Omits unspecified operation flags so the shared runner can validate its exact allowed input surface. */
    public function values(): array
    {
        $settings = ['working-directory' => $this->release->workingDirectory,
            'fragment-directory' => $this->release->fragmentDirectory, 'changelog-file' => $this->release->changelogFile,
            'locale' => $this->release->locale, 'template' => $this->release->template, 'base-ref' => $this->release->baseRef,
            'tag-prefix' => $this->release->tagPrefix, 'source' => $this->release->source];
        if (null !== $this->release->repository) {
            $settings['repository'] = $this->release->repository;
        }
        foreach (get_object_vars($this) as $name => $value) {
            if ('release' !== $name && null !== $value) {
                $settings[strtolower(preg_replace('/[A-Z]/', '-$0', $name))] = $value;
            }
        }

        return $settings;
    }
}
