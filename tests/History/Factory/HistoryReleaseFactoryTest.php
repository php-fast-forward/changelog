<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\History\Factory;

use FastForward\Changelog\History\Factory\HistoryReleaseFactory;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\Validator\ReleaseDateValidatorInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HistoryReleaseFactory::class)]
#[UsesClass(HistoryRelease::class)]
final class HistoryReleaseFactoryTest extends TestCase
{
    #[Test]
    #[TestWith(['v1.2.3-rc.0+ci.4', '1.2.3-rc.0+ci.4', '2024-02-29'])]
    #[TestWith(['unreleased', 'unreleased', null])]
    public function createsStrictValuesWithoutInventingDates(string $input, string $expected, ?string $date): void
    {
        $dates = $this->createMock(ReleaseDateValidatorInterface::class);
        $dates->expects(null === $date ? self::never() : self::once())->method('validate')->with($date ?? 'unused');
        $release = new HistoryReleaseFactory($dates)->create($input, $date, 'tagger_date', 'body', 'heading', 'ending');
        self::assertSame($expected, $release->getVersion());
        self::assertSame($date, $release->getDate());
        self::assertSame('tagger_date', $release->getDateSource());
        self::assertSame('body', $release->getBody());
        self::assertSame('heading', $release->getHeading());
        self::assertSame('ending', $release->getEnding());
    }

    #[Test]
    #[TestWith(['latest'])]
    #[TestWith(['vv1.0.0'])]
    #[TestWith(['1.0.0-01'])]
    #[TestWith(["1.0.0\n"])]
    public function rejectsNonSemanticIdentities(string $version): void
    {
        $this->expectException(InvalidArgumentException::class);
        $dates = $this->createMock(ReleaseDateValidatorInterface::class);
        $dates->expects(self::never())->method('validate');
        new HistoryReleaseFactory($dates)->create($version);
    }

    #[Test]
    #[TestWith(['2026-02-30'])]
    #[TestWith(['2026-2-03'])]
    public function rejectsInvalidCalendarDates(string $date): void
    {
        $this->expectException(InvalidArgumentException::class);
        $dates = $this->createMock(ReleaseDateValidatorInterface::class);
        $dates->expects(self::once())->method('validate')->with($date)->willThrowException(new InvalidArgumentException('Invalid calendar date.'));
        new HistoryReleaseFactory($dates)->create('1.0.0', $date);
    }
}
