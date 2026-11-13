<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\GitHub;

use FastForward\Changelog\GitHub\GitHubClient;
use FastForward\Changelog\GitHub\GitHubFilteredTransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(GitHubClient::class)]
final class FilteredCommitPackTest extends TestCase
{
    /** Filtering is negotiated explicitly, has one immutable want, and keeps credentials out of the Git protocol. */
    public function testFilteredPackKeepsCompleteHistoryAndExistingBoundaries(): void
    {
        $sha = str_repeat('a', 40);
        $pack = 'PACK' . str_repeat('x', 28);
        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (
            &$calls,
            $sha,
            $pack
        ): MockResponse {
            $calls[] = [$method, $url];
            self::assertSame(0, $options['max_redirects']);
            self::assertSame([
                'Authorization: Basic ' . base64_encode('x-access-token:synthetic')],
                $options['normalized_headers']['authorization'],
            );
            if ('GET' === $method) {
                $body = "001e# service=git-upload-pack\n0000" . $sha . " HEAD\0filter no-progress\n";
                $type = 'advertisement';
            } else {
                $packet = static fn(string $data): string => sprintf('%04x', strlen($data) + 4) . $data;
                self::assertSame(
                    $packet('want ' . $sha . " filter\n") . $packet("filter blob:limit=1048576\n") . "00000009done\n",
                    $options['body'],
                );
                self::assertStringNotContainsString('synthetic', $options['body']);
                self::assertStringNotContainsString('deepen', $options['body']);
                $body = "0008NAK\n" . $pack;
                $type = 'result';
            }

            return new MockResponse($body, ['response_headers' => ['content-type' => 'application/x-git-upload-pack-' . $type]]);
        });
        $client = new GitHubClient($http, 'synthetic');
        self::assertInstanceOf(GitHubFilteredTransportInterface::class, $client);
        self::assertSame($pack, $client->filteredCommitPack('owner/project', $sha));
        self::assertCount(2, $calls);
    }

    /** An absent, empty or misleading capability cannot silently produce a different acquisition mode. */
    #[TestWith([''])]
    #[TestWith(["HEAD\0\n"])]
    #[TestWith(["HEAD\0filter-other no-progress\n"])]
    public function testMissingFilterCapabilityFailsBeforePackRequest(string $capabilities): void
    {
        $requests = 0;
        $http = new MockHttpClient(static function () use (&$requests, $capabilities): MockResponse {
            ++$requests;

            return new MockResponse("001e# service=git-upload-pack\n0000" . $capabilities, ['response_headers' => ['content-type' => 'application/x-git-upload-pack-advertisement']]);
        });
        try {
            (new GitHubClient($http))->filteredCommitPack('owner/project', str_repeat('a', 40));
            self::fail('An unsupported filter must be refused.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('did not advertise native pack filtering', $failure->getMessage());
            self::assertSame(1, $requests);
        }
    }
}
