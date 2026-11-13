<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Normalizer;

use FastForward\Changelog\Console\Normalizer\LineAnswerNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\InvalidArgumentException;

#[CoversClass(LineAnswerNormalizer::class)]
final class LineAnswerNormalizerTest extends TestCase
{
    /** Captured Enter delimiters are removed once while significant Markdown bytes survive. */
    #[DataProvider('answers')]
    public function testOnlyRemovesTheTerminalDelimiter(?string $answer, ?string $expected): void
    {
        self::assertSame($expected, (new LineAnswerNormalizer())($answer));
    }

    /** Native question validation rejects blank answers before retrying without trimming valid Markdown. */
    public function testValidatorPreservesMarkdown(): void
    {
        self::assertSame('  **Markdown**  ', (new LineAnswerNormalizer())->validate('  **Markdown**  '));
    }

    /** Native question validation rejects absent or whitespace-only responses with the existing diagnostic. */
    #[DataProvider('blankAnswers')]
    public function testValidatorRejectsBlankAnswers(?string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The change description must contain meaningful text.');
        (new LineAnswerNormalizer())->validate($value);
    }

    /** Covers absent, empty and whitespace-only normalized terminal responses. */
    public static function blankAnswers(): array
    {
        return [[null], [''], [" \t\r\n"]];
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
