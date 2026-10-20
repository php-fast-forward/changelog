<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Markdown;

use FastForward\Changelog\Markdown\BlockStructure;
use FastForward\Changelog\Markdown\Parser\TrackedFencedCodeContinueParser;
use FastForward\Changelog\Markdown\Parser\TrackedFencedCodeStartParser;
use FastForward\Changelog\Markdown\SourceDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlockStructure::class)]
#[UsesClass(SourceDocument::class)]
#[UsesClass(TrackedFencedCodeStartParser::class)]
#[UsesClass(TrackedFencedCodeContinueParser::class)]
final class BlockStructureTest extends TestCase
{
    /** Effective release layout must expose the next root heading while still permitting contained HTML. */
    #[TestWith(["- First.\n\n<pre>\nExample", true])]
    #[TestWith(["- First.\n\n<pre>\nExample\n</pre>", false])]
    #[TestWith(["- <pre>\n  Example", false])]
    public function testHtmlTailContainmentUsesTheEffectiveReleaseLayout(string $description, bool $unsafe): void
    {
        self::assertSame($unsafe, BlockStructure::hasUncontainedHtmlTail($description));
    }

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

    /** Only literal code after the complete same-line container prefix needs a detached reference paragraph. */
    #[TestWith(['-     printf hello', true])]
    #[TestWith(['1.     printf hello', true])]
    #[TestWith(['- -     printf hello', true])]
    #[TestWith(['- >     printf hello', true])]
    #[TestWith(["-\t\tprintf hello", true])]
    #[TestWith(['- Ordinary prose.', false])]
    #[TestWith(['Ordinary prose.', false])]
    public function testIndentedItemPayload(string $line, bool $expected): void
    {
        self::assertSame($expected, BlockStructure::startsIndentedItem($line));
    }

    /** Validation examines exactly the wrapper used for real rendering, including single-line fence openers. */
    #[TestWith(['- Text', '- Text'])]
    #[TestWith(["  - Parent.  \n    - Child.\n\n    Continuation.", "- Parent.  \n  - Child.\n\n  Continuation."])]
    #[TestWith(['Text', '- Text'])]
    #[TestWith(["Text\n\nNext.", "- Text\n\n  Next."])]
    #[TestWith(["Text\n\n\t<!-- literal", "- Text\n\n      <!-- literal"])]
    #[TestWith(["Text\n\n    all:\n    \tprintf", "- Text\n\n      all:\n      \tprintf"])]
    #[TestWith(["- Text\n\n    \tprintf", "- Text\n\n        printf"])]
    #[TestWith(["- Text\n\n  \t  code\tpayload", "- Text\n\n      code\tpayload"])]
    #[TestWith([
        "- See [guide].\n\n[guide]: https://example.test",
        "- See [guide].\n\n  [guide]: https://example.test",
    ])]
    #[TestWith([
        "1. See [guide].\n\n[guide]: https://example.test\n\n[other]: https://example.test/other\n",
        "1. See [guide].\n\n   [guide]: https://example.test\n\n   [other]: https://example.test/other\n",
    ])]
    #[TestWith([
        "- See [guide].\n\n[guide]: https://example.test\n\nFollowing prose.",
        "- See [guide].\n\n[guide]: https://example.test\n\nFollowing prose.",
    ])]
    #[TestWith(["```php\ncode();\n```", "-\n  ```php\n  code();\n  ```"])]
    #[TestWith(["~~~php\ncode();\n~~~", "-\n  ~~~php\n  code();\n  ~~~"])]
    #[TestWith(["```literal``` span\nNext.", "- ```literal``` span\n  Next."])]
    #[TestWith(["```make\nall:\n\tprintf 'hello'\n```", "-\n  ```make\n  all:\n  \tprintf 'hello'\n  ```"])]
    public function testEffectiveWrapper(string $description, string $expected): void
    {
        self::assertSame($expected, BlockStructure::wrapDescription($description));
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
    #[TestWith(["- Visible <!-- hidden\n  ~~~\n  --> Visible.", true])]
    #[TestWith(["- Visible <!-- literal\n  ~~~\n  --> Code example.\n  ~~~", false])]
    #[TestWith(["- Use `some\n  ~~~\n  code` safely.", true])]
    #[TestWith(["- Use `literal\n  ~~~\n  code` example.\n  ~~~", false])]
    public function testNewFragmentFenceRequiresExplicitClosure(string $description, bool $expected): void
    {
        self::assertSame($expected, BlockStructure::hasUnclosedFence($description));
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

}
