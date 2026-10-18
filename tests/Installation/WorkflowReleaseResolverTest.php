<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Installation;

use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Installation\WorkflowReleaseResolver;
use FastForward\Changelog\Version\PackageVersionResolverInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(WorkflowReleaseResolver::class)]
final class WorkflowReleaseResolverTest extends TestCase
{
    private const string REPOSITORY = '/repos/php-fast-forward/changelog';
    private const string SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string CALLER_PATH = 'resources/workflows/changelog.yml';
    private const string WORKFLOW_PATH = '.github/workflows/changelog.yml';
    private const string CALLER = "name: Changelog\non:\n  push:\n    branches: [main]\njobs:\n  changelog:\n    uses: php-fast-forward/changelog/.github/workflows/changelog.yml@v0\n";
    private const string WORKFLOW = "on:\n  workflow_call:\n    inputs:\n      publish:\n        type: boolean\n        default: true\n      base-branch:\n        type: string\n        default: main\n      count:\n        type: number\n        default: 2\n";

    /** Stable runtime metadata selects the full published tag without consulting consumer package versions. */
    #[DataProvider('stableVersions')]
    public function testRuntimeStableVersionResolvesThePublishedImmutableContract(string $version): void
    {
        $result = $this->resolver($this->responses(), $version)->resolve();

        self::assertSame('v0.2.0', $result['ref']);
        self::assertSame(self::SHA, $result['sha']);
        self::assertSame(self::CALLER, $result['caller']);
        self::assertSame([
            'publish' => ['type' => 'boolean', 'default' => true],
            'base-branch' => ['type' => 'string', 'default' => 'main'],
            'count' => ['type' => 'number', 'default' => 2],
        ], $result['inputs']);
    }

    /** Composer metadata may contain the optional conventional v prefix. */
    public static function stableVersions(): array
    {
        return [['0.2.0'], ['v0.2.0']];
    }

    /** An explicit stable reference bypasses runtime-version inference and accepts wrapped API base64. */
    public function testExplicitTagUsesOnlyVerifiedCommitContentAndDecodesWrappedBase64(): void
    {
        $responses = $this->responses();
        $key = $this->contentPath(self::CALLER_PATH);
        $responses[$key]['content'] = chunk_split($responses[$key]['content'], 12, "\r\n");

        self::assertSame(self::CALLER, $this->resolver($responses)->resolve('0.2.0')['caller']);
    }

    /** Channels, development versions and malformed versions fail before any API request. */
    #[DataProvider('invalidReferences')]
    public function testUnpublishedReferenceShapesCannotBeUsedAsInstallationEvidence(string $reference): void
    {
        $resolver = $this->resolver([]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Select a published full stable tag');

        $resolver->resolve($reference);
    }

    /** Supplies unsupported version shapes, including whitespace and a path-shaped input. */
    public static function invalidReferences(): array
    {
        return [[''], ['dev-main'], ['main'], ['v0'], ['v0.2'], ['0.2.0-beta.1'], ['0.2.0+build'],
            ['00.2.0'], ['0.02.0'], ['0.2.00'], ['V0.2.0'], [' v0.2.0'], ["v0.2.0\n"], ['../../v0.2.0']];
    }

    /** Development runtime metadata requires an explicit stable reference rather than an implicit upgrade. */
    public function testDevelopmentRuntimeDoesNotDiscoverOrInstallTheLatestRelease(): void
    {
        $resolver = $this->resolver([], 'dev-main');
        $this->expectException(InvalidArgumentException::class);

        $resolver->resolve();
    }

    /** Only a published non-prerelease record for the selected exact tag authorizes tag lookup. */
    #[DataProvider('invalidReleases')]
    public function testIncompleteOrUnpublishedReleaseEvidenceStopsBeforeTagLookup(?array $release): void
    {
        $resolver = $this->resolver([self::REPOSITORY . '/releases/tags/v0.2.0' => $release]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no verified published stable GitHub Release');

        $resolver->resolve('v0.2.0');
    }

    /** Supplies absent, mismatched and insufficient release states without server-provided URLs. */
    public static function invalidReleases(): array
    {
        return [[null], [[]], [['tag_name' => 'v0.2.1', 'draft' => false, 'prerelease' => false]],
            [['tag_name' => 'v0.2.0', 'draft' => true, 'prerelease' => false]],
            [['tag_name' => 'v0.2.0', 'draft' => false, 'prerelease' => true]],
            [['tag_name' => 'v0.2.0', 'draft' => 'false', 'prerelease' => false]],
            [['tag_name' => 'v0.2.0', 'draft' => false]]];
    }

    /** The Git ref must identify the exact requested tag before any object or file is trusted. */
    #[DataProvider('invalidRefs')]
    public function testAbsentOrMismatchedGitRefIsRejected(?array $ref): void
    {
        $responses = array_slice($this->responses(), 0, 2, true);
        $responses[self::REPOSITORY . '/git/ref/tags/v0.2.0'] = $ref;
        $resolver = $this->resolver($responses);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('product tag identity');

        $resolver->resolve('v0.2.0');
    }

    /** Supplies null, missing and alternate ref identities. */
    public static function invalidRefs(): array
    {
        return [[null], [[]], [['ref' => 'refs/heads/v0.2.0']], [['ref' => 'refs/tags/v0.2.1']]];
    }

    /** Invalid immutable descriptors fail before a contents API request can use their value. */
    #[DataProvider('invalidObjects')]
    public function testInvalidOrNonCommitTagObjectsAreRejected(mixed $object, string $message): void
    {
        $responses = array_slice($this->responses(), 0, 2, true);
        $responses[self::REPOSITORY . '/git/ref/tags/v0.2.0']['object'] = $object;
        $resolver = $this->resolver($responses);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $resolver->resolve('v0.2.0');
    }

    /** Supplies malformed identities and unsupported Git object kinds. */
    public static function invalidObjects(): array
    {
        return [[null, 'invalid or cyclic'], ['server URL', 'invalid or cyclic'],
            [[], 'invalid or cyclic'], [['sha' => 123], 'invalid or cyclic'],
            [['sha' => str_repeat('A', 40)], 'invalid or cyclic'],
            [['sha' => str_repeat('a', 39)], 'invalid or cyclic'],
            [['sha' => self::SHA, 'type' => 'tree'], 'must identify a commit'],
            [['sha' => self::SHA, 'type' => 'blob'], 'must identify a commit'],
            [['sha' => self::SHA], 'must identify a commit']];
    }

    /** Seven annotations can be peeled while the eighth inspected object must be the final commit. */
    public function testMaximumSupportedAnnotatedTagChainResolvesItsCommit(): void
    {
        $responses = $this->annotatedResponses(7);

        self::assertSame(self::SHA, $this->resolver($responses)->resolve('v0.2.0')['sha']);
    }

    /** An eighth annotation cannot cause unbounded extra API traversal. */
    public function testAnnotatedTagDepthIsBounded(): void
    {
        $responses = array_slice($this->annotatedResponses(8), 0, 2, true)
            + array_slice($this->annotatedResponses(8), 4, null, true);
        $resolver = $this->resolver($responses);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('supported annotation depth');

        $resolver->resolve('v0.2.0');
    }

    /** A tag cycle stops on repeated immutable identity without re-requesting the repeated object. */
    public function testAnnotatedTagCycleIsRejected(): void
    {
        $responses = array_slice($this->annotatedResponses(2), 0, 2, true)
            + array_slice($this->annotatedResponses(2), 4, null, true);
        $first = str_repeat('1', 40);
        $second = str_repeat('2', 40);
        $responses[self::REPOSITORY . '/git/tags/' . $second]['object'] = ['type' => 'tag', 'sha' => $first];
        $resolver = $this->resolver($responses);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid or cyclic');

        $resolver->resolve('v0.2.0');
    }

    /** An annotated tag response must preserve the requested object's own SHA. */
    #[DataProvider('badAnnotatedTags')]
    public function testAnnotatedTagResponseIdentityCannotRedirectTheGraph(?array $reply): void
    {
        $responses = array_slice($this->annotatedResponses(1), 0, 2, true);
        $responses[self::REPOSITORY . '/git/tags/' . str_repeat('1', 40)] = $reply;
        $resolver = $this->resolver($responses);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different object identity');

        $resolver->resolve('v0.2.0');
    }

    /** Supplies absent and mismatched tag object responses. */
    public static function badAnnotatedTags(): array
    {
        return [[null], [[]], [['sha' => str_repeat('2', 40), 'object' => ['type' => 'commit', 'sha' => self::SHA]]]];
    }

    /** Missing or nonregular resources cannot enter YAML parsing. */
    #[DataProvider('badResourceMetadata')]
    public function testResourceMetadataMustDescribeOneBoundedRegularBlob(string $kind): void
    {
        $file = $this->blob(self::CALLER_PATH, self::CALLER);
        $file = match ($kind) {
            'absent' => null,
            'symlink', 'submodule', 'dir' => array_replace($file, ['type' => $kind]),
            'path' => array_replace($file, ['path' => 'untrusted.yml']),
            'encoding' => array_replace($file, ['encoding' => 'utf-8']),
            'content-type' => array_replace($file, ['content' => []]),
            'encoded-size' => array_replace($file, ['content' => str_repeat('A', 150_001)]),
        };
        $responses = array_slice($this->responses(), 0, 3, true);
        $responses[$this->contentPath(self::CALLER_PATH)] = $file;
        $resolver = $this->resolver($responses);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bounded regular workflow resource');

        $resolver->resolve('v0.2.0');
    }

    /** Supplies each metadata failure independently so every rejection preserves its boundary. */
    public static function badResourceMetadata(): array
    {
        return array_map(static fn(string $kind): array => [$kind], [
            'absent', 'symlink', 'submodule', 'dir', 'path', 'encoding', 'content-type', 'encoded-size',
        ]);
    }

    /** Encoded bytes require strict base64, a matching Git blob identity, size bounds and valid UTF-8. */
    #[DataProvider('badResourceBytes')]
    public function testResourceBytesMustMatchTheirImmutableBlob(string $kind): void
    {
        $file = match ($kind) {
            'base64' => array_replace($this->blob(self::CALLER_PATH, self::CALLER), ['content' => '%%%']),
            'hash' => array_replace($this->blob(self::CALLER_PATH, self::CALLER), ['sha' => str_repeat('b', 40)]),
            'decoded-size' => $this->blob(self::CALLER_PATH, str_repeat('a', 100_001)),
            'utf8' => $this->blob(self::CALLER_PATH, "\xFF"),
        };
        $responses = array_slice($this->responses(), 0, 3, true);
        $responses[$this->contentPath(self::CALLER_PATH)] = $file;
        $resolver = $this->resolver($responses);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable Git blob');

        $resolver->resolve('v0.2.0');
    }

    /** Supplies malformed base64, content identity mismatch, decoded overflow and invalid Unicode. */
    public static function badResourceBytes(): array
    {
        return [['base64'], ['hash'], ['decoded-size'], ['utf8']];
    }

    /** The exact supported decoded-size boundary remains usable rather than being rejected off by one. */
    public function testMaximumDecodedResourceSizeIsAccepted(): void
    {
        $caller = self::CALLER . '#' . str_repeat('a', 100_000 - strlen(self::CALLER) - 1);
        $responses = $this->responses();
        $responses[$this->contentPath(self::CALLER_PATH)] = $this->blob(self::CALLER_PATH, $caller);

        self::assertSame($caller, $this->resolver($responses)->resolve('v0.2.0')['caller']);
    }

    /** Only a caller for this product and a supported reusable input structure become installation data. */
    #[DataProvider('unsupportedContracts')]
    public function testUnsupportedCallerAndInputContractsFailClosed(
        string $caller,
        string $workflow,
        string $message,
    ): void {
        $responses = $this->responses();
        $responses[$this->contentPath(self::CALLER_PATH)] = $this->blob(self::CALLER_PATH, $caller);
        $responses[$this->contentPath(self::WORKFLOW_PATH)] = $this->blob(self::WORKFLOW_PATH, $workflow);
        $resolver = $this->resolver($responses);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $resolver->resolve('v0.2.0');
    }

    /** Supplies absent structures, foreign actions and malformed typed input records. */
    public static function unsupportedContracts(): array
    {
        return [[self::CALLER, '', 'supported lifecycle caller'],
            [self::CALLER, "on:\n  workflow_call:\n    inputs: scalar\n", 'supported lifecycle caller'],
            ['', self::WORKFLOW, 'supported lifecycle caller'],
            [
                str_replace('php-fast-forward/changelog/', 'other/project/', self::CALLER),
                self::WORKFLOW,
                'supported lifecycle caller',
            ],
            [
                str_replace("on:\n  push:\n    branches: [main]\n", "on: push\n", self::CALLER),
                self::WORKFLOW,
                'supported lifecycle caller',
            ],
            [
                str_replace(
                    'uses: php-fast-forward/changelog/.github/workflows/changelog.yml@v0',
                    'uses: []',
                    self::CALLER,
                ),
                self::WORKFLOW,
                'supported lifecycle caller',
            ],
            [
                self::CALLER,
                "on:\n  workflow_call:\n    inputs:\n      0:\n        type: string\n",
                'unsupported workflow input',
            ],
            [self::CALLER, "on:\n  workflow_call:\n    inputs:\n      publish: scalar\n", 'unsupported workflow input'],
            [self::CALLER, str_replace('type: boolean', 'type: object', self::WORKFLOW), 'unsupported workflow input'],
            [
                self::CALLER,
                str_replace('type: boolean', 'required: true', self::WORKFLOW),
                'unsupported workflow input',
            ]];
    }

    /** Invalid YAML reports a parser failure rather than inventing missing defaults or caller content. */
    public function testMalformedYamlCannotProduceAnInstallationContract(): void
    {
        $responses = $this->responses();
        $responses[$this->contentPath(self::WORKFLOW_PATH)] = $this->blob(self::WORKFLOW_PATH, 'on: [broken');
        $resolver = $this->resolver($responses);
        $this->expectException(RuntimeException::class);

        $resolver->resolve('v0.2.0');
    }

    /** Builds an API double that rejects mutations, external URLs and unexpected or excess reads. */
    private function resolver(array $responses, ?string $runtimeVersion = null): WorkflowReleaseResolver
    {
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::never())->method('paginate');
        $github->expects(self::exactly(count($responses)))->method('request')->willReturnCallback(
            static function (string $method, string $path, ?array $body = null) use ($responses): ?array {
                self::assertSame('GET', $method);
                self::assertNull($body);
                self::assertArrayHasKey($path, $responses);

                return $responses[$path];
            },
        );
        $versions = $this->createMock(PackageVersionResolverInterface::class);
        if (null === $runtimeVersion) {
            $versions->expects(self::never())->method('resolve');
        } else {
            $versions->expects(self::once())->method('resolve')->willReturn($runtimeVersion);
        }

        return new WorkflowReleaseResolver($github, $versions);
    }

    /** Defines one complete lightweight-tag release with content-addressed immutable resource responses. */
    private function responses(): array
    {
        return [self::REPOSITORY . '/releases/tags/v0.2.0' => ['tag_name' => 'v0.2.0', 'draft' => false, 'prerelease' => false],
            self::REPOSITORY . '/git/ref/tags/v0.2.0' => ['ref' => 'refs/tags/v0.2.0', 'object' => ['type' => 'commit', 'sha' => self::SHA]],
            $this->contentPath(self::CALLER_PATH) => $this->blob(self::CALLER_PATH, self::CALLER),
            $this->contentPath(self::WORKFLOW_PATH) => $this->blob(self::WORKFLOW_PATH, self::WORKFLOW)];
    }

    /** Extends immutable fixture objects without fetching or creating real Git refs. */
    private function annotatedResponses(int $annotations): array
    {
        $responses = $this->responses();
        $first = str_repeat('1', 40);
        $responses[self::REPOSITORY . '/git/ref/tags/v0.2.0']['object'] = ['type' => 'tag', 'sha' => $first];
        for ($index = 1; $index <= $annotations; ++$index) {
            $sha = str_repeat((string) $index, 40);
            $responses[self::REPOSITORY . '/git/tags/' . $sha] = ['sha' => $sha,
                'object' => $index === $annotations ? ['type' => 'commit', 'sha' => self::SHA]
                    : ['type' => 'tag', 'sha' => str_repeat((string) ($index + 1), 40)]];
        }

        return $responses;
    }

    /** Creates a regular Contents API response with its exact native Git blob identity. */
    private function blob(string $path, string $bytes): array
    {
        return ['type' => 'file', 'path' => $path, 'encoding' => 'base64', 'content' => base64_encode($bytes),
            'sha' => sha1('blob ' . strlen($bytes) . "\0" . $bytes)];
    }

    /** Binds every resource lookup to the fixture's verified complete commit rather than a moving tag. */
    private function contentPath(string $path): string
    {
        return self::REPOSITORY . '/contents/' . $path . '?ref=' . self::SHA;
    }
}
