<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Version;

use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\Version\VersionBaselineResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/** Verifies maintained numeric version state without Git, network, filesystem or publication effects. */
#[CoversClass(VersionBaselineResolver::class)]
#[UsesClass(HistoryDocument::class)]
#[UsesClass(HistoryRelease::class)]
final class VersionBaselineResolverTest extends TestCase
{
    /** Stable sections can advance the baseline while unrelated pending/prerelease sections remain ignored. */
    #[DataProvider('baselines')]
    public function testSelectsGreatestStableIdentityWithoutChangingHistory(
        array $versions,
        string $tagVersion,
        string $expected,
    ): void {
        $releases = array_map(static fn(string $version): HistoryRelease => new HistoryRelease(
            $version,
            body: 'Preserved ' . $version,
        ), $versions);
        $history = new HistoryDocument($releases, 'Original prefix', 'Original references');

        self::assertSame($expected, new VersionBaselineResolver()->resolve($history, $tagVersion));
        self::assertSame($releases, $history->getReleases());
        self::assertSame('Original prefix', $history->getPrefix());
        self::assertSame('Original references', $history->getReferences());
    }

    /** Supplies absent tags/history, delayed publication, mixed order and arbitrary-width numeric boundaries. */
    public static function baselines(): iterable
    {
        yield 'empty' => [[], '0.0.0', '0.0.0'];
        yield 'tag only' => [[], '1.7.0', '1.7.0'];
        yield 'history ahead' => [['1.7.1', '1.7.0'], '1.7.0', '1.7.1'];
        yield 'multiple pending versions' => [['1.7.1', '1.7.3', '1.7.2'], '1.7.0', '1.7.3'];
        yield 'history only' => [['1.7.1'], '0.0.0', '1.7.1'];
        yield 'prefixed history' => [['v1.7.1', 'V1.7.2'], '1.7.0', '1.7.2'];
        yield 'zero history' => [['0.0.0'], '0.0.0', '0.0.0'];
        yield 'tag ahead' => [['1.7.1'], '1.8.0', '1.8.0'];
        yield 'nonstable sections' => [['unreleased', '99.0.0-rc.1', '1.7.1'], '1.7.0', '1.7.1'];
        yield 'minor numeric width' => [['1.10.0', '1.9.99'], '1.2.0', '1.10.0'];
        yield 'patch numeric order' => [['1.7.8', '1.7.9'], '1.7.7', '1.7.9'];
        yield 'huge major' => [['100000000000000000000.0.0'], '99999999999999999999.9.9', '100000000000000000000.0.0'];
        yield 'huge minor' => [['1.100000000000000000000.0'], '1.99999999999999999999.9', '1.100000000000000000000.0'];
        yield 'huge patch' => [['1.7.100000000000000000000'], '1.7.99999999999999999999', '1.7.100000000000000000000'];
        yield 'build metadata retained' => [['1.7.1+prepared'], '1.7.0', '1.7.1+prepared'];
        yield 'equal core deterministic tie' => [['1.7.1+z'], '1.7.1+a', '1.7.1+z'];
        yield 'equal identity' => [['1.7.1'], '1.7.1', '1.7.1'];
        yield 'older major' => [['0.99.99'], '1.0.0', '1.0.0'];
    }

    /** Invalid tag input never silently becomes an initial version or publication evidence. */
    #[TestWith([''])]
    #[TestWith(['01.0.0'])]
    #[TestWith(['1.7'])]
    #[TestWith(['v1.7.0'])]
    #[TestWith(['1.7.0-rc.1'])]
    #[TestWith(["1.7.0\n"])]
    public function testRejectsInvalidTagBaseline(string $version): void
    {
        $this->expectException(InvalidArgumentException::class);

        new VersionBaselineResolver()->resolve(new HistoryDocument(), $version);
    }
}
