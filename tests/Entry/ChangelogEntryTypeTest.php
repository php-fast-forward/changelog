<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @author   Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license  https://opensource.org/licenses/MIT MIT License
 *
 * @see      https://github.com/php-fast-forward/changelog
 * @see      https://github.com/php-fast-forward/changelog/issues
 * @see      https://php-fast-forward.github.io/changelog/
 * @see      https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Tests\Entry;

use FastForward\Changelog\Entry\ChangelogEntryType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangelogEntryType::class)]
final class ChangelogEntryTypeTest extends TestCase
{
    #[Test]
    public function orderedWillReturnKeepAChangelogSectionOrder(): void
    {
        self::assertSame(
            [
                ChangelogEntryType::Added,
                ChangelogEntryType::Changed,
                ChangelogEntryType::Deprecated,
                ChangelogEntryType::Removed,
                ChangelogEntryType::Fixed,
                ChangelogEntryType::Security,
            ],
            ChangelogEntryType::ordered(),
        );
    }

    #[Test]
    public function fromInputWillNormalizeSupportedValues(): void
    {
        self::assertSame(ChangelogEntryType::Fixed, ChangelogEntryType::fromInput(' fixed '));
        self::assertSame(ChangelogEntryType::Security, ChangelogEntryType::fromInput('security'));
    }

    #[Test]
    public function fromInputWillRejectUnsupportedValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported changelog type "unknown".');

        ChangelogEntryType::fromInput('unknown');
    }
}
