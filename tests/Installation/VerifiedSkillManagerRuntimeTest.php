<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Installation;

use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Installation\VerifiedSkillManagerRuntime;
use FastForward\Changelog\Process\Factory\ProcessFactoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Proves the executable trust boundary without network, native files or PHP subprocess execution. */
#[CoversClass(VerifiedSkillManagerRuntime::class)]
final class VerifiedSkillManagerRuntimeTest extends TestCase
{
    private const string BYTES = 'Trusted executable fixture bytes';
    private string $body = self::BYTES;
    private string $temporary = '/private/temp';
    private string $path = '/private/temp/manager-random.phar';
    private ?string $persisted = null;
    private bool $tamper = false;
    private int $progress = 32;
    private int $exit = 0;
    private array $events = [];

    /** Fixed bytes are checked before creation and again before PHP execution; cleanup always follows execution. */
    public function testVerifiedRuntimeExecutesItsOwnPharWithStructuredArguments(): void
    {
        $this->runtime()->run('/consumer', 'tools/vendor', ['add', 'php-fast-forward/changelog', '--no-interaction']);

        self::assertSame(['download', 'allocate', 'write', 'read', 'execute', 'remove'], $this->events);
        self::assertNull($this->persisted);
    }

    /** Neither a corrupt download nor an oversized response reaches temporary executable materialization. */
    #[DataProvider('invalidDownloads')]
    public function testUnverifiedDownloadCannotBeExecuted(string $kind): void
    {
        if ('digest' === $kind) {
            $this->body = 'Different bytes';
        } elseif ('body-size' === $kind) {
            $this->body = str_repeat('x', 2_000_001);
        } else {
            $this->progress = 2_000_001;
        }
        try {
            $this->runtime()->run('/consumer', 'vendor', ['update']);
            self::fail('Unverified executable must be rejected.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('runtime', $failure->getMessage());
        }
        self::assertSame(['download'], $this->events);
    }

    /** Covers the transport progress limit and the complete-response fallback size/integrity checks. */
    public static function invalidDownloads(): array
    {
        return [['digest'], ['body-size'], ['progress']];
    }

    /** Runtime storage cannot be relative or contained by the consumer's contribution-controlled tree. */
    #[DataProvider('invalidTemporaryRoots')]
    public function testUnsafeTemporaryRootBlocksBeforeDownload(string $directory): void
    {
        $this->temporary = $directory;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the consumer checkout');
        $this->runtime()->run('/consumer', 'vendor', ['update']);
    }

    /** Includes both equality and descent beneath the consumer root plus relative paths. */
    public static function invalidTemporaryRoots(): array
    {
        return [['/consumer'], ['/consumer/tmp'], ['relative']];
    }

    /** Unexpected allocator paths grant no ownership: they cannot be written, executed or removed. */
    public function testEscapedTemporaryFileCannotBeExecuted(): void
    {
        $this->path = '/consumer/manager.phar';
        try {
            $this->runtime()->run('/consumer', 'vendor', ['update']);
            self::fail('Escaped executable must be rejected.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('escaped', $failure->getMessage());
        }
        self::assertSame(['download', 'allocate'], $this->events);
    }

    /** Persisted byte substitution cannot reach PHP even after a correct download was observed. */
    public function testPersistedTamperingIsRejectedBeforeExecution(): void
    {
        $this->tamper = true;
        try {
            $this->runtime()->run('/consumer', 'vendor', ['update']);
            self::fail('Altered executable must be rejected.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('persisted', $failure->getMessage());
        }
        self::assertSame(['download', 'allocate', 'write', 'read', 'remove'], $this->events);
    }

    /** Failed execution retains consumer changes honestly and still removes only its temporary runtime. */
    public function testManagerFailureStillCleansTheTemporaryRuntime(): void
    {
        $this->exit = 9;
        try {
            $this->runtime()->run(
                '/consumer',
                'tools/vendor',
                ['add', 'php-fast-forward/changelog', '--no-interaction'],
            );
            self::fail('Failed manager must be reported.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('changes were retained', $failure->getMessage());
        }
        self::assertSame('remove', array_last($this->events));
    }

    /** Replaces each IO boundary; only the trusted composition digest is substituted for a small test vector. */
    private function runtime(): VerifiedSkillManagerRuntime
    {
        $http = $this->createStub(HttpClientInterface::class);
        $http->method('request')->willReturnCallback(
            function (string $method, string $url, array $options): ResponseInterface {
                self::assertSame('GET', $method);
                self::assertSame(VerifiedSkillManagerRuntime::URL, $url);
                self::assertSame(['timeout', 'max_duration', 'on_progress'], array_keys($options));
                self::assertSame(60, $options['max_duration']);
                $this->events[] = 'download';
                $options['on_progress']($this->progress);
                $response = $this->createStub(ResponseInterface::class);
                $response->method('getContent')->willReturn($this->body);

                return $response;
            },
        );
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('tempnam')->willReturnCallback(
            function (string $directory, string $prefix, string $suffix): string {
                self::assertSame('/private/temp', $directory);
                self::assertSame('.phar', $suffix);
                $this->events[] = 'allocate';

                return $this->path;
            },
        );
        $files = $this->createStub(ManagedFileStoreInterface::class);
        $files->method('write')->willReturnCallback(function (string $path, string $bytes): void {
            self::assertSame($this->path, $path);
            $this->events[] = 'write';
            $this->persisted = $bytes;
        });
        $files->method('read')->willReturnCallback(function (): ?string {
            $this->events[] = 'read';

            return $this->tamper ? 'Changed after write' : $this->persisted;
        });
        $files->method('remove')->willReturnCallback(function (string $path): void {
            self::assertSame($this->path, $path);
            $this->events[] = 'remove';
            $this->persisted = null;
        });
        $processes = $this->createStub(ProcessFactoryInterface::class);
        $processes->method('create')->willReturnCallback(function (array $command): Process {
            self::assertSame([
                PHP_BINARY, $this->path, 'add', 'php-fast-forward/changelog', '--no-interaction'],
                $command,
            );
            $process = $this->createMock(Process::class);
            $process->expects(self::once())->method('setWorkingDirectory')->with('/consumer');
            $process->method('getEnv')->willReturn(['GH_TOKEN' => false]);
            $process->expects(self::once())->method('setEnv')->with([
                'GH_TOKEN' => false, 'COMPOSER' => 'composer.json', 'COMPOSER_VENDOR_DIR' => 'tools/vendor',
            ]);
            $process->expects(self::once())->method('setTimeout')->with(300);
            $process->expects(self::once())->method('run')->willReturnCallback(function (): int {
                $this->events[] = 'execute';

                return $this->exit;
            });

            return $process;
        });

        return new VerifiedSkillManagerRuntime($http, $filesystem, $files, $processes, $this->temporary, hash(
            'sha256',
            self::BYTES,
        ));
    }
}
