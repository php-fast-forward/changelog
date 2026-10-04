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

namespace FastForward\Changelog\Release\Factory;

use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use InvalidArgumentException;

/** Validates shared settings before adapters access the consumer repository. */
final readonly class ReleaseOptionsFactory implements ReleaseOptionsFactoryInterface
{
    private const array DEFAULTS = [
        'workingDirectory' => '.', 'fragmentDirectory' => '.changelog',
        'changelogFile' => 'CHANGELOG.md', 'locale' => 'en',
        'template' => 'keep-a-changelog', 'baseRef' => 'HEAD',
        'tagPrefix' => 'v', 'repository' => null, 'source' => 'auto',
    ];

    /** Injects the caller's deterministic path resolver, never the installation root. */
    public function __construct(private PackagePathResolverInterface $paths) {}

    /** Applies explicit values to defaults; paths to managed files MUST stay in the project. */
    public function create(array $values = []): ReleaseOptions
    {
        $unknown = array_diff(array_keys($values), array_keys(self::DEFAULTS));
        if ([] !== $unknown) {
            throw new InvalidArgumentException('Unknown release settings: ' . implode(', ', $unknown));
        }
        $settings = array_replace(self::DEFAULTS, $values);
        foreach ($settings as $name => $value) {
            if ('repository' === $name && null === $value) {
                continue;
            }
            if (! is_string($value) || str_contains($value, "\0") || ('' === trim($value) && 'tagPrefix' !== $name)) {
                throw new InvalidArgumentException('Setting ' . $name . ' must be a nonempty string.');
            }
        }
        foreach (['fragmentDirectory', 'changelogFile'] as $name) {
            $path = str_replace('\\', '/', $settings[$name]);
            if ($this->paths->isAbsolute($path) || [] !== array_intersect(explode('/', $path), ['', '.', '..'])
                || '.' === $path || str_starts_with($path, '-')) {
                throw new InvalidArgumentException('Setting ' . $name . ' must be a relative project path without traversal.');
            }
            $settings[$name] = $path;
        }
        $changelogNamespace = strtolower($settings['changelogFile']);
        $fragmentNamespace = strtolower($settings['fragmentDirectory']);
        if ($changelogNamespace === $fragmentNamespace
            || str_starts_with($changelogNamespace, $fragmentNamespace . '/')) {
            throw new InvalidArgumentException('The central changelog must be outside the fragment directory and its release journal.');
        }
        if (! in_array($settings['locale'], ['en', 'pt-BR'], true)) {
            throw new InvalidArgumentException('Locale must be en or pt-BR.');
        }
        if (! in_array($settings['source'], ['auto', 'github', 'tags'], true)) {
            throw new InvalidArgumentException('Source must be auto, github or tags.');
        }
        if ('keep-a-changelog' !== $settings['template']) {
            $template = str_replace('\\', '/', $settings['template']);
            if (! str_ends_with($template, '.php') || $this->paths->isAbsolute($template)
                || [] !== array_intersect(explode('/', $template), ['', '.', '..']) || str_starts_with($template, '-')) {
                throw new InvalidArgumentException('Template must be keep-a-changelog or a relative project PHP file.');
            }
            $settings['template'] = $template;
        }
        if (! $this->isValidTagPrefix($settings['tagPrefix'])) {
            throw new InvalidArgumentException('Tag prefix must use supported characters and form a valid Git tag reference.');
        }
        if (str_starts_with($settings['baseRef'], '-') || preg_match('/[\r\n]/', $settings['baseRef'])) {
            throw new InvalidArgumentException('Base reference must be a Git revision, not an option.');
        }
        if (null !== $settings['repository'] && 1 !== preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $settings['repository'])) {
            throw new InvalidArgumentException('Repository must use owner/name.');
        }
        $settings['workingDirectory'] = $this->paths->absolutePath($settings['workingDirectory']);
        return new ReleaseOptions(...$settings);
    }

    /**
     * Validates the supported prefix alphabet and the complete generated Git ref without processes or I/O.
     * A namespace component MUST NOT be empty, start with a dot or end with .lock; consecutive dots are forbidden.
     * The appended stable SemVer makes a trailing prefix dot or .lock valid in the final component.
     *
     * @see https://git-scm.com/docs/git-check-ref-format
     */
    private function isValidTagPrefix(string $prefix): bool
    {
        if ('' !== $prefix && 1 !== preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*$~D', $prefix)) {
            return false;
        }

        $reference = 'refs/tags/' . $prefix . '1.0.0';
        if (str_contains($reference, '..')) {
            return false;
        }

        return array_all(explode('/', $reference), static fn(string $component): bool => '' !== $component
            && ! str_starts_with($component, '.') && ! str_ends_with($component, '.lock'));
    }
}
