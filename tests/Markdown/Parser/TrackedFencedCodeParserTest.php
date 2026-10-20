<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Markdown\Parser;

use FastForward\Changelog\Markdown\Parser\TrackedFencedCodeContinueParser;
use FastForward\Changelog\Markdown\Parser\TrackedFencedCodeStartParser;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Parser\MarkdownParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrackedFencedCodeStartParser::class)]
#[CoversClass(TrackedFencedCodeContinueParser::class)]
final class TrackedFencedCodeParserTest extends TestCase
{
    /** Closure evidence records an actual delimiter instead of accepting EOF or a containing item's boundary. */
    #[TestWith(["```php\ncode();\n```", true])]
    #[TestWith(["```php\ncode();", false])]
    #[TestWith(["- ```php\n  code();\n- Next.", false])]
    #[TestWith(["- Parent.\n  - ```php\n    code();\n    ```", true])]
    #[TestWith(["- > ~~~php\n  > code();\n  >~~~", true])]
    #[TestWith(["- > ~~~php\n  > code();\n- Next.", false])]
    #[TestWith(["```php\ncode();\n```\t", true])]
    public function testOnlyExplicitClosingDelimitersProduceClosureEvidence(string $source, bool $closed): void
    {
        $fences = $this->fences($source);

        self::assertCount(1, $fences);
        self::assertSame(
            $closed,
            $fences[0]->data->get(TrackedFencedCodeContinueParser::EXPLICIT_CLOSER, false),
        );
    }

    /** Raw HTML containing literal backticks never invokes a fenced-code parser. */
    public function testRawHtmlCannotManufactureAClosingRequirement(): void
    {
        self::assertSame([], $this->fences("- <pre>\n  ```\n  literal\n  </pre>"));
    }

    /** Separate blocks retain their own closure evidence rather than sharing state through a reusable parser. */
    public function testFenceClosureEvidenceIsLocalToItsCodeBlock(): void
    {
        $fences = $this->fences("```php\nfirst();\n```\n\n~~~php\nsecond();");

        self::assertCount(2, $fences);
        self::assertTrue($fences[0]->data->get(TrackedFencedCodeContinueParser::EXPLICIT_CLOSER, false));
        self::assertFalse($fences[1]->data->get(TrackedFencedCodeContinueParser::EXPLICIT_CLOSER, false));
        self::assertSame("first();\n", $fences[0]->getLiteral());
        self::assertSame("second();\n", $fences[1]->getLiteral());
    }

    /**
     * Builds an in-memory CommonMark environment using the tracked adapter ahead of its standard fence parser.
     *
     * @param string $source Authored Markdown inspected without filesystem or runtime state.
     *
     * @return list<FencedCode> Parsed code blocks in source order, retaining independent closure evidence.
     */
    private function fences(string $source): array
    {
        $environment = new Environment(['max_nesting_level' => 100]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addBlockStartParser(new TrackedFencedCodeStartParser(), 51);
        $fences = [];

        foreach (new MarkdownParser($environment)->parse($source)->iterator() as $node) {
            if ($node instanceof FencedCode) {
                $fences[] = $node;
            }
        }

        return $fences;
    }
}
