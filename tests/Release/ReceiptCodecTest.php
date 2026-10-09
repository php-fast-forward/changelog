<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release;

use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\Factory\ReleaseReceiptFactoryInterface;
use FastForward\Changelog\Release\ReceiptCodec;
use FastForward\Changelog\Release\ReleaseReceipt;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(ReceiptCodec::class)]
#[UsesClass(ReleaseReceipt::class)]
final class ReceiptCodecTest extends TestCase
{
    use ReceiptFixtureTrait;

    /** Encoding MUST order fields and consumed map before computing one stable identity. */
    public function testCanonicalEncodingRoundTripsAndRetainsExactNotes(): void
    {
        $codec = $this->codec();
        $evidence = self::evidence();
        $encoded = $codec->encode($evidence);
        $reordered = array_reverse($evidence, true);
        $reordered['consumed'] = array_reverse($reordered['consumed'], true);
        self::assertSame($encoded, $codec->encode($reordered));
        $receipt = $codec->decode($encoded);
        self::assertSame(['id', ...array_keys($evidence)], array_keys($receipt->data));
        self::assertSame($evidence['notes'], $receipt->data['notes']);
        self::assertSame(
            hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            $receipt->data['id'],
        );
        self::assertSame("\n", substr($encoded, -1));
    }

    /** A maintenance receipt can have no Git base, release impact, previous document or consumed map. */
    public function testMaintenanceAndNestedConfiguredDirectoryAreAccepted(): void
    {
        $evidence = array_replace(self::evidence(), [
            'base_sha' => null, 'next_version' => null, 'impact' => null,
            'before_changelog_sha256' => null, 'consumed' => [], 'historical_versions' => [],
            'tag_prefix' => '', 'repository' => 'owner/name',
        ]);
        $codec = $this->codec();
        self::assertSame($evidence, array_diff_key($codec->decode($codec->encode($evidence))->data, ['id' => true]));
        $evidence['next_version'] = '1.0.1';
        $evidence['impact'] = 'patch';
        $evidence['base_sha'] = str_repeat('b', 64);
        $evidence['fragment_directory'] = 'docs/.changelog';
        $evidence['changelog_file'] = 'docs/CHANGELOG.md';
        $evidence['consumed'] = ['docs/.changelog/new-feature.md' => hash('sha256', 'body')];
        self::assertSame($evidence['consumed'], $codec->decode($codec->encode($evidence))->data['consumed']);
    }

    /** Existing stable tags can retain build metadata while newly resolved releases use their numeric version. */
    public function testStableBuildMetadataIsRetainedForCurrentAndHistoricalVersions(): void
    {
        $evidence = array_replace(
            self::evidence(),
            ['current_version' => '1.0.0+build.001',
                'historical_versions' => ['0.1.0+legacy.docs'],
            ]);
        $codec = $this->codec();
        $receipt = $codec->decode($codec->encode($evidence));
        self::assertSame('1.0.0+build.001', $receipt->data['current_version']);
        self::assertSame(['0.1.0+legacy.docs'], $receipt->data['historical_versions']);
        self::assertSame('1.0.1', $receipt->data['next_version']);
    }

    /** Duplicate keys including escaped spellings MUST never collapse silently in JSON decoding. */
    #[DataProvider('invalidDocuments')]
    public function testMalformedDocumentsAreRejected(string $document, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->codec()->decode($document);
    }

    /** Supplies malformed JSON and each invalid top-level container shape. */
    public static function invalidDocuments(): iterable
    {
        yield ['{', 'Invalid release receipt JSON'];
        yield ['null', 'must be an object'];
        yield ['[]', 'must be an object'];
        yield ['{"schema":1}', 'must be an object'];
        yield ['{"id":"x","schema":1,"schema":1}', 'Duplicate release receipt key: schema'];
        yield ['{"id":"x","schema":1,"sch\\u0065ma":1}', 'Duplicate release receipt key: schema'];
    }

    /** Each field MUST enforce its schema type, exact path policy and semantic consistency. */
    #[DataProvider('invalidEvidence')]
    public function testInvalidEvidenceCannotBeEncoded(array $changes, ?string $remove = null): void
    {
        $evidence = array_replace(self::evidence(), $changes);
        if (null !== $remove) {
            unset($evidence[$remove]);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->codec()->encode($evidence);
    }

    /** Covers unknown/missing fields, hashes, scalar settings, versions and fragment namespaces. */
    public static function invalidEvidence(): iterable
    {
        yield [['unknown' => true]];
        yield [[], 'notes'];
        yield [['schema' => '1']];
        yield [['base_sha' => 1]];
        yield [['base_sha' => 'abc']];
        yield [['current_version' => null]];
        yield [['current_version' => '01.0.0']];
        yield [['current_version' => '1.0.0+']];
        yield [['current_version' => '1.0.0+bad_meta']];
        yield [['historical_versions' => ['0.1.0-rc.1']]];
        yield [['next_version' => '1.0.1-beta']];
        yield [['impact' => 'huge']];
        yield [['impact' => null]];
        yield [['next_version' => null]];
        yield [['next_version' => null, 'impact' => null]];
        foreach (['/absolute', '-option', 'a/../b', 'a/./b', 'a//b', 'a\\b', 'C:relative', "nul\0path", ''] as $path) {
            yield [['fragment_directory' => $path]];
        }
        yield [['changelog_file' => 42]];
        yield [['changelog_file' => '.changelog']];
        yield [['changelog_file' => '.changelog/history.md']];
        yield [['locale' => 'fr']];
        yield [['template' => null]];
        yield [['template' => ' ']];
        yield [['template' => "nul\0template"]];
        yield [['tag_prefix' => null]];
        yield [['tag_prefix' => '-bad']];
        yield [['repository' => 1]];
        yield [['repository' => 'not-a-repository']];
        yield [['before_changelog_sha256' => 3]];
        yield [['after_changelog_sha256' => str_repeat('A', 64)]];
        yield [['notes_sha256' => 'bad']];
        yield [['changelog_contents' => null]];
        yield [['changelog_contents' => 'modified']];
        yield [['notes' => 12]];
        yield [['notes' => 'edited']];
        yield [['historical_versions' => null]];
        yield [['historical_versions' => ['key' => '0.1.0']]];
        yield [['historical_versions' => [1]]];
        yield [['historical_versions' => ['0.1.0', '0.1.0']]];
        yield [['consumed' => null]];
        yield [['consumed' => ['some-value']]];
        foreach ([
            '.changelog/nested/a.md',
            '.changelog/.hidden.md',
            '.changelog/AGENTS.md',
            '.changelog/../escape.md',
            '/outside/a.md',
            '.changelog/A.md',
            '.changelog/a',
            '.changelog\\a.md',
        ] as $path) {
            yield [['consumed' => [$path => str_repeat('a', 64)]]];
        }
        yield [['consumed' => [0 => str_repeat('a', 64)]]];
        yield [['consumed' => ['.changelog/a.md' => 1]]];
        yield [['consumed' => ['.changelog/a.md' => 'invalid']]];
    }

    /** Arbitrary Markdown and JSON examples in the snapshot/notes MUST not be mistaken for receipt object keys. */
    public function testQuotedMarkdownJsonAndUnicodeRemainExactInSnapshotAndNotes(): void
    {
        $evidence = self::evidence();
        $evidence['changelog_contents'] = "# Histórico\n\n<!-- fragment {\"id\":\"a.md\",\"category\":\"changed\"} -->\n\n- Preserve \"notes\": {\"id\":\"two\"} e barras \\\n";
        $evidence['after_changelog_sha256'] = hash('sha256', $evidence['changelog_contents']);
        $evidence['notes'] = "```json\n{\"id\":\"one\",\"notes\":\"\\\\\"}\n```\n  Quoted \"schema\": 1  \n";
        $evidence['notes_sha256'] = hash('sha256', $evidence['notes']);
        $codec = $this->codec();
        $receipt = $codec->decode($codec->encode($evidence));
        self::assertSame($evidence['changelog_contents'], $receipt->data['changelog_contents']);
        self::assertSame($evidence['notes'], $receipt->data['notes']);
    }

    /** A syntactically valid edited receipt MUST fail its content ID check. */
    public function testIdentityTamperingAndWrongIdTypesAreRejected(): void
    {
        $codec = $this->codec();
        $data = json_decode($codec->encode(self::evidence()), true, flags: JSON_THROW_ON_ERROR);
        foreach ([null, 12, 'invalid', str_repeat('f', 64)] as $id) {
            $data['id'] = $id;
            try {
                $codec->decode(json_encode($data, JSON_THROW_ON_ERROR));
                self::fail('Tampered identity must fail.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('id does not match', $error->getMessage());
            }
        }
    }

    /** Invalid JSON MUST retain its decoder failure for diagnosis. */
    public function testJsonErrorRetainsItsCause(): void
    {
        try {
            $this->codec()->decode('{');
            self::fail('Invalid JSON must fail.');
        } catch (InvalidArgumentException $error) {
            self::assertInstanceOf(JsonException::class, $error->getPrevious());
        }
    }

    /** Creates the real pure codec with mocked object and diagnostic construction. */
    private function codec(): ReceiptCodec
    {
        $receipts = $this->createStub(ReleaseReceiptFactoryInterface::class);
        $receipts->method('create')->willReturnCallback(
            static fn(array $data): ReleaseReceipt => new ReleaseReceipt($data),
        );
        $exceptions = $this->createStub(ReleaseExceptionFactoryInterface::class);
        $exceptions->method('invalid')->willReturnCallback(
            static fn(string $message, ?Throwable $previous = null): InvalidArgumentException => new InvalidArgumentException(
                $message,
                previous: $previous,
            ),
        );

        return new ReceiptCodec($receipts, $exceptions);
    }
}
