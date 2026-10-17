<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Input;

use FastForward\Changelog\Console\Input\ReleaseInput;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseInput::class)]
final class ReleaseInputTest extends TestCase
{
    /** Shared CLI values keep offline defaults and normalize only the explicitly supported boolean spellings. */
    #[TestWith([false, false])]
    #[TestWith([true, true])]
    #[TestWith(['true', true])]
    #[TestWith(['false', false])]
    public function testExplicitReferenceFlagHasAnUnambiguousBooleanValue(bool|string $input, bool $expected): void
    {
        $values = new ReleaseInput();
        self::assertFalse($values->values()['resolvePullRequests']);
        $values->resolvePullRequests = $input;

        self::assertSame($expected, $values->values()['resolvePullRequests']);
    }

    /** A misspelled optional value must not silently enable network-backed consolidation. */
    public function testUnsupportedReferenceFlagFailsBeforeCreatingOptions(): void
    {
        $values = new ReleaseInput();
        $values->resolvePullRequests = 'yes';
        $this->expectException(InvalidArgumentException::class);

        $values->values();
    }
}
