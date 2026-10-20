<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Markdown;

use FastForward\Changelog\Markdown\Parser\TrackedFencedCodeContinueParser;
use FastForward\Changelog\Markdown\Parser\TrackedFencedCodeStartParser;
use FastForward\Changelog\Markdown\SourceDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceDocument::class)]
#[UsesClass(TrackedFencedCodeStartParser::class)]
#[UsesClass(TrackedFencedCodeContinueParser::class)]
final class SourceDocumentTest extends TestCase
{
    /** Original byte slices retain mixed line endings, multibyte text and literal tabs without Markdown rendering. */
    public function testOriginalLinesAndByteOffsetsRetainTheirExactSource(): void
    {
        $source = "alpha\r\nβ\n\tomega\r";
        $document = SourceDocument::analyze($source);

        self::assertSame([1 => "alpha\r\n", 2 => "β\n", 3 => "\tomega\r"], $document->lines());
        self::assertSame([0, 7, 10], array_map($document->offset(...), [1, 2, 3]));

        foreach ($document->lines() as $number => $line) {
            self::assertSame($line, substr($source, $document->offset($number), strlen($line)));
        }

        self::assertSame($source, implode('', $document->lines()));
    }

    /** Sibling items retain independent optional indentation while a deeper child belongs to its owning item. */
    public function testPrincipalItemRangesDoNotDedentAnotherItemsContinuation(): void
    {
        $source = "  - First.\n- Second.\n  Continued.\n  - Child.\n    More.\n- Third.\n";

        self::assertSame([
            ['start' => 1, 'end' => 1, 'indent' => 2, 'padding' => 4, 'ordered' => false],
            ['start' => 2, 'end' => 5, 'indent' => 0, 'padding' => 2, 'ordered' => false],
            ['start' => 6, 'end' => 6, 'indent' => 0, 'padding' => 2, 'ordered' => false],
        ], SourceDocument::analyze($source)->principalItems());
    }

    /** An ordered item's content column includes its complete numeric marker and following padding. */
    public function testOrderedPrincipalItemsExposeTheirActualContentColumn(): void
    {
        self::assertSame([
            ['start' => 1, 'end' => 2, 'indent' => 0, 'padding' => 4, 'ordered' => true],
            ['start' => 3, 'end' => 3, 'indent' => 0, 'padding' => 4, 'ordered' => true],
        ], SourceDocument::analyze("12. First.\n    Continued.\n13. Second.\n")->principalItems());
    }

    /** Raw HTML owns apparent fences and release headings without creating code blocks or structural boundaries. */
    public function testRawHtmlDoesNotInventAFenceOrReleaseBoundary(): void
    {
        $source = "## [2.0.0]\n\n<pre>\n```\n## [9.9.9]\n</pre>\n\n## [1.0.0]\n";
        $document = SourceDocument::analyze($source);

        self::assertFalse($document->hasUnclosedFence());
        self::assertArrayHasKey(1, $document->structuralLines());
        self::assertArrayHasKey(8, $document->structuralLines());

        foreach ([3, 4, 5, 6] as $number) {
            self::assertArrayHasKey($number, $document->opaqueLines());
            self::assertArrayNotHasKey($number, $document->structuralLines());
        }

        self::assertSame($source, implode('', $document->lines()));
    }

    /** Delimiter-like text inside nested quoted code remains opaque at its original line positions. */
    public function testNestedQuotedCodeOwnsItsLiteralHtmlAndReferenceExamples(): void
    {
        $source = "- Parent.\n  - Child.\n\n    > ~~~html\n    > <!-- literal\n    > [guide]: https://example.test/literal\n    > ~~~\n";
        $document = SourceDocument::analyze($source);

        self::assertFalse($document->hasUnclosedFence());
        self::assertFalse($document->hasUnclosedComment());
        self::assertSame([], $document->referenceOffsets());

        foreach ([4, 5, 6, 7] as $number) {
            self::assertArrayHasKey($number, $document->opaqueLines());
            self::assertArrayNotHasKey($number, $document->structuralLines());
        }
    }

    /** Code presentation consumes only ancestor indentation; the remaining fenced recipe stays a literal source slice. */
    public function testNestedCodeExposesItsContainerBoundaryWithoutChangingPayload(): void
    {
        $source = "- Parent.\n  - Child.\n\n    ```make\n    all:\n    \tprintf 'hello'\n    ```\n";
        $document = SourceDocument::analyze($source);

        self::assertSame([4 => true, 5 => true, 6 => true, 7 => true], $document->fencedLines());
        self::assertSame(6, $document->indentationLimits()[1]);
        self::assertSame(8, $document->indentationLimits()[6]);
        self::assertSame("    \tprintf 'hello'\n", $document->lines()[6]);
        self::assertFalse($document->beginsWithIndentedCode());
    }

    /** A code-first item needs delivery metadata outside code; a later example does not change the first block's role. */
    #[TestWith(['-     printf hello', true])]
    #[TestWith(['12.     printf hello', true])]
    #[TestWith(["Description.\n\n    printf hello", false])]
    public function testOnlyTheFirstLiteralIndentedBlockControlsEntryPresentation(string $source, bool $codeFirst): void
    {
        self::assertSame($codeFirst, SourceDocument::analyze($source)->beginsWithIndentedCode());
    }

    /** A standalone opening fence needs the code wrapper while a first-line quote retains its own block structure. */
    #[TestWith(["```php\ncode();\n```", true])]
    #[TestWith(["> ```php\n> code();\n> ```", false])]
    #[TestWith(["- ```php\n  code();\n  ```", false])]
    public function testOnlyAnOpeningRootFenceSelectsTheStandaloneCodeWrapper(string $source, bool $fencedFirst): void
    {
        self::assertSame($fencedFirst, SourceDocument::analyze($source)->beginsWithFencedCode());
    }

    /** Historical formatting distinguishes a dangling final fence from a contained example already ended by a sibling. */
    #[TestWith(['', false])]
    #[TestWith(['Plain notes.', false])]
    #[TestWith(["```php\ncode();\n\n", true])]
    #[TestWith(["```php\ncode();\n```\n", false])]
    #[TestWith(["- ```php\n  code();\n- Next.\n", false])]
    public function testOnlyAnUnclosedFinalFencePreventsHistoricalFormatting(string $source, bool $unclosedTail): void
    {
        self::assertSame($unclosedTail, SourceDocument::analyze($source)->hasUnclosedTailFence());
    }

    /** Active reference prefixes keep original offsets while fenced, commented and inline-code examples cannot claim labels. */
    public function testReferenceOffsetsDistinguishDefinitionsFromLiteralExamples(): void
    {
        $source = "```md\r\n[literal]: https://example.test/code\r\n```\r\n\r\n"
            . "<!-- hidden\r\n[hidden]: https://example.test/comment\r\n-->\r\n\r\n"
            . "[Guide]: https://example.test/maintained \"Title\"\r\nFollowing prose.\r\n\r\n"
            . "- Use `literal\r\n  [inline]: https://example.test/span\r\n  code` safely.\r\n";

        self::assertSame(
            ['guide' => strpos($source, '[Guide]:')],
            SourceDocument::analyze($source)->referenceOffsets(),
        );
    }

    /** Duplicate labels preserve the first active definition's original byte position. */
    public function testReferenceOffsetsRetainTheFirstCaseInsensitiveDefinition(): void
    {
        $source = "# Notes\n\n[Guide]: https://example.test/first\n\n[guide]: https://example.test/second\n";

        self::assertSame(
            ['guide' => strpos($source, '[Guide]:')],
            SourceDocument::analyze($source)->referenceOffsets(),
        );
    }

    /** Link resolution keeps the first label while footer separation still recognizes a later duplicate definition. */
    public function testEveryDefinitionPositionSurvivesFirstLabelSelection(): void
    {
        $source = "- See [guide].\n\n  [Guide]: https://example.test/body\n\n[guide]: https://example.test/footer\n";
        $document = SourceDocument::analyze($source);
        $first = strpos($source, '  [Guide]:');
        $footer = strpos($source, '[guide]:');

        self::assertSame(['guide' => $first], $document->referenceOffsets());
        self::assertSame([$first => true, $footer => true], $document->referencePositions());
        self::assertSame($source, implode('', $document->lines()));
    }

    /** Reference-shaped prose with trailing junk cannot authorize a definition or occupy a maintained label. */
    public function testReferenceGrammarExcludesMalformedSingleLineCandidates(): void
    {
        $source = "[broken]: https://example.test trailing junk\n\n"
            . "[valid]: <https://example.test/maintained> \"A title\"\n";

        self::assertSame(
            ['valid' => strpos($source, '[valid]:')],
            SourceDocument::analyze($source)->referenceOffsets(),
        );
    }

    /** Root legacy single-line protocol comments remain recognizable without exposing ordinary HTML block content. */
    public function testLegacyProtocolMarkersRemainEligibleStructuralLines(): void
    {
        $source = "<!-- fast-forward-changelog:release {\"version\":\"1.0.0\"} -->\n"
            . "## [1.0.0]\n<!-- fast-forward-changelog:end-release -->\n";
        $document = SourceDocument::analyze($source);

        foreach ([1, 2, 3] as $number) {
            self::assertArrayHasKey($number, $document->structuralLines());
        }
    }

    /** A legacy-looking token remains literal when a real code or HTML container owns its source line. */
    #[TestWith(["```md\n<!-- fast-forward-changelog:end-release -->\n```\n", 2])]
    #[TestWith(["    <!-- fast-forward-changelog:end-release -->\n", 1])]
    #[TestWith(["<pre>\n<!-- fast-forward-changelog:end-release -->\n</pre>\n", 2])]
    public function testOpaqueExamplesCannotReactivateLegacyProtocolMarkers(string $source, int $markerLine): void
    {
        $document = SourceDocument::analyze($source);

        self::assertArrayHasKey($markerLine, $document->opaqueLines());
        self::assertArrayNotHasKey($markerLine, $document->structuralLines());
        self::assertSame($source, implode('', $document->lines()));
    }

    /** Inline backticks cannot cross item boundaries and conceal a different item's unfinished HTML comment. */
    #[TestWith(["- Use `code\n- literal <!-- hidden\n  ` token\n", true])]
    #[TestWith(["- Use `code\n- literal <!-- hidden\n  ` token\n  --> Visible.\n", false])]
    public function testInlineCommentSafetyIsBoundedByTheOwningParagraph(string $source, bool $unclosed): void
    {
        self::assertSame($unclosed, SourceDocument::analyze($source)->hasUnclosedComment());
    }

    /** A real fence interrupts a prior inline comment, whereas an HTML comment block owns apparent fence text. */
    #[TestWith(["- Visible <!-- hidden\n  ~~~\n  --> Visible.", true])]
    #[TestWith(["- <!-- hidden\n  ~~~\n  --> Visible.", false])]
    public function testBlockPrecedenceControlsFenceClosureRequirements(string $source, bool $unclosed): void
    {
        self::assertSame($unclosed, SourceDocument::analyze($source)->hasUnclosedFence());
    }

    /** Strict comment safety distinguishes real openers from escaped text and complete paragraph code spans. */
    #[TestWith(['Visible \\<!-- literal', false])]
    #[TestWith(['Visible \\\\<!-- hidden', true])]
    #[TestWith(['Use `<!-- literal` safely.', false])]
    #[TestWith(['Use `literal <!-- hidden', true])]
    #[TestWith(['<!-- closed -->', false])]
    #[TestWith(['<!-- hidden', true])]
    public function testCommentSafetyRespectsEscapesAndCompleteInlineCode(string $source, bool $unclosed): void
    {
        self::assertSame($unclosed, SourceDocument::analyze($source)->hasUnclosedComment());
    }
}
