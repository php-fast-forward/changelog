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

namespace FastForward\Changelog\Template;

use FastForward\Changelog\Configuration\Factory\ConfigSourceFactoryInterface;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Template\Factory\TemplateFactoryInterface;

/** Reads custom PHP only after explicit selection and the managed-path preflight. */
final readonly class TemplateResolver implements TemplateResolverInterface
{
    /**
     * Injects template construction, consumer paths and substitutable config/file boundaries.
     *
     * @param TemplateFactoryInterface         $templates  Constructs validated localized presentation from built-in defaults and explicit overrides.
     * @param PackagePathResolverInterface     $paths      Resolves explicit consumer paths without filesystem observation.
     * @param ManagedFileStoreInterface        $files      Reads and writes guarded managed bytes through an injected filesystem boundary.
     * @param ConfigSourceFactoryInterface     $sources    Constructs explicitly selected PHP configuration sources without implicit defaults files.
     * @param ReleaseExceptionFactoryInterface $exceptions Constructs controlled domain diagnostics without exposing underlying host details.
     */
    public function __construct(
        private TemplateFactoryInterface $templates,
        private PackagePathResolverInterface $paths,
        private ManagedFileStoreInterface $files,
        private ConfigSourceFactoryInterface $sources,
        private ReleaseExceptionFactoryInterface $exceptions,
    ) {}

    /**
     * Loads custom overrides only when requested; privileged callers MUST select a trusted base.
     *
     * @param ReleaseOptions $options Validated consumer paths, presentation settings and source-selection policy.
     *
     * @return TemplateInterface Validated presentation from built-in defaults and only explicitly selected trusted PHP overrides.
     *
     * @throws \InvalidArgumentException When the locale, selected template file or override structure is invalid.
     * @throws \RuntimeException         When guarded access to the selected managed path fails.
     */
    public function resolve(ReleaseOptions $options): TemplateInterface
    {
        if ('keep-a-changelog' === $options->template) {
            return $this->templates->create($options->locale);
        }
        $path = $this->paths->absolutePath($options->template, $options->workingDirectory);
        if (null === $this->files->read($path)) {
            throw $this->exceptions->invalid('The selected PHP template does not exist: ' . $options->template);
        }
        $overrides = $this->sources->create($path)->toArray();

        return $this->templates->create($options->locale, $overrides);
    }
}
