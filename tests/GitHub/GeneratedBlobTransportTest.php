<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\GitHub;

use FastForward\Changelog\GitHub\GitHubClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(GitHubClient::class)]
final class GeneratedBlobTransportTest extends TestCase
{
    /** Native streamed base64 preserves binary bytes across chunk boundaries without leaking authentication. */
    #[TestWith([''])]
    #[TestWith(["binary\0\xff"])]
    #[TestWith(['chunks'])]
    public function testUploadConfirmsExactBlob(string $bytes): void
    {
        if ('chunks' === $bytes) {
            $bytes = str_repeat("a\0\xff", 20_000);
        }
        $sha = hash('sha1', 'blob ' . strlen($bytes) . "\0" . $bytes);
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (
            $bytes,
            $sha
        ): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.github.com/repos/owner/project/git/blobs', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame([
                'Authorization: Bearer synthetic-token'],
                $options['normalized_headers']['authorization'],
            );
            $body = '';
            while ('' !== ($chunk = $options['body'](8192))) {
                $body .= $chunk;
            }
            self::assertSame([
                'encoding' => 'base64', 'content' => base64_encode($bytes)],
                json_decode($body, true, 512, JSON_THROW_ON_ERROR),
            );

            return new MockResponse(json_encode(['sha' => $sha], JSON_THROW_ON_ERROR));
        });
        self::assertSame($sha, new GitHubClient($http, 'synthetic-token')->createBlob('owner/project', $bytes));
    }

    /** NativeHttpClient's eager request-body buffering is refused before request or body construction. */
    public function testNativeUploadBackendIsRejectedBeforeRequest(): void
    {
        $http = new NativeHttpClient(['on_progress' => static function (): never {
            self::fail('A refused native upload must never start a request.');
        }]);
        $this->expectExceptionMessage('NativeHttpClient buffers request bodies');
        new GitHubClient($http)->createBlob('owner/project', 'binary');
    }
    /** An unrelated returned object cannot become release evidence. */
    public function testUploadRejectsWrongObject(): void
    {
        $this->expectExceptionMessage('exact generated blob identity');
        new GitHubClient(new MockHttpClient(new MockResponse('{"sha":"wrong"}')))->createBlob('owner/project', 'bytes');
    }

    /** Invalid identities and oversized payloads fail before any network effect. */
    #[TestWith(['missing', false])]
    #[TestWith(['owner/..', false])]
    #[TestWith(['owner/project', true])]
    public function testUploadRejectsInvalidInput(string $repository, bool $oversized): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');
        $bytes = $oversized ? str_repeat('x', 67_108_865) : '';
        $this->expectException(RuntimeException::class);
        new GitHubClient($http)->createBlob($repository, $bytes);
    }

    /** Raw bytes are bounded by independent tree size and bound to the Git object, including empty blobs. */
    #[TestWith([''])]
    #[TestWith(["binary\0\xff"])]
    public function testRawDigest(string $bytes): void
    {
        $sha = hash('sha1', 'blob ' . strlen($bytes) . "\0" . $bytes);
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (
            $bytes,
            $sha
        ): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://api.github.com/repos/owner/project/git/blobs/' . $sha, $url);
            self::assertFalse($options['buffer']);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(['Accept: application/vnd.github.raw+json'], $options['normalized_headers']['accept']);

            return new MockResponse($bytes);
        });
        self::assertSame(
            hash('sha256', $bytes),
            new GitHubClient($http)->blobDigest('owner/project', $sha, strlen($bytes)),
        );
    }

    /** Invalid tree metadata is rejected before an HTTP request. */
    #[TestWith(['missing', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 0])]
    #[TestWith(['owner/..', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 0])]
    #[TestWith(['owner/project', 'main', 0])]
    #[TestWith(['owner/project', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', -1])]
    #[TestWith(['owner/project', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 67_108_865])]
    public function testReadRejectsInvalidMetadata(string $repository, string $sha, int $size): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');
        $this->expectException(RuntimeException::class);
        new GitHubClient($http)->blobDigest($repository, $sha, $size);
    }

    /** HTTP errors, truncated responses, identity mismatch and excess bytes never validate. */
    #[TestWith([404, 'abc', 3])]
    #[TestWith([200, 'ab', 3])]
    #[TestWith([200, 'bad', 3])]
    #[TestWith([200, 'abcd', 3])]
    public function testReadRejectsInvalidResponse(int $status, string $bytes, int $size): void
    {
        $sha = hash('sha1', "blob 3\0abc");
        $this->expectException(RuntimeException::class);
        new GitHubClient(new MockHttpClient(new MockResponse($bytes, ['http_code' => $status])))->blobDigest(
            'owner/project',
            $sha,
            $size,
        );
    }

    /** Transport diagnostics cannot expose response bodies or credentials. */
    public function testReadRedactsTransportFailure(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('synthetic-token private body');
        });
        $this->expectExceptionMessage('Generated blob transport failed; details were withheld.');
        new GitHubClient($http, 'synthetic-token')->blobDigest('owner/project', str_repeat('a', 40), 0);
    }
}
