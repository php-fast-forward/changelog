<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console;

use FastForward\Changelog\Console\GitHubOutputWriter;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(GitHubOutputWriter::class)]
final class GitHubOutputWriterTest extends TestCase
{
    /** Local CLI results remain exact JSON without observing or writing a runner file. */
    public function testStdoutOnlyPreservesLiteralJsonContent(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::never())->method('appendToFile');
        $result = ['status' => 'valid', 'version' => null, 'details' => ['text' => "<info>literal</info> café\nnext"]];
        self::assertSame(
            json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            new GitHubOutputWriter($filesystem)->write($result),
        );
    }

    /** One locked append preserves declared names, booleans, counts and the complete stable result. */
    public function testActionOutputsAreAppendedTogether(): void
    {
        $result = ['status' => 'updated', 'state' => null, 'version' => '1.2.3', 'pull_request' => 16,
            'url' => 'https://example.test/pr/16', 'head_sha' => 'head', 'plan_id' => 'plan',
            'fragments' => 2, 'maintenance' => false, 'path' => '.changelog/fix.md', 'tag' => 'v1.2.3', 'sha' => 'commit'];
        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())->method('appendToFile')->with(
            '/runner/output',
            'result=' . $json . "\nstatus=updated\nversion=1.2.3\npull-request=16\nurl=https://example.test/pr/16\nhead-sha=head\nplan-id=plan\nfragments=2\nmaintenance=false\npath=.changelog/fix.md\ntag=v1.2.3\nsha=commit\n",
            true,
        );
        self::assertSame($json, new GitHubOutputWriter($filesystem, '/runner/output')->write($result));
    }

    /** True and non-integral numeric outputs have deterministic scalar representations. */
    public function testAdditionalScalarTypesHaveStableRepresentations(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())->method('appendToFile')->with(
            '/runner/output',
            "result={\"maintenance\":true,\"fragments\":0.5}\nfragments=0.5\nmaintenance=true\n",
            true,
        );
        new GitHubOutputWriter($filesystem, '/runner/output')->write(['maintenance' => true, 'fragments' => 0.5]);
    }

    /** Scalar injection and complex output values fail before any partial file write. */
    #[DataProvider('unsafeValues')]
    public function testUnsafeScalarsCannotCreateForgedOutputs(mixed $value): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::never())->method('appendToFile');
        $this->expectException(InvalidArgumentException::class);
        new GitHubOutputWriter($filesystem, '/runner/output')->write(['status' => 'valid', 'url' => $value]);
    }

    /** Covers every forbidden line separator and a non-scalar known output. */
    public static function unsafeValues(): array
    {
        return [["url\nforged=value"], ["url\rforged=value"], ["url\0forged=value"], [['forged' => 'value']]];
    }

    /** A configured empty filename cannot fall through to a filesystem write. */
    public function testEmptyOutputPathIsRejected(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::never())->method('appendToFile');
        $this->expectException(InvalidArgumentException::class);
        new GitHubOutputWriter($filesystem, '')->write(['status' => 'valid']);
    }

    /** Invalid JSON content cannot produce partial GitHub outputs. */
    public function testInvalidUtf8FailsBeforeAppending(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::never())->method('appendToFile');
        $this->expectException(JsonException::class);
        new GitHubOutputWriter($filesystem, '/runner/output')->write(['status' => "\xff"]);
    }

    /** Failed output persistence propagates instead of reporting successful command completion. */
    public function testAppendFailureIsObservable(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())->method('appendToFile')->willThrowException(
            new RuntimeException('Output unavailable.'),
        );
        $this->expectException(RuntimeException::class);
        new GitHubOutputWriter($filesystem, '/runner/output')->write(['status' => 'valid']);
    }
}
