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

    /**
     * Composes construction and diagnostic boundaries without performing I/O.
     *
     * @param HistoryDocumentFactoryInterface  $documentFactory  Constructs ordered history with unique canonical release identities and unchanged surrounding bytes.
     * @param HistoryReleaseFactoryInterface   $releaseFactory   Constructs strict semantic sections with validated dates and preserved raw presentation.
     * @param HistoryExceptionFactoryInterface $exceptionFactory Constructs controlled validation diagnostics without performing the operation itself.
     */
    public function __construct(
        private HistoryDocumentFactoryInterface $documentFactory,
        private HistoryReleaseFactoryInterface $releaseFactory,
        private HistoryExceptionFactoryInterface $exceptionFactory,
    ) {}

    /**
     * Parses English, Brazilian Portuguese, Changesets and marked release sections.
     *
     * Fenced code MUST NOT create release boundaries. Unknown prose, nested
     * Markdown, references and footer boilerplate MUST remain byte-preserved.
     * Legacy boundaries require an Unreleased alias or a numeric version token;
     * bare Changesets version headings retain their original source bytes.
     * Recognized malformed headings MUST fail, with semantic validation owned
     * by the release factory rather than silently treating releases as prose.
     * Unrecognized level-two headings with numeric-dot-triple version shapes
     * MUST fail so changing templates cannot hide history from later imports.
     * Legacy reference footers require only definitions and blank lines through
     * EOF; following prose is ambiguous and MUST remain inside release notes.
     *
     * @param string                 $markdown Complete central-history Markdown, including its introduction and reference footer.
     * @param TemplateInterface|null $template Optional selected template used to recognize custom headings alongside built-in syntax.
     *
     * @return HistoryDocument Parsed history with lossless release bodies, introductory Markdown and reference footer.
     *
     * @throws \InvalidArgumentException When release grammar, legacy markers, semantic identities or dates are malformed or ambiguous.
     */
    public function parse(string $markdown, ?TemplateInterface $template = null): HistoryDocument
    {
        [$sections, $referenceOffsets] = $this->scanSections($markdown, $template);
        $lastContent = [] === $sections ? 0 : array_last($sections)['content'];
        $footerOffset = $this->referenceFooterOffset($markdown, $lastContent, $referenceOffsets);

        return $this->documentFromSections($markdown, $sections, $footerOffset);
    }

    /**
     * Scans release boundaries while retaining protected-body and code-fence state.
     *
     * @param string                 $markdown Complete history bytes, including original line endings.
     * @param TemplateInterface|null $template Selected heading grammar; null uses historical aliases.
     *
     * @return array{0:list<array<string,mixed>>,1:array<int,true>} Release offsets and terminal-reference candidates.
     */
    private function scanSections(string $markdown, ?TemplateInterface $template): array
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
                if ($this->closesProtectedBody($line, $lineOffset, $protectedEnd, $fence)) {
                    $sections[array_key_last($sections)]['body_end'] = $lineOffset;

                    if (null === $protectedEnd) {
                        $legacyClosed = true;
                    }

                    $protected = false;
                    $protectedEnd = null;
                }

                continue;
            }

            if (! $this->outsideFence($line, $fence)) {
                continue;
            }

            if ($legacyClosed && self::END_RELEASE === rtrim($line, "\r\n")) {
                throw $this->exceptionFactory->invalid(
                    'A legacy release has ambiguous closing delimiters; preserve its source before migration.',
                );
            }

            if (1 === preg_match('/^<!-- fast-forward-changelog:release (.+) -->\r?\n?\z/', $line, $matches)) {
                if (null !== $pending) {
                    throw $this->exceptionFactory->invalid(
                        'A release marker must be followed by one level-two heading.',
                    );
                }

                $pending = ['offset' => $lineOffset, 'metadata' => $this->metadata($matches[1])];

                continue;
            }

            if (null !== $pending) {
                if ('' === trim($line)) {
                    continue;
                }

                [$section, $protectedEnd] = $this->markedSection($pending, $line, $offset, strlen($markdown));
                $sections[] = $section;
                $pending = null;
                $protected = true;

                continue;
            }

            if (1 === preg_match('/^\[[^\]\r\n]+\]:[ \t]+\S/', $line)) {
                $referenceOffsets[$lineOffset] = true;
            }

            $heading = $this->plainHeading($line, $template);
            if (null !== $heading) {
                $sections[] = [
                    'offset' => $lineOffset, 'content' => $offset, 'body_end' => null,
                    'version' => $heading['version'], 'date' => $heading['date'], 'source' => null,
                ];
            }
        }

        if (null !== $pending || $protected) {
            throw $this->exceptionFactory->invalid(null !== $protectedEnd
                ? 'A release body_length requires its closing delimiter at the exact declared byte offset.'
                : 'A marked release must have its heading and end-release delimiter.');
        }

        return [$sections, $referenceOffsets];
    }

    /**
     * Recognizes a protected body's closing delimiter at its permitted boundary.
     *
     * @param string                                  $line        Current source line, including its original newline.
     * @param int                                     $offset      Byte offset at which the current line begins.
     * @param int|null                                $expectedEnd Exact declared boundary, or null for legacy delimiter scanning.
     * @param array{character:string,length:int}|null $fence       Mutable active fence state, updated by reference as source lines open or close code blocks.
     *
     * @return bool Whether this line closes the current protected body.
     */
    private function closesProtectedBody(string $line, int $offset, ?int $expectedEnd, ?array &$fence): bool
    {
        if (null !== $expectedEnd) {
            if ($offset < $expectedEnd) {
                return false;
            }

            if ($offset !== $expectedEnd || self::END_RELEASE !== rtrim($line, "\r\n")) {
                throw $this->exceptionFactory->invalid(
                    'A release body_length must end exactly at its closing delimiter.',
                );
            }

            return true;
        }

        return $this->outsideFence($line, $fence) && self::END_RELEASE === rtrim($line, "\r\n");
    }

    /**
     * Builds one marked section after validating its heading and declared body length.
     *
     * @param array{offset:int,metadata:array<string,mixed>} $pending        Marker offset and decoded metadata.
     * @param string                                         $line           First nonblank line after the marker.
     * @param int                                            $contentOffset  Byte offset immediately after the release heading.
     * @param int                                            $documentLength Total available Markdown byte length.
     *
     * @return array{0:array<string,mixed>,1:?int} Section offsets and its optional exact body boundary.
     */
    private function markedSection(array $pending, string $line, int $contentOffset, int $documentLength): array
    {
        if (! str_starts_with($line, '## ')) {
            throw $this->exceptionFactory->invalid(
                'A release marker must be followed by one level-two heading.',
            );
        }

        $metadata = $pending['metadata'];
        $section = [
            'offset' => $pending['offset'], 'content' => $contentOffset, 'body_end' => null,
            'version' => $metadata['version'], 'date' => $metadata['date'], 'source' => $metadata['date_source'],
        ];
        $bodyEnd = null;

        if (null !== $metadata['body_length']) {
            if ($metadata['body_length'] > $documentLength - $contentOffset) {
                throw $this->exceptionFactory->invalid(
                    'A release body_length exceeds the available Markdown bytes.',
                );
            }

            $bodyEnd = $contentOffset + $metadata['body_length'];
        }

        return [$section, $bodyEnd];
    }

    /**
     * Finds the terminal reference-only footer without moving following prose.
     *
     * @param string          $markdown         Complete original history bytes.
     * @param int             $lastContent      Byte offset after the last recognized release heading.
     * @param array<int,true> $referenceOffsets Reference-definition offsets observed outside protected regions.
     *
     * @return int First footer-definition offset, or the document length when none qualifies.
     */
    private function referenceFooterOffset(string $markdown, int $lastContent, array $referenceOffsets): int
    {
        $footerOffset = strlen($markdown);
        $tailOffset = $footerOffset;

        foreach (array_reverse($this->lines($markdown)) as $line) {
            $tailOffset -= strlen($line);
            if ($tailOffset < $lastContent
                || ('' !== trim($line, " \t\r\n") && ! isset($referenceOffsets[$tailOffset]))) {
                break;
            }

            if (isset($referenceOffsets[$tailOffset])) {
                $footerOffset = $tailOffset;
            }
        }

        return $footerOffset;
    }

    /**
     * Constructs releases from exact source slices and preserves the surrounding document.
     *
     * @param string                    $markdown     Complete original history bytes.
     * @param list<array<string,mixed>> $sections     Validated release headings and body offsets in source order.
     * @param int                       $footerOffset Start of the global reference footer.
     *
     * @return HistoryDocument Ordered releases, untouched prefix and raw footer.
     */
    private function documentFromSections(string $markdown, array $sections, int $footerOffset): HistoryDocument
    {
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

        return $this->documentFactory->create(
            $releases,
            substr($markdown, 0, $prefixEnd),
            substr($markdown, $footerOffset),
        );
    }

    /**
     * Renders localized structure or preserves existing sections during collection.
     *
     * Newly rendered sections contain ordinary Markdown only. Ambiguous unfenced
     * release headings or terminal reference definitions fail before any writes.
     * Incremental collection preserves stored sections, adding only boundary
     * whitespace around newly rendered releases so headings remain separate.
     *
     * @param HistoryDocument   $document             Maintained history whose release bodies, introduction and references must be preserved.
     * @param TemplateInterface $template             Validated structural presentation used to recognize or render history headings.
     * @param bool              $preservePresentation Whether incremental rendering must retain existing headings and framing.
     *
     * @return string Complete maintained Markdown with localized structure or exact retained presentation, including the original reference footer.
     *
     * @throws \InvalidArgumentException When an unfenced body boundary, dangling code fence or terminal reference definition prevents a safe round trip.
     */
    public function render(
        HistoryDocument $document,
        TemplateInterface $template,
        bool $preservePresentation = false,
    ): string {
        $releases = $document->getReleases();
        $existing = [] !== $releases && null !== $releases[0]->getHeading();
        $output = $preservePresentation && ('' !== $document->getPrefix() || $existing)
            ? $document->getPrefix() : $this->formatPrefix($document->getPrefix(), $template);

        foreach ($releases as $index => $release) {
            if ('' !== $output && (! $preservePresentation || null === $release->getHeading()
                || ($index > 0 && null === $releases[$index - 1]->getHeading()))
                && 1 !== preg_match('/(?:\r?\n){2}\z/', $output)) {
                $newline = str_ends_with($output, "\r\n") ? "\r\n" : "\n";
                $output .= (str_ends_with($output, "\n") ? '' : $newline) . $newline;
            }

            if ($preservePresentation && null !== $release->getHeading()) {
                $output .= $release->getHeading() . $release->getBody() . $release->getEnding();

                continue;
            }

            $heading = 'unreleased' === $release->getVersion()
                ? $template->unreleasedHeading() : $template->releaseHeading(
                    $release->getVersion(),
                    $release->getDate(),
                );

            if (null !== $release->getHeading() && 1 === preg_match(
                '/[ \t]+(\[(?:YANKED|REMOVIDO)\])[ \t]*(?:\r?\n)?\z/',
                $release->getHeading(),
                $matches,
            )) {
                $heading .= ' ' . $matches[1];
            }

            $body = $preservePresentation ? $release->getBody() : $this->formatBody($release->getBody(), $template);
            $this->assertPlainBody($body, $template);
            if ('' !== $body && ! str_ends_with($body, "\n")) {
                $body .= "\n";
            }

            $output .= $heading . "\n";
            if ('' !== $body && ! str_starts_with($body, "\n") && ! str_starts_with($body, "\r\n")) {
                $output .= "\n";
            }
            $output .= $body;
            $ending = preg_replace('/\A<!-- fast-forward-changelog:end-release -->\r?\n?/', '', $release->getEnding());
            $output .= $ending;
        }

        return $output . $document->getReferences();
    }

    /**
     * Returns the exact stored body of the requested release without outer structure.
     *
     * @param HistoryDocument $document Maintained history whose release bodies, introduction and references must be preserved.
     * @param string          $version  Canonical release identity to render or select; lookup accepts one conventional v prefix.
     *
     * @return string Exact selected release body without its outer heading or document reference footer.
     *
     * @throws \InvalidArgumentException When the requested release section is absent from the maintained history.
     */
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
     * @param string $markdown Complete central-history Markdown, including its introduction and reference footer.
     *
     * @return list<string> Source lines retaining their complete original newline bytes.
     */
    private function lines(string $markdown): array
    {
        return preg_split('/(?<=\n)/', $markdown, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Determines whether a line is outside a CommonMark-style fenced block.
     *
     * @param string                                  $line  Current source Markdown line, including its original line ending.
     * @param array{character:string,length:int}|null $fence Mutable active fence state, updated by reference as source lines open or close code blocks.
     *
     * @return bool Whether the current line is ordinary content outside a fence; delimiter lines return false and update the supplied state.
     */
    private function outsideFence(string $line, ?array &$fence): bool
    {
        if (null !== $fence) {
            $pattern = '/^ {0,3}' . preg_quote(
                $fence['character'],
                '/',
            ) . '{' . $fence['length'] . ',}[ \t]*(?:\r?\n|\z)/';

            if (1 === preg_match($pattern, $line)) {
                $fence = null;
            }

            return false;
        }

        if (1 === preg_match('/^ {0,3}(`{3,}|~{3,})(.*)/', $line, $matches)
            && ('~' === $matches[1][0] || ! str_contains($matches[2], '`'))) {
            $fence = ['character' => $matches[1][0], 'length' => strlen($matches[1])];

            return false;
        }

        return true;
    }

    /**
     * Recognizes standard, bare Changesets and explicitly selected template headings.
     *
     * Bare version-only headings delegate semantic validation to the release
     * factory; arbitrary labels or trailing prose remain outside that grammar.
     *
     * @param string                 $line     Current source Markdown line, including its original line ending.
     * @param TemplateInterface|null $template Optional selected template used to recognize custom headings alongside built-in syntax.
     *
     * @return array{version:string,date:?string}|null Recognized release identity and optional date, or null for ordinary non-release Markdown.
     */
    private function plainHeading(string $line, ?TemplateInterface $template): ?array
    {
        if (null !== $template) {
            if (rtrim($line, " \t\r\n") === $template->unreleasedHeading()) {
                return ['version' => 'unreleased', 'date' => null];
            }

            foreach ([true, false] as $dated) {
                $heading = preg_quote($template->releaseHeading('{version}', $dated ? '{date}' : null), '~');
                $pattern = $heading;
                foreach (['version' => '(?<version>[vV]?[0-9]+\.[^\s]*?)', 'date' => '(?<date>[^\s]+)'] as $name => $capture) {
                    $placeholder = preg_quote('{' . $name . '}', '~');
                    $position = strpos($pattern, $placeholder);
                    if (false === $position) {
                        continue;
                    }
                    $pattern = substr_replace($pattern, $capture, $position, strlen($placeholder));
                    $pattern = str_replace($placeholder, '\k<' . $name . '>', $pattern);
                }
                if (1 === preg_match(
                    '~^' . $pattern . '(?:[ \t]+\[(?:YANKED|REMOVIDO)\])?[ \t]*(?:\r?\n|\z)~u',
                    $line,
                    $matches,
                )) {
                    return ['version' => $matches['version'], 'date' => $matches['date'] ?? null];
                }
            }
        }

        if (1 === preg_match('~^##[ \t]+\[(?<version>[^\]\r\n]+)\]~u', $line, $matches)) {
            $unreleased = in_array(
                $matches['version'],
                ['Unreleased', 'unreleased', 'Não publicado', 'não publicado'],
                true,
            );
            if ($unreleased || 1 === preg_match('/\A[vV]?[0-9]+\.[^\s]*\z/u', $matches['version'])) {
                if (1 !== preg_match(
                    '~^##[ \t]+\[(?<version>[^\]\r\n]+)\](?:\([^\r\n]*\))?(?:[ \t]+-[ \t]+(?<date>\d{4}-\d{2}-\d{2}))?(?:[ \t]+\[(?:YANKED|REMOVIDO)\])?[ \t]*(?:\r?\n|\z)~u',
                    $line,
                    $matches,
                )) {
                    throw $this->exceptionFactory->invalid(
                        'A legacy release heading must use a bracketed version, optional link and optional ISO date.',
                    );
                }

                return ['version' => $unreleased ? 'unreleased' : $matches['version'], 'date' => $matches['date'] ?? null];
            }
        }

        $bareVersion = '[vV]?[0-9]+\.[0-9]+\.[0-9]+'
            . '(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?'
            . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?';
        if (1 === preg_match(
            '~^##[ \t]+(?<version>' . $bareVersion . ')[ \t]*(?:\r?\n|\z)~u',
            $line,
            $matches,
        )) {
            return ['version' => $matches['version'], 'date' => null];
        }

        if (1 === preg_match('/^##[ \t]+/', $line)
            && 1 === preg_match('/(?<![0-9])[0-9]++\.[0-9]++\.[0-9]++/u', $line)) {
            throw $this->exceptionFactory->invalid(
                'An unrecognized level-two heading resembles a release; keep the matching release template or migrate historical release headings before changing templates. For Markdown prose or examples, fence or nest this heading.',
            );
        }

        return null;
    }

    /**
     * Refuses Markdown that cannot retain exact release boundaries in a plain changelog.
     *
     * @param string            $body     Raw release body bytes, preserving Markdown whitespace and code fences.
     * @param TemplateInterface $template Validated structural presentation used to recognize or render history headings.
     *
     * @return void Completion only when the body cannot be mistaken for outer release boundaries or a terminal document footer.
     */
    private function assertPlainBody(string $body, TemplateInterface $template): void
    {
        $fence = null;
        $terminalReference = false;

        foreach ($this->lines($body) as $line) {
            if (! $this->outsideFence($line, $fence)) {
                $terminalReference = false;

                continue;
            }

            if (null !== $this->plainHeading($line, $template)
                || 1 === preg_match('/^<!-- fast-forward-changelog:(?:release |end-release)/', $line)) {
                throw $this->exceptionFactory->invalid(
                    'A plain changelog body cannot contain an unfenced release heading or legacy release delimiter; fence or nest this Markdown example.',
                );
            }

            if ('' !== trim($line)) {
                $terminalReference = 1 === preg_match('/^\[[^\]\r\n]+\]:[ \t]+\S/', $line);
            }
        }

        if (null !== $fence) {
            throw $this->exceptionFactory->invalid('An unclosed code fence prevents safe historical reformatting.');
        }
        if ($terminalReference) {
            throw $this->exceptionFactory->invalid(
                'A plain changelog body cannot end with an unindented reference definition that is ambiguous with the global footer; place it before following prose or inside a nested example.',
            );
        }
    }

    /**
     * Decodes a typed release marker without inventing missing provenance.
     *
     * @param string $json Complete JSON payload from one legacy release marker, interpreted only as typed metadata.
     *
     * @return array{version:string,date:?string,date_source:?string,body_length:?int} Typed legacy marker fields, retaining unknown dates and an optional exact protected-body byte length.
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
            throw $this->exceptionFactory->invalid(
                'A release marker must contain a version, nullable date metadata and an optional nonnegative integer body_length.',
            );
        }

        return ['version' => $data['version'], 'date' => $data['date'] ?? null,
            'date_source' => $data['date_source'] ?? null, 'body_length' => $data['body_length'] ?? null];
    }

    /**
     * Replaces known or marked introduction text while retaining unknown prose.
     *
     * @param string            $prefix   Original introductory Markdown, including recognized or legacy marked presentation.
     * @param TemplateInterface $template Validated structural presentation used to recognize or render history headings.
     *
     * @return string Localized recognized introduction with unknown surrounding prose retained; unrelated introductions remain untouched.
     */
    private function formatPrefix(string $prefix, TemplateInterface $template): string
    {
        $introduction = rtrim($template->introduction(), "\r\n") . "\n\n";

        if (1 === preg_match(
            '/\A<!-- fast-forward-changelog:introduction -->\R.*?\R<!-- \/fast-forward-changelog:introduction -->\R*/s',
            $prefix,
            $matches,
        )) {
            return $introduction . substr($prefix, strlen($matches[0]));
        }

        if (1 === preg_match(
            '/\A# Changelog\R\R(?:All notable changes[^\r\n]+|Todas as mudanças[^\r\n]+)\R\R(?:The format[^\r\n]+\Rand this[^\r\n]+|O formato[^\r\n]+\Re o projeto[^\r\n]+)\R*/u',
            $prefix,
            $matches,
        )) {
            return $introduction . substr($prefix, strlen($matches[0]));
        }

        return '' === trim($prefix) ? $introduction . $prefix : $prefix;
    }

    /**
     * Localizes recognized categories while retaining all other Markdown byte content.
     *
     * @param string            $body     Raw release body bytes, preserving Markdown whitespace and code fences.
     * @param TemplateInterface $template Validated structural presentation used to recognize or render history headings.
     *
     * @return string Body with recognized category headings localized and legacy technical markers removed, preserving all other Markdown bytes.
     */
    private function formatBody(string $body, TemplateInterface $template): string
    {
        $categories = [
            'Added' => 'added', 'Adicionado' => 'added', 'Changed' => 'changed', 'Modificado' => 'changed',
            'Deprecated' => 'deprecated', 'Obsoleto' => 'deprecated', 'Removed' => 'removed', 'Removido' => 'removed',
            'Fixed' => 'fixed', 'Corrigido' => 'fixed', 'Security' => 'security', 'Segurança' => 'security',
        ];
        foreach (array_unique($categories) as $category) {
            $categories[substr($template->categoryHeading($category), 4)] = $category;
        }
        $fence = null;
        $pending = null;
        $output = '';

        foreach ($this->lines($body) as $line) {
            if (null !== $pending && '' !== trim($line) && ! str_starts_with($line, '### ')) {
                throw $this->exceptionFactory->invalid(
                    'A category marker must be followed by one level-three heading.',
                );
            }

            if (! $this->outsideFence($line, $fence)) {
                $output .= $line;

                continue;
            }

            if (1 === preg_match(
                '/^<!-- fast-forward-changelog:category (added|changed|deprecated|removed|fixed|security) -->\r?\n?\z/',
                $line,
                $matches,
            )) {
                $pending = $matches[1];

                continue;
            }

            if (1 === preg_match('/^<!-- fast-forward-changelog:fragment \{.*\} -->\r?\n?\z/', $line)) {
                continue;
            }

            if (1 === preg_match('/^### ([^\r\n]+?)[ \t]*(?<newline>\r?\n)?\z/', $line, $matches)) {
                $category = $pending ?? $categories[$matches[1]] ?? null;

                if (null !== $category) {
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
