<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Markdown;

use FastForward\Changelog\Markdown\BlockStructure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlockStructure::class)]
final class BlockStructureTest extends TestCase
{
    /** Visual container columns include leading indentation and tab stops without changing authored bytes. */
    #[TestWith(['- Text', ['indent' => 0, 'contentIndent' => 2, 'ordered' => false]])]
    #[TestWith(['  - Text', ['indent' => 2, 'contentIndent' => 4, 'ordered' => false]])]
    #[TestWith(["-\tText", ['indent' => 0, 'contentIndent' => 4, 'ordered' => false]])]
    #[TestWith(['2. Text', ['indent' => 0, 'contentIndent' => 3, 'ordered' => true]])]
    #[TestWith(['-     code', ['indent' => 0, 'contentIndent' => 2, 'ordered' => false]])]
    #[TestWith(['-', ['indent' => 0, 'contentIndent' => 2, 'ordered' => false]])]
    #[TestWith(['- - -', null])]
    #[TestWith(['Text', null])]
    public function testListMarkerColumns(string $line, ?array $expected): void
    {
        self::assertSame($expected, BlockStructure::listItem($line));
    }

    /** Validation examines exactly the wrapper used for real rendering, including single-line fence openers. */
    #[TestWith(['- Text', '- Text'])]
    #[TestWith(["  - Parent.  \n    - Child.\n\n    Continuation.", "- Parent.  \n  - Child.\n\n  Continuation."])]
    #[TestWith(['Text', '- Text'])]
    #[TestWith(["Text\n\nNext.", "- Text\n\n  Next."])]
    #[TestWith(["Text\n\n\t<!-- literal", "- Text\n\n      <!-- literal"])]
    #[TestWith(["- Text\n\n  \t  code\tpayload", "- Text\n\n      code\tpayload"])]
    #[TestWith(["```php\ncode();\n```", "-\n  ```php\n  code();\n  ```"])]
    #[TestWith(["~~~php\ncode();\n~~~", "-\n  ~~~php\n  code();\n  ~~~"])]
    #[TestWith(["```literal``` span\nNext.", "- ```literal``` span\n  Next."])]
    public function testEffectiveWrapper(string $description, string $expected): void
    {
        self::assertSame($expected, BlockStructure::wrapDescription($description));
    }

    /** A continuation fence retains the complete list context through blanks and ends on a real dedent. */
    public function testContinuationFenceEndsWithItsContainer(): void
    {
        $fence = null;
        $containers = [];
        self::assertTrue(BlockStructure::outsideFence('- Parent.', $fence, $containers));
        self::assertTrue(BlockStructure::outsideFence('', $fence, $containers));
        self::assertFalse(BlockStructure::outsideFence('  ```md', $fence, $containers));
        self::assertSame(2, $fence['padding']);
        self::assertFalse(BlockStructure::outsideFence('', $fence, $containers));
        self::assertFalse(BlockStructure::outsideFence('  ## [9.9.9]', $fence, $containers));
        self::assertTrue(BlockStructure::outsideFence('## [1.0.0]', $fence, $containers));
        self::assertNull($fence);
        self::assertSame([], $containers);
    }

    /** Nested list context and leading indent participate in explicit closing-fence limits. */
    public function testNestedListFenceUsesItsOwnContentColumn(): void
    {
        $fence = null;
        $containers = [];
        BlockStructure::outsideFence('- Parent.', $fence, $containers);
        BlockStructure::outsideFence('  - Child.', $fence, $containers);
        self::assertFalse(BlockStructure::outsideFence('    ```php', $fence, $containers));
        self::assertSame(4, $fence['padding']);
        self::assertFalse(BlockStructure::outsideFence('        ```', $fence, $containers));
        self::assertNotNull($fence);
        self::assertFalse(BlockStructure::outsideFence('      ```', $fence, $containers));
        self::assertNull($fence);
        self::assertTrue(BlockStructure::outsideFence('  Parent continuation.', $fence, $containers));
        self::assertSame([2], $containers);
    }

    /** Same-line list containers contribute every content column before recognizing their nested fence. */
    public function testConsecutiveListMarkersCanOpenAFence(): void
    {
        $fence = null;
        $containers = [];
        self::assertFalse(BlockStructure::outsideFence('- - ```php', $fence, $containers));
        self::assertSame([2, 4], $containers);
        self::assertSame(4, $fence['padding']);
        self::assertFalse(BlockStructure::outsideFence('    code();', $fence, $containers));
        self::assertFalse(BlockStructure::outsideFence('    ```', $fence, $containers));
        self::assertNull($fence);
    }

    /** Only the fence's existing quote depth is structural; greater-than text inside its code stays literal. */
    public function testQuotedFenceRetainsItsOwnContainerDepth(): void
    {
        $fence = null;
        $containers = [];
        self::assertFalse(BlockStructure::outsideFence('> ~~~html', $fence, $containers));
        self::assertSame(1, $fence['quotes']);
        self::assertSame(2, $fence['padding']);
        self::assertFalse(BlockStructure::outsideFence('> > ~~~', $fence, $containers));
        self::assertNotNull($fence);
        self::assertFalse(BlockStructure::outsideFence('> <!-- literal', $fence, $containers));
        self::assertFalse(BlockStructure::outsideFence('> ~~~', $fence, $containers));
        self::assertNull($fence);
        self::assertTrue(BlockStructure::outsideFence('## [1.0.0]', $fence, $containers));
    }

    /** Historical quote termination stays readable; fragment validation separately requires an explicit closer. */
    public function testHistoricalQuotedFenceEndsWithItsQuoteContainer(): void
    {
        $fence = null;
        $containers = [];
        $implicit = false;
        BlockStructure::outsideFence('> ~~~md', $fence, $containers, $implicit);
        self::assertTrue(BlockStructure::outsideFence('## [1.0.0]', $fence, $containers, $implicit));
        self::assertNull($fence);
        self::assertTrue($implicit);
    }

    /** New fragments need explicit closers even when historical Markdown permits an implicit item boundary. */
    #[TestWith(["- ```text\n  example\n- Next.", true])]
    #[TestWith(["- ```text\n  example\n  ```\n- Next.", false])]
    #[TestWith(["- - ```text\n    example\n    ```", false])]
    #[TestWith(["```text\nexample", true])]
    #[TestWith(["Description.\n\n    ```literal", false])]
    #[TestWith(["- <!-- hidden\n  ~~~\n  --> Visible.", false])]
    #[TestWith(["> ~~~html\n> <!-- literal\n> ~~~", false])]
    #[TestWith(["> ~~~html\n> example\nOther paragraph.", true])]
    public function testNewFragmentFenceRequiresExplicitClosure(string $description, bool $expected): void
    {
        self::assertSame($expected, BlockStructure::hasUnclosedFence($description));
    }

    /** A root-level fence remains active across unindented headings until its actual closing delimiter. */
    public function testRootFenceDoesNotEndOnAnOrdinaryDedent(): void
    {
        $fence = null;
        $containers = [];
        self::assertFalse(BlockStructure::outsideFence('~~~md', $fence, $containers));
        self::assertFalse(BlockStructure::outsideFence('## [9.9.9]', $fence, $containers));
        self::assertFalse(BlockStructure::outsideFence('~~~', $fence, $containers));
        self::assertTrue(BlockStructure::outsideFence('## [1.0.0]', $fence, $containers));
    }

    /** Literal comment markers in code stay data; a real unclosed comment cannot hide appended references. */
    #[TestWith(['Text <!-- closed -->', false])]
    #[TestWith(['Text <!-- open', true])]
    #[TestWith(['Use `<!--` literally.', false])]
    #[TestWith(["Use `<!--\ncontinued` literally.", false])]
    #[TestWith(["~~~html\n<!-- literal\n~~~", false])]
    #[TestWith(["> ~~~html\n> <!-- literal\n> ~~~", false])]
    #[TestWith(["> > ~~~html\n> > <!-- literal\n> > ~~~", false])]
    #[TestWith(["> ~~~html\n> > ~~~\n> <!-- literal\n> ~~~", false])]
    #[TestWith(["Description.\n\n    <!-- literal", false])]
    #[TestWith(["- Description.\n\n      <!-- literal", false])]
    #[TestWith(["-     <!-- literal", false])]
    #[TestWith(["    Code.\n    <!-- literal", false])]
    #[TestWith(["Description.\n\n\t<!-- literal", false])]
    #[TestWith(["Description.\n    <!-- open", true])]
    #[TestWith(["Description.\n    - <!-- open", true])]
    #[TestWith(["    Code.\n<!-- open", true])]
    #[TestWith(["Text <!--\n~~~html\n--> Visible.", false])]
    #[TestWith(['\\<!-- literal', false])]
    #[TestWith(['\\\\<!-- open', true])]
    #[TestWith(['Text ` unmatched <!-- open', true])]
    #[TestWith(['<!-- ` --> ` Text <!-- open', true])]
    #[TestWith(['Text <!-- `literal` --> Visible.', false])]
    #[TestWith(["Text ` unmatched\n\n<!-- open", true])]
    #[TestWith(['-->', false])]
    public function testCommentSafety(string $description, bool $expected): void
    {
        self::assertSame($expected, BlockStructure::hasUnclosedComment($description));
    }

    /** Comment starts use content columns instead of confusing list/quote markers with code payload. */
    #[TestWith(['<!-- comment', [], true])]
    #[TestWith(['- <!-- comment', [2], true])]
    #[TestWith(['> <!-- comment', [], true])]
    #[TestWith(['      <!-- code', [2], false])]
    #[TestWith(['- \\<!-- literal', [2], false])]
    public function testCommentBlockStart(string $line, array $containers, bool $expected): void
    {
        self::assertSame($expected, BlockStructure::opensComment($line, $containers));
    }
}
