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

namespace FastForward\Changelog\Template\Factory;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Template\KeepAChangelogTemplate;
use FastForward\Changelog\Template\TemplateInterface;
use InvalidArgumentException;

/** Validates presentation settings before constructing a localized template. */
final class TemplateFactory implements TemplateFactoryInterface
{
    /**
     * Creates English or Brazilian Portuguese headings with explicit overrides.
     *
     * Unknown keys MUST fail early. Templates MUST retain level-two release
     * headings and level-three categories so semantic markers can round-trip.
     */
    public function create(string $locale = 'en', array $overrides = []): TemplateInterface
    {
        if (! in_array($locale, ['en', 'pt-BR'], true)) {
            throw new InvalidArgumentException('Supported locales are en and pt-BR.');
        }

        $portuguese = 'pt-BR' === $locale;
        $settings = [
            'introduction' => $portuguese
                ? "# Changelog\n\nTodas as mudanças relevantes deste projeto serão documentadas neste arquivo.\n\nO formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/)\ne o projeto segue [Versionamento Semântico](https://semver.org/lang/pt-BR/)."
                : "# Changelog\n\nAll notable changes to this project will be documented in this file.\n\nThe format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),\nand this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).",
            'release_heading' => '## [{version}]',
            'release_heading_dated' => '## [{version}] - {date}',
            'unreleased_heading' => $portuguese ? '## [Não publicado]' : '## [Unreleased]',
            'no_notes' => $portuguese ? 'Notas desta versão não estão disponíveis.' : 'Release notes are unavailable for this version.',
            'category_headings' => $portuguese
                ? ['added' => '### Adicionado', 'changed' => '### Modificado', 'deprecated' => '### Obsoleto', 'removed' => '### Removido', 'fixed' => '### Corrigido', 'security' => '### Segurança']
                : ['added' => '### Added', 'changed' => '### Changed', 'deprecated' => '### Deprecated', 'removed' => '### Removed', 'fixed' => '### Fixed', 'security' => '### Security'],
        ];

        foreach ($overrides as $key => $value) {
            if (! array_key_exists($key, $settings)) {
                throw new InvalidArgumentException('Unknown template setting: ' . $key);
            }

            if ('category_headings' === $key) {
                if (! is_array($value)) {
                    throw new InvalidArgumentException('category_headings must be an array.');
                }

                foreach ($value as $category => $heading) {
                    if (! is_string($category) || null === Category::tryFrom($category)) {
                        throw new InvalidArgumentException('Template categories must use canonical lowercase identifiers.');
                    }

                    $this->validateHeading($heading, '### ');
                    $settings[$key][$category] = $heading;
                }

                continue;
            }

            if (! is_string($value) || '' === trim($value)) {
                throw new InvalidArgumentException('Template text settings must contain strings.');
            }

            $settings[$key] = $value;
        }

        foreach (['release_heading', 'release_heading_dated', 'unreleased_heading'] as $key) {
            $this->validateHeading($settings[$key], '## ');
        }

        foreach (['release_heading', 'release_heading_dated'] as $key) {
            if (! str_contains($settings[$key], '{version}')) {
                throw new InvalidArgumentException('Release heading templates must contain {version}.');
            }
        }

        if (! str_contains($settings['release_heading_dated'], '{date}')) {
            throw new InvalidArgumentException('Dated release headings must contain {date}.');
        }

        return new KeepAChangelogTemplate($locale, $settings['introduction'], $settings['release_heading'], $settings['release_heading_dated'], $settings['category_headings'], $settings['unreleased_heading'], $settings['no_notes']);
    }

    /** Rejects multiline or structurally incompatible headings before construction. */
    private function validateHeading(mixed $heading, string $prefix): void
    {
        if (! is_string($heading) || ! str_starts_with($heading, $prefix)
            || '' === trim(substr($heading, strlen($prefix))) || 1 === preg_match('/[\r\n]/', $heading)
        ) {
            throw new InvalidArgumentException('Template headings must use the required Markdown level on one line.');
        }
    }
}
