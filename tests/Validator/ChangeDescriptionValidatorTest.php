<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Validator;

use FastForward\Changelog\Validator\ChangeDescriptionValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeDescriptionValidator::class)]
final class ChangeDescriptionValidatorTest extends TestCase
{
    /** Empty answers fail without normalization, I/O or accepting a default description. */
    #[DataProvider('blankAnswers')]
    public function testRejectsMissingOrBlankText(?string $answer): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(2);
        $this->expectExceptionMessage('The change description must contain meaningful text.');
        new ChangeDescriptionValidator()($answer);
    }

    /** Provides absent and whitespace-only answers without consulting host input. */
    public static function blankAnswers(): array
    {
        return [[null], [''], ['   '], ["\t\r\n"]];
    }

    /** Significant leading/trailing spaces and multiline Markdown remain byte-identical. */
    public function testPreservesExactMarkdown(): void
    {
        $answer = "  **Meaningful**  \r\n\r\n    code  \n";
        self::assertSame($answer, new ChangeDescriptionValidator()($answer));
    }
}
