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

namespace FastForward\Changelog\Release;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\Template\TemplateInterface;

/** Consumes the legacy pending section when fragments establish an actual release. */
final readonly class ReleaseHistoryConsolidator implements ReleaseHistoryConsolidatorInterface
{
    /** Injects value construction; consolidation performs no I/O or clock access. */
    public function __construct(private HistoryReleaseFactoryInterface $releases) {}

    /** Preserves descriptions, fenced examples and prior releases while removing the pending heading. */
    public function promote(HistoryDocument $document, string $version, ?string $date, string $notes, TemplateInterface $template): HistoryDocument
    {
        $pending = $document->getRelease('unreleased');
        if (null !== $pending && '' !== trim($pending->getBody())) {
            $legacy = $this->sections($pending->getBody(), $template);
            $current = $this->sections($notes, $template);
            $notes = $this->join($legacy[''], $current['']);
            foreach (Category::cases() as $category) {
                $body = $this->join($legacy[$category->value] ?? '', $current[$category->value] ?? '', true);
                if ('' !== trim($body)) {
                    $notes = $this->join($notes, $template->categoryHeading($category->value) . "\n\n" . ltrim($body, "\r\n"));
                }
            }
        }
        $retained = array_values(array_filter($document->getReleases(), static fn(HistoryRelease $release): bool => 'unreleased' !== $release->getVersion()));
        array_unshift($retained, $this->releases->create($version, $date, 'release-plan', $notes));
        return $document->withReleases($retained);
    }

    /**
     * Separates recognized category structure without parsing or rewriting descriptions.
     * Fenced headings remain literal body bytes; unknown headings stay in their original section.
     * @return array<string,string> uncategorized prose plus canonical category bodies
     */
    private function sections(string $body, TemplateInterface $template): array
    {
        $headings = [];
        foreach (Category::cases() as $category) {
            $headings['### ' . $category->heading()] = $category->value;
            $headings[$template->categoryHeading($category->value)] = $category->value;
        }
        foreach (['Adicionado' => 'added', 'Modificado' => 'changed', 'Obsoleto' => 'deprecated', 'Removido' => 'removed', 'Corrigido' => 'fixed', 'Segurança' => 'security'] as $heading => $category) {
            $headings['### ' . $heading] ??= $category;
        }
        $sections = ['' => ''];
        $active = '';
        $fence = null;
        foreach (preg_split('/(?<=\n)/', $body) as $line) {
            $heading = rtrim($line, " \t\r\n");
            if ($this->outsideFence($line, $fence) && isset($headings[$heading])) {
                $active = $headings[$heading];
                $sections[$active] ??= '';
                continue;
            }
            $sections[$active] .= $line;
        }
        return $sections;
    }

    /** Joins body boundaries without stripping meaningful spaces; consecutive simple list entries stay compact. */
    private function join(string $left, string $right, bool $compactList = false): string
    {
        if ('' === trim($left)) {
            return $right;
        }
        if ('' === trim($right)) {
            return $left;
        }
        $left = rtrim($left, "\r\n");
        $right = ltrim($right, "\r\n");
        $separator = $compactList && 1 === preg_match('/(?:\A|\n)- [^\r\n]*\z/', $left)
            && str_starts_with($right, '- ') ? "\n" : "\n\n";
        return $left . $separator . $right;
    }

    /**
     * Distinguishes structural headings from backtick and tilde fenced examples.
     * @param array{character:string,length:int}|null $fence active fence state
     */
    private function outsideFence(string $line, ?array &$fence): bool
    {
        if (null !== $fence) {
            if (1 === preg_match('/^ {0,3}' . preg_quote($fence['character'], '/') . '{' . $fence['length'] . ',}[ \t]*(?:\r?\n|\z)/', $line)) {
                $fence = null;
            }
            return false;
        }
        if (1 === preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $matches)) {
            $fence = ['character' => $matches[1][0], 'length' => strlen($matches[1])];
            return false;
        }
        return true;
    }
}
