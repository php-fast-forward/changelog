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

namespace FastForward\Changelog\History;

use FastForward\Changelog\History\Factory\HistoryDocumentFactoryInterface;
use FastForward\Changelog\History\Factory\HistoryExceptionFactoryInterface;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\Template\TemplateInterface;
use JsonException;

/** Preserves Markdown history while localizing only recognized structural headings. */
final readonly class HistoryCodec implements HistoryCodecInterface
{
    private const string END_RELEASE = '<!-- fast-forward-changelog:end-release -->';

    /** Composes construction and diagnostic boundaries without performing I/O. */
    public function __construct(
        private HistoryDocumentFactoryInterface $documentFactory,
        private HistoryReleaseFactoryInterface $releaseFactory,
        private HistoryExceptionFactoryInterface $exceptionFactory,
    ) {}

    /**
     * Parses English, Brazilian Portuguese and semantically marked release sections.
     *
     * Fenced code MUST NOT create release boundaries. Unknown prose, nested
     * Markdown, references and footer boilerplate MUST remain byte-preserved.
     */
    public function parse(string $markdown): HistoryDocument
    {
        $sections = [];
        $referenceOffsets = [];
        $pending = null;
        $protected = false;
        $protectedEnd = null;
        $legacyClosed = false;
        $fence = null;
        $offset = 0;

        foreach ($this->lines($markdown) as $line) {
            $lineOffset = $offset;
            $offset += strlen($line);

            if ($protected) {
                if (null !== $protectedEnd) {
                    if ($lineOffset < $protectedEnd) {
                        continue;
                    }

                    if ($lineOffset !== $protectedEnd || self::END_RELEASE !== rtrim($line, "\r\n")) {
                        throw $this->exceptionFactory->invalid('A release body_length must end exactly at its closing delimiter.');
                    }

                    $sections[array_key_last($sections)]['body_end'] = $lineOffset;
                    $protected = false;
                    $protectedEnd = null;

                    continue;
                }

                if ($this->outsideFence($line, $fence) && self::END_RELEASE === rtrim($line, "\r\n")) {
                    $sections[array_key_last($sections)]['body_end'] = $lineOffset;
                    $protected = false;
                    $legacyClosed = true;
                }

                continue;
            }

            if (! $this->outsideFence($line, $fence)) {
                continue;
            }

            if ($legacyClosed && self::END_RELEASE === rtrim($line, "\r\n")) {
                throw $this->exceptionFactory->invalid('A legacy release has ambiguous closing delimiters; preserve its source before migration.');
            }

            if (1 === preg_match('/^<!-- fast-forward-changelog:release (.+) -->\r?\n?\z/', $line, $matches)) {
                if (null !== $pending) {
                    throw $this->exceptionFactory->invalid('A release marker must be followed by one level-two heading.');
                }

                $pending = ['offset' => $lineOffset, 'metadata' => $this->metadata($matches[1])];

                continue;
            }

            if (null !== $pending) {
                if ('' === trim($line)) {
                    continue;
                }

                if (! str_starts_with($line, '## ')) {
                    throw $this->exceptionFactory->invalid('A release marker must be followed by one level-two heading.');
                }

                $metadata = $pending['metadata'];
                $sections[] = [
                    'offset' => $pending['offset'], 'content' => $offset, 'body_end' => null,
                    'version' => $metadata['version'], 'date' => $metadata['date'], 'source' => $metadata['date_source'],
                ];
                $pending = null;
                $protected = true;
                if (null !== $metadata['body_length']) {
                    if ($metadata['body_length'] > strlen($markdown) - $offset) {
                        throw $this->exceptionFactory->invalid('A release body_length exceeds the available Markdown bytes.');
                    }

                    $protectedEnd = $offset + $metadata['body_length'];
                }

                continue;
            }

            if (1 === preg_match('/^\[[^\]\r\n]+\]:[ \t]+\S/', $line)) {
                $referenceOffsets[] = $lineOffset;
            }

            if (1 !== preg_match('~^##[ \t]+\[(?<version>[^\]\r\n]+)\](?:\([^\r\n]*\))?(?:[ \t]+-[ \t]+(?<date>\d{4}-\d{2}-\d{2}))?(?:[ \t]+\[(?:YANKED|REMOVIDO)\])?[ \t]*(?:\r?\n|\z)~u', $line, $matches)) {
                continue;
            }

            $version = in_array($matches['version'], ['Unreleased', 'unreleased', 'Não publicado', 'não publicado'], true)
                ? 'unreleased' : $matches['version'];
            $sections[] = [
                'offset' => $lineOffset, 'content' => $offset, 'body_end' => null,
                'version' => $version, 'date' => $matches['date'] ?? null, 'source' => null,
            ];
        }

        if (null !== $pending || $protected) {
            throw $this->exceptionFactory->invalid(null !== $protectedEnd
                ? 'A release body_length requires its closing delimiter at the exact declared byte offset.'
                : 'A marked release must have its heading and end-release delimiter.');
        }

        $lastContent = [] === $sections ? 0 : array_last($sections)['content'];
        $footerOffset = strlen($markdown);

        foreach ($referenceOffsets as $referenceOffset) {
            if ($referenceOffset >= $lastContent) {
                $footerOffset = $referenceOffset;

                break;
            }
        }

        $releases = [];

        foreach ($sections as $index => $section) {
            $nextOffset = $sections[$index + 1]['offset'] ?? $footerOffset;
            $bodyEnd = $section['body_end'] ?? $nextOffset;
            $releases[] = $this->releaseFactory->create(
                $section['version'],
                $section['date'],
                $section['source'],
                substr($markdown, $section['content'], $bodyEnd - $section['content']),
                substr($markdown, $section['offset'], $section['content'] - $section['offset']),
                substr($markdown, $bodyEnd, $nextOffset - $bodyEnd),
            );
        }

        $prefixEnd = $sections[0]['offset'] ?? $footerOffset;

        return $this->documentFactory->create($releases, substr($markdown, 0, $prefixEnd), substr($markdown, $footerOffset));
    }

    /**
     * Renders localized structure or preserves existing sections during collection.
     *
     * Semantically marked sections protect raw imported notes from accidental
     * parsing of version-like headings within their descriptions.
     */
    public function render(HistoryDocument $document, TemplateInterface $template, bool $preservePresentation = false): string
    {
        $releases = $document->getReleases();
        $existing = [] !== $releases && null !== $releases[0]->getHeading();
        $output = $preservePresentation && ('' !== $document->getPrefix() || $existing)
            ? $document->getPrefix() : $this->formatPrefix($document->getPrefix(), $template);

        foreach ($releases as $release) {
            if ($preservePresentation && null !== $release->getHeading()) {
                $output .= $release->getHeading() . $release->getBody() . $release->getEnding();

                continue;
            }

            $heading = 'unreleased' === $release->getVersion()
                ? $template->unreleasedHeading() : $template->releaseHeading($release->getVersion(), $release->getDate());

            if (null !== $release->getHeading() && 1 === preg_match('/[ \t]+(\[(?:YANKED|REMOVIDO)\])[ \t]*(?:\r?\n)?\z/', $release->getHeading(), $matches)) {
                $heading .= ' ' . $matches[1];
            }

            $body = $preservePresentation ? $release->getBody() : $this->formatBody($release->getBody(), $template);
            $this->assertClosedFences($body);
            if ('' !== $body && ! str_ends_with($body, "\n")) {
                $body .= "\n";
            }

            $metadata = ['version' => $release->getVersion(), 'date' => $release->getDate(),
                'date_source' => $release->getDateSource(), 'body_length' => strlen($body)];
            $output .= '<!-- fast-forward-changelog:release ' . json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR) . " -->\n" . $heading . "\n" . $body;

            $ending = preg_replace('/\A<!-- fast-forward-changelog:end-release -->\r?\n?/', '', $release->getEnding());
            $output .= self::END_RELEASE . "\n" . $ending;
        }

        return $output . $document->getReferences();
    }

    /** Returns the exact stored body of the requested release without outer structure. */
    public function notes(HistoryDocument $document, string $version): string
    {
        $release = $document->getRelease($version);

        if (null === $release) {
            throw $this->exceptionFactory->invalid('The requested release is absent from CHANGELOG.md: ' . $version);
        }

        return $release->getBody();
    }

    /**
     * Splits Markdown while retaining every original line-ending byte.
     *
     * @return list<string>
     */
    private function lines(string $markdown): array
    {
        return preg_split('/(?<=\n)/', $markdown, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Determines whether a line is outside a CommonMark-style fenced block.
     *
     * @param array{character:string,length:int}|null $fence active fence state
     */
    private function outsideFence(string $line, ?array &$fence): bool
    {
        if (null !== $fence) {
            $pattern = '/^ {0,3}' . preg_quote($fence['character'], '/') . '{' . $fence['length'] . ',}[ \t]*(?:\r?\n|\z)/';

            if (1 === preg_match($pattern, $line)) {
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

    /** Rejects open fences that would hide the structural end-of-release delimiter. */
    private function assertClosedFences(string $body): void
    {
        $fence = null;

        foreach ($this->lines($body) as $line) {
            $this->outsideFence($line, $fence);
        }

        if (null !== $fence) {
            throw $this->exceptionFactory->invalid('An unclosed code fence prevents safe historical reformatting.');
        }
    }

    /**
     * Decodes a typed release marker without inventing missing provenance.
     *
     * @return array{version:string,date:?string,date_source:?string,body_length:?int}
     */
    private function metadata(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->exceptionFactory->invalid('A release marker must contain valid JSON metadata.');
        }

        if (! is_array($data) || ! isset($data['version']) || ! is_string($data['version'])
            || (null !== ($data['date'] ?? null) && ! is_string($data['date']))
            || (null !== ($data['date_source'] ?? null) && ! is_string($data['date_source']))
            || (array_key_exists('body_length', $data) && (! is_int($data['body_length']) || $data['body_length'] < 0))
            || [] !== array_diff(array_keys($data), ['version', 'date', 'date_source', 'body_length'])
        ) {
            throw $this->exceptionFactory->invalid('A release marker must contain a version, nullable date metadata and an optional nonnegative integer body_length.');
        }

        return ['version' => $data['version'], 'date' => $data['date'] ?? null,
            'date_source' => $data['date_source'] ?? null, 'body_length' => $data['body_length'] ?? null];
    }

    /** Replaces known or marked introduction text while retaining unknown prose. */
    private function formatPrefix(string $prefix, TemplateInterface $template): string
    {
        $introduction = "<!-- fast-forward-changelog:introduction -->\n" . rtrim($template->introduction(), "\r\n") . "\n<!-- /fast-forward-changelog:introduction -->\n\n";

        if (1 === preg_match('/\A<!-- fast-forward-changelog:introduction -->\R.*?\R<!-- \/fast-forward-changelog:introduction -->\R*/s', $prefix, $matches)) {
            return $introduction . substr($prefix, strlen($matches[0]));
        }

        if (1 === preg_match('/\A# Changelog\R\R(?:All notable changes[^\r\n]+|Todas as mudanças[^\r\n]+)\R\R(?:The format[^\r\n]+\Rand this[^\r\n]+|O formato[^\r\n]+\Re o projeto[^\r\n]+)\R*/u', $prefix, $matches)) {
            return $introduction . substr($prefix, strlen($matches[0]));
        }

        return '' === trim($prefix) ? $introduction . $prefix : $prefix;
    }

    /** Localizes recognized categories while retaining all other Markdown byte content. */
    private function formatBody(string $body, TemplateInterface $template): string
    {
        $categories = [
            'Added' => 'added', 'Adicionado' => 'added', 'Changed' => 'changed', 'Modificado' => 'changed',
            'Deprecated' => 'deprecated', 'Obsoleto' => 'deprecated', 'Removed' => 'removed', 'Removido' => 'removed',
            'Fixed' => 'fixed', 'Corrigido' => 'fixed', 'Security' => 'security', 'Segurança' => 'security',
        ];
        $fence = null;
        $pending = null;
        $output = '';

        foreach ($this->lines($body) as $line) {
            if (null !== $pending && '' !== trim($line) && ! str_starts_with($line, '### ')) {
                throw $this->exceptionFactory->invalid('A category marker must be followed by one level-three heading.');
            }

            if (! $this->outsideFence($line, $fence)) {
                $output .= $line;

                continue;
            }

            if (1 === preg_match('/^<!-- fast-forward-changelog:category (added|changed|deprecated|removed|fixed|security) -->\r?\n?\z/', $line, $matches)) {
                $pending = $matches[1];
                $output .= $line;

                continue;
            }

            if (1 === preg_match('/^### ([^\r\n]+?)[ \t]*(?<newline>\r?\n)?\z/', $line, $matches)) {
                $category = $pending ?? $categories[$matches[1]] ?? null;

                if (null !== $category) {
                    $output .= null === $pending ? '<!-- fast-forward-changelog:category ' . $category . " -->\n" : '';
                    $output .= $template->categoryHeading($category) . ($matches['newline'] ?? '');
                    $pending = null;

                    continue;
                }
            }

            $output .= $line;
        }

        if (null !== $pending) {
            throw $this->exceptionFactory->invalid('A category marker must be followed by one level-three heading.');
        }

        return $output;
    }
}
