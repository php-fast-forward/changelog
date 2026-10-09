<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Normalizer;

use FastForward\Changelog\Console\Normalizer\LineAnswerNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LineAnswerNormalizer::class)]
final class LineAnswerNormalizerTest extends TestCase
{
    /** Captured Enter delimiters are removed once while significant Markdown bytes survive. */
    #[DataProvider('answers')]
    public function testOnlyRemovesTheTerminalDelimiter(?string $answer, ?string $expected): void
    {
        self::assertSame($expected, new LineAnswerNormalizer()($answer));
    }

    /** Exercises null, empty lines, both platform delimiters and preceding meaningful whitespace. */
    public static function answers(): array
    {
        return [
            [null, null],
            ['', ''],
            ["\n", ''],
            ["\r\n", ''],
            ["  **Markdown**  \n", '  **Markdown**  '],
            ["  **Markdown**  \r\n", '  **Markdown**  '],
            ["body\n\n", "body\n"],
            ["body\r\n\r\n", "body\r\n"],
            ['  No delimiter  ', '  No delimiter  '],
        ];
    }
}
