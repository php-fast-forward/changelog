<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Date;

use FastForward\Changelog\Date\ReleaseDateValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseDateValidator::class)]
final class ReleaseDateValidatorTest extends TestCase
{
    #[Test]
    public function validateAcceptsARealZeroPaddedCalendarDate(): void
    {
        (new ReleaseDateValidator())->validate('2024-02-29');

        self::addToAssertionCount(1);
    }

    #[Test]
    #[TestWith(['2026/09/05'])]
    #[TestWith(['2026-9-5'])]
    #[TestWith(['2026-02-29'])]
    #[TestWith(['2026-02-30'])]
    #[TestWith(['0000-01-01'])]
    public function validateRejectsMalformedOrImpossibleDates(string $date): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf(
            'Invalid release date "%s"; expected a real calendar date in YYYY-MM-DD format.',
            $date,
        ));

        (new ReleaseDateValidator())->validate($date);
    }
}
