<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release;

use FastForward\Changelog\Release\Factory\ReleaseExceptionFactory;
use FastForward\Changelog\Release\ReleaseMetadataParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ReleaseMetadataParser::class)]
#[UsesClass(ReleaseExceptionFactory::class)]
final class ReleaseMetadataParserTest extends TestCase
{
    /** Both Git and immutable API messages preserve the original whole-line parsing contract. */
    public function testCompleteMetadataAndUnrelatedProse(): void
    {
        $parser = new ReleaseMetadataParser();
        self::assertNull($parser->parse('Ordinary squash message without transaction metadata.'));
        $message = "Subject\r\n\r\nChangelog-Base: " . str_repeat('a', 40)
            . "\r\nChangelog-Plan: " . str_repeat('b', 64)
            . "\r\nChangelog-Output: " . str_repeat('c', 64)
            . "\r\nChangelog-Options: " . str_repeat('d', 64) . "\r\n";
        self::assertSame([
            'base_sha' => str_repeat('a', 40), 'plan_id' => str_repeat('b', 64),
            'output_sha256' => str_repeat('c', 64), 'options_sha256' => str_repeat('d', 64),
        ], $parser->parse($message));
        self::assertSame(
            str_repeat('a', 64),
            $parser->parse(str_replace(str_repeat('a', 40), str_repeat('a', 64), $message))['base_sha'],
        );
    }

    /** Incomplete, malformed or duplicate metadata cannot become a fallback transaction. */
    #[DataProvider('invalidMessages')]
    public function testStrictFailures(string $message, string $diagnostic): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($diagnostic);
        new ReleaseMetadataParser()->parse($message);
    }

    /** Supplies independently invalid trailer shapes. */
    public static function invalidMessages(): iterable
    {
        yield ['Changelog-Base: ' . str_repeat('a', 40), 'incomplete'];
        yield ['Changelog-Plan: incomplete', 'malformed or duplicate'];
        yield ['Changelog-Base:' . str_repeat('a', 40), 'malformed or duplicate'];
        yield [
            "Changelog-Base: " . str_repeat('a', 40) . "\nChangelog-Base: " . str_repeat('a', 40),
            'malformed or duplicate',
        ];
        yield ['Changelog-Base: ' . str_repeat('A', 40), 'malformed or duplicate'];
        yield ['Changelog-Output: ' . str_repeat('a', 64) . ' trailing', 'malformed or duplicate'];
    }
}
