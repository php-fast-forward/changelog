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

namespace FastForward\Changelog\Markdown;

use FastForward\Changelog\Markdown\Parser\TrackedFencedCodeContinueParser;
use FastForward\Changelog\Markdown\Parser\TrackedFencedCodeStartParser;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Reference\ReferenceParser;

/** Classifies a complete Markdown source while keeping its original bytes authoritative. */
final class SourceDocument
{
    /**
     * Original one-based source lines, including their line endings.
     *
     * @var array<int,string> Original one-based source lines, including their line endings.
     */
    private array $lines = [];

    /**
     * Original byte offsets keyed by one-based source line.
     *
     * @var array<int,int> Original byte offsets keyed by one-based source line.
     */
    private array $offsets = [];

    /**
     * Root lines eligible for domain headings and legacy framing.
     *
     * @var array<int,true> Root lines eligible for domain headings and legacy framing.
     */
    private array $structural = [];

    /**
     * Code and raw HTML source lines at any container depth.
     *
     * @var array<int,true> Code and raw HTML source lines at any container depth.
     */
    private array $opaque = [];

    /**
     * Fenced source lines whose payload tabs must never be expanded.
     *
     * @var array<int,true> Fenced source lines whose payload tabs must never be expanded.
     */
    private array $fenced = [];

    /**
     * Maximum structural indentation columns before literal indented-code payload.
     *
     * @var array<int,int> Maximum structural indentation columns before literal indented-code payload.
     */
    private array $indentation = [];

    /**
     * Root list-item source ranges.
     *
     * @var list<array{start:int,end:int,indent:int,padding:int,ordered:bool}> Root list-item source ranges.
     */
    private array $items = [];

    /**
     * Captures byte slices and projections from an already parsed document; no rendering or I/O occurs.
     *
     * @param string   $source   Original Markdown, retaining mixed line endings and literal tabs.
     * @param Document $document Privately owned dependency tree used only for classification.
     */
    private function __construct(
        string $source,
        private readonly Document $document,
    ) {
        $offset = 0;

        foreach (preg_split('/(?<=\n)|(?<=\r)(?!\n)/', $source, flags: PREG_SPLIT_NO_EMPTY) as $line) {
            $number = count($this->lines) + 1;
            $this->lines[$number] = $line;
            $this->offsets[$number] = $offset;
            $this->structural[$number] = true;
            $offset += strlen($line);
        }
        $markers = [];

        foreach ($document->iterator() as $node) {
            if (! $node instanceof AbstractBlock || $node instanceof Document) {
                continue;
            }
            $range = range($node->getStartLine(), $node->getEndLine());

            if ($node instanceof ListBlock || $node instanceof BlockQuote) {
                foreach ($range as $number) {
                    unset($this->structural[$number]);
                }
            }

            if ($node instanceof FencedCode || $node instanceof IndentedCode || $node instanceof HtmlBlock) {
                foreach ($range as $number) {
                    $this->opaque[$number] = true;
                    unset($this->structural[$number]);

                    if ($node instanceof FencedCode) {
                        $this->fenced[$number] = true;
                    }
                }
            }

            if ($node instanceof ListItem) {
                $data = $node->getListData();
                $base = $this->containerPadding($node);

                foreach ($range as $number) {
                    $this->indentation[$number] = max($this->indentation[$number] ?? 4, 4 + $base);
                }

                if ($node->parent()?->parent() instanceof Document) {
                    $this->items[] = [
                        'start' => $node->getStartLine(), 'end' => $node->getEndLine(),
                        'indent' => $data->markerOffset, 'padding' => $data->markerOffset + $data->padding,
                        'ordered' => ListBlock::TYPE_ORDERED === $data->type,
                    ];
                }
            }

            if ($node instanceof HtmlBlock && HtmlBlock::TYPE_2_COMMENT === $node->getType()
                && $node->parent() instanceof Document && $node->getStartLine() === $node->getEndLine()) {
                $markers[$node->getStartLine()] = true;
            }
        }

        foreach ($this->lines as $number => $line) {
            if (isset($markers[$number]) && 1 === preg_match(
                '/\A<!-- (?:\/?fast-forward-changelog:)[^\r\n]* -->[\r\n]*\z/',
                $line,
            )) {
                $this->structural[$number] = true;
            }
        }
    }

    /**
     * Parses CommonMark block ownership with tracked fence closure, without HTML conversion or external state.
     *
     * @param  string $source Complete original Markdown document or independently framed domain segment.
     * @return self   Source-backed classification whose public projections contain only scalar values.
     */
    public static function analyze(string $source): self
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addBlockStartParser(new TrackedFencedCodeStartParser(), 51);

        return new self($source, new MarkdownParser($environment)->parse($source));
    }

    /**
     * Returns source slices without normalizing line endings or whitespace.
     *
     * @return array<int,string> Original one-based lines, retaining every line-ending byte.
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * Locates an existing source line without converting byte positions to character columns.
     *
     * @param  int $line Existing one-based line number.
     * @return int Original zero-based byte offset.
     */
    public function offset(int $line): int
    {
        return $this->offsets[$line];
    }

    /**
     * Selects lines eligible for the changelog domain's root grammar.
     *
     * @return array<int,true> Root source lines outside code, HTML and nested containers, with reserved protocol markers restored.
     */
    public function structuralLines(): array
    {
        return $this->structural;
    }

    /**
     * Identifies literal block regions that cannot supply domain headings or definitions.
     *
     * @return array<int,true> Source lines owned by code or raw HTML, including their delimiters.
     */
    public function opaqueLines(): array
    {
        return $this->opaque;
    }

    /**
     * Identifies fenced payload regions whose whitespace remains literal.
     *
     * @return array<int,true> Fenced source lines requiring literal payload-byte preservation.
     */
    public function fencedLines(): array
    {
        return $this->fenced;
    }

    /**
     * Provides presentation indentation limits derived from actual list containers.
     *
     * @return array<int,int> Per-line structural indentation limits; absent lines have a four-column root limit.
     */
    public function indentationLimits(): array
    {
        return $this->indentation;
    }

    /**
     * Partitions a root authored list into its actual principal items.
     *
     * @return list<array{start:int,end:int,indent:int,padding:int,ordered:bool}> Root item ranges in source order; children remain within their owning item.
     */
    public function principalItems(): array
    {
        return $this->items;
    }

    /**
     * Chooses detached delivery metadata for an item beginning with literal code.
     *
     * @return bool Whether the first list item's first content block is literal indented code.
     */
    public function beginsWithIndentedCode(): bool
    {
        foreach ($this->document->iterator() as $node) {
            if ($node instanceof IndentedCode && 1 === $node->getStartLine()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distinguishes a standalone opening fence from fenced code nested inside a first-line quote.
     *
     * @return bool Whether the document's first root block is fenced code beginning on its first line.
     */
    public function beginsWithFencedCode(): bool
    {
        $first = $this->document->firstChild();

        return $first instanceof FencedCode && 1 === $first->getStartLine();
    }

    /**
     * Requires explicit fence closure for newly consolidated fragment content.
     *
     * @return bool Whether any real fenced block ended without an explicit delimiter in its original container.
     */
    public function hasUnclosedFence(): bool
    {
        foreach ($this->document->iterator() as $node) {
            if ($node instanceof FencedCode && ! $node->data->get(
                TrackedFencedCodeContinueParser::EXPLICIT_CLOSER,
                false,
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distinguishes an unfinished historical tail from a fence already ended by a later container boundary.
     *
     * @return bool Whether a real fence still owns the final nonblank source line without an explicit delimiter.
     */
    public function hasUnclosedTailFence(): bool
    {
        $last = 0;

        foreach ($this->lines as $number => $line) {
            if ('' !== trim($line)) {
                $last = $number;
            }
        }

        foreach ($this->document->iterator() as $node) {
            if ($node instanceof FencedCode && $node->getEndLine() >= $last
                && ! $node->data->get(TrackedFencedCodeContinueParser::EXPLICIT_CLOSER, false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Applies the strict reference-safety policy only inside upstream-classified paragraphs and HTML comments.
     *
     * Inline lookahead cannot cross a paragraph or sibling item. A real subsequent fence interrupts an
     * unmatched inline opener, leaving it literal; fenced and indented examples never enter this lexer.
     *
     * @return bool Whether a real comment or an unfinished paragraph comment could hide appended metadata.
     */
    public function hasUnclosedComment(): bool
    {
        foreach ($this->document->iterator() as $node) {
            if ($node instanceof HtmlBlock && HtmlBlock::TYPE_2_COMMENT === $node->getType()) {
                if ($this->unclosedComment($node->getLiteral())) {
                    return true;
                }
            } elseif ($node instanceof Paragraph) {
                $next = $node->getEndLine() + 1;

                if (! isset($this->fenced[$next]) && $this->unclosedComment(implode('', array_slice(
                    $this->lines, $node->getStartLine() - 1, $node->getEndLine() - $node->getStartLine() + 1,
                )))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Finds supported single-line definitions in upstream-validated reference prefixes and source gaps.
     *
     * Reference-only paragraphs are removed by CommonMark, so their offsets are reconstructed from original
     * lines. Ordinary paragraph continuations, code and HTML remain ineligible even when a label exists elsewhere.
     *
     * @return array<string,int> First original byte offset for each ASCII-normalized active definition label.
     */
    public function referenceOffsets(): array
    {
        $references = [];

        foreach ($this->referenceDefinitions() as $offset => $label) {
            $references[$label] ??= $offset;
        }

        return $references;
    }

    /**
     * Retains every active definition boundary, including duplicate labels needed for lossless footer extraction.
     *
     * @return array<int,true> Original byte positions of all supported active single-line definitions.
     */
    public function referencePositions(): array
    {
        return array_fill_keys(array_keys($this->referenceDefinitions()), true);
    }

    /**
     * Classifies supported definition source lines without losing duplicate positions or interpreting literal examples.
     *
     * @return array<int,string> Original byte position to ASCII-normalized label, in source order.
     */
    private function referenceDefinitions(): array
    {
        $blocked = $this->opaque;

        foreach ($this->document->iterator() as $node) {
            if (! $node instanceof Paragraph) {
                continue;
            }
            $prefix = true;

            foreach (range($node->getStartLine(), $node->getEndLine()) as $number) {
                $prefix = $prefix && null !== $this->referenceLabel($this->lines[$number]);

                if (! $prefix) {
                    $blocked[$number] = true;
                }
            }
        }
        $references = [];

        foreach ($this->lines as $number => $line) {
            $label = isset($blocked[$number]) ? null : $this->referenceLabel($line);

            if (null !== $label && $this->document->getReferenceMap()->contains($label)) {
                $references[$this->offsets[$number]] = strtolower(trim($label));
            }
        }

        return $references;
    }

    /**
     * Computes a nested item's complete content boundary from its classified ancestors.
     *
     * @param  ListItem $item Classified item.
     * @return int      Complete ancestral list-content column used only for presentation indentation.
     */
    private function containerPadding(ListItem $item): int
    {
        $padding = 0;

        for ($node = $item; null !== $node; $node = $node->parent()) {
            if ($node instanceof ListItem) {
                $data = $node->getListData();
                $padding += $data->markerOffset + $data->padding;
            }
        }

        return $padding;
    }

    /**
     * Validates only the supported single-line definition grammar through the upstream reference parser.
     *
     * @param  string      $line Original candidate definition line.
     * @return string|null Valid single-line label; multiline definitions remain outside the footer contract.
     */
    private function referenceLabel(string $line): ?string
    {
        if (1 !== preg_match('/\A {0,3}\[([^\]\r\n]+)\]:[ \t]*\S/', $line, $matches)) {
            return null;
        }
        $parser = new ReferenceParser();
        $parser->parse(rtrim($line, "\r\n"));

        foreach ($parser->getReferences() as $reference) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Checks comment delimiters and complete code spans within one already classified block only.
     *
     * @param  string $text Bounded original paragraph or HTML-comment text; block grammar is never inferred here.
     * @return bool   Whether an unescaped comment opener remains unmatched after complete inline spans are ignored.
     */
    private function unclosedComment(string $text): bool
    {
        $comment = false;
        preg_match_all('/`+|<!--|-->/', $text, $tokens, PREG_OFFSET_CAPTURE);
        $skip = 0;

        foreach ($tokens[0] as [$token, $offset]) {
            if ($offset < $skip) {
                continue;
            }

            if ($comment) {
                $comment = '-->' !== $token;

                continue;
            }
            $slashes = 0;

            for ($before = $offset - 1; $before >= 0 && '\\' === $text[$before]; --$before) {
                ++$slashes;
            }

            if (1 === $slashes % 2) {
                continue;
            }

            if ('<!--' === $token) {
                $comment = true;
            } elseif ('`' === $token[0] && 1 === preg_match(
                '/(?<!`)' . preg_quote($token, '/') . '(?!`)/', $text, $close, PREG_OFFSET_CAPTURE, $offset + strlen(
                    $token,
                ),
            )) {
                $skip = $close[0][1] + strlen($token);
            }
        }

        return $comment;
    }
}
