<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release;

use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubBlobTransportInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Release\GeneratedFileEvidence;
use FastForward\Changelog\Release\ReleaseOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(GeneratedFileEvidence::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\FastForward\Changelog\Release\ReleaseOptions::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\FastForward\Changelog\Automation\Policy\GitHubEvidence::class)]
final class GeneratedFileEvidenceTest extends TestCase
{
    /** Default behavior performs no filesystem, Git or remote acquisition. */
    public function testEmptySelectionHasNoEffects(): void
    {
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::never())->method('filesAt');
        $files = $this->createMock(ManagedFileStoreInterface::class);
        $files->expects(self::never())->method('read');
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::never())->method('request');
        $evidence = new GeneratedFileEvidence($git, $files, $this->createStub(
            PackagePathResolverInterface::class,
        ), $github);
        self::assertSame([], $evidence->local(new ReleaseOptions('/fixture'), 'base'));
        self::assertSame([], $evidence->remote(new ReleaseOptions('/fixture'), 'base', 'head'));
    }

    /** Prepared payload is read as data through the guarded file boundary; committed output uses Git bytes. */
    #[TestWith([null])]
    #[TestWith(['head'])]
    public function testLocalPayload(?string $head): void
    {
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->method('filesAt')->willReturn([['path' => 'dist/tool', 'mode' => '100755']]);
        $files = $this->createMock(ManagedFileStoreInterface::class);
        $paths = null === $head ? $this->createMock(PackagePathResolverInterface::class) : $this->createStub(
            PackagePathResolverInterface::class,
        );
        if (null === $head) {
            $paths->expects(self::once())->method('absolutePath')->with('dist/tool', '/fixture')->willReturn(
                '/fixture/dist/tool',
            );
            $files->expects(self::once())->method('read')->with('/fixture/dist/tool')->willReturn("binary\0");
            $git->expects(self::never())->method('readFileAt');
        } else {
            $files->expects(self::never())->method('read');
            $git->expects(self::once())->method('readFileAt')->with('/fixture', 'head', 'dist/tool')->willReturn(
                "binary\0",
            );
        }
        $evidence = new GeneratedFileEvidence($git, $files, $paths, $this->createStub(GitHubClientInterface::class));
        self::assertSame(
            ['dist/tool' => hash('sha256', "binary\0")],
            $evidence->local($this->options(), 'base', $head),
        );
    }

    /** Missing, symbolic, ambiguous or wrong-path entries are outside the generated replacement contract. */
    #[TestWith([[]])]
    #[TestWith([[['path' => 'dist/tool', 'mode' => '120000']]])]
    #[TestWith([[['path' => 'other', 'mode' => '100644']]])]
    #[TestWith([[['path' => 'dist/tool', 'mode' => '100644'], ['path' => 'dist/tool', 'mode' => '100644']]])]
    public function testRejectsInvalidBaseFile(array $entries): void
    {
        $git = $this->createStub(GitRepositoryInterface::class);
        $git->method('filesAt')->willReturn($entries);
        $this->expectExceptionMessage('exact regular source-base files');
        $this->evidence($git)->local($this->options(), 'base');
    }

    /** A generated replacement cannot change executable permission. */
    public function testRejectsModeChange(): void
    {
        $git = $this->createStub(GitRepositoryInterface::class);
        $git->method('filesAt')->willReturnOnConsecutiveCalls(
            [['path' => 'dist/tool', 'mode' => '100755']],
            [['path' => 'dist/tool', 'mode' => '100644']],
        );
        $this->expectExceptionMessage('preserve its source-base file mode');
        $this->evidence($git)->local($this->options(), 'base', 'head');
    }

    /** Absent or oversized prepared and committed bytes are rejected. */
    #[TestWith([false, false])]
    #[TestWith([false, true])]
    #[TestWith([true, false])]
    #[TestWith([true, true])]
    public function testRejectsMissingOrOversizedBytes(bool $committed, bool $oversized): void
    {
        $bytes = $oversized ? str_repeat('x', 67_108_865) : null;
        $git = $this->createStub(GitRepositoryInterface::class);
        $git->method('filesAt')->willReturn([['path' => 'dist/tool', 'mode' => '100755']]);
        $git->method('readFileAt')->willReturn($bytes);
        $files = $this->createStub(ManagedFileStoreInterface::class);
        $files->method('read')->willReturn($bytes);
        $paths = $this->createStub(PackagePathResolverInterface::class);
        $paths->method('absolutePath')->willReturn('/fixture/dist/tool');
        $this->expectException(RuntimeException::class);
        new GeneratedFileEvidence($git, $files, $paths, $this->createStub(GitHubClientInterface::class))->local(
            $this->options(),
            'base',
            $committed ? 'head' : null,
        );
    }

    /** Existing regular base objects can be reused without acquiring their payload. */
    public function testBaseBlobIdentity(): void
    {
        $github = $this->remote();
        $github->expects(self::never())->method('blobDigest');
        self::assertSame(
            str_repeat('c', 40),
            $this->evidence(github: $github)->baseBlob($this->options(), 'base', 'dist/tool'),
        );
    }

    /** A nonregular source entry cannot authorize object reuse. */
    public function testBaseBlobRejectsNonregularEntry(): void
    {
        $github = $this->remote('mode');
        $github->expects(self::never())->method('blobDigest');
        $this->expectExceptionMessage('existing immutable regular blob');
        $this->evidence(github: $github)->baseBlob($this->options(), 'base', 'dist/tool');
    }
    /** Native remote proof reconstructs the actual immutable blob rather than trusting self-reported hashes. */
    public function testRemotePayloadUsesTreeIdentityAndRawDigest(): void
    {
        $github = $this->remote();
        $github->expects(self::once())->method('blobDigest')->with('owner/project', str_repeat('c', 40), 6)->willReturn(
            hash('sha256', 'binary'),
        );
        self::assertSame([
            'dist/tool' => hash('sha256', 'binary')],
            $this->evidence(github: $github)->remote($this->options(), 'base', 'head'),
        );
    }

    /** Remote reconstruction cannot silently fall back to an unverified adapter or repository. */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testRemoteRequiresTrustedTransport(bool $native): void
    {
        $github = $native ? $this->createStubForIntersectionOfInterfaces(
            [GitHubClientInterface::class, GitHubBlobTransportInterface::class],
        ) : $this->createStub(
            GitHubClientInterface::class,
        );
        $options = $native ? new ReleaseOptions('/fixture', repository: null, generatedFiles: [
            'dist/tool',
        ]) : $this->options();
        $this->expectExceptionMessage('trusted native blob transport');
        $this->evidence(github: $github)->remote($options, 'base', 'head');
    }

    /** Incomplete tree evidence and changed file identity or mode are denied before raw acquisition. */
    #[TestWith(['missing-root'])]
    #[TestWith(['wrong-tree'])]
    #[TestWith(['truncated'])]
    #[TestWith(['missing-entries'])]
    #[TestWith(['missing-path'])]
    #[TestWith(['mode'])]
    #[TestWith(['type'])]
    #[TestWith(['size'])]
    public function testRemoteRejectsInvalidTrees(string $failure): void
    {
        $github = $this->remote($failure);
        $github->expects(self::never())->method('blobDigest');
        $this->expectException(RuntimeException::class);
        $this->evidence(github: $github)->remote($this->options(), 'base', 'head');
    }

    /** Builds an injected fixture without consulting host or Git state. */
    private function evidence(
        ?GitRepositoryInterface $git = null,
        ?GitHubClientInterface $github = null,
    ): GeneratedFileEvidence {
        return new GeneratedFileEvidence($git ?? $this->createStub(GitRepositoryInterface::class), $this->createStub(
            ManagedFileStoreInterface::class,
        ), $this->createStub(
            PackagePathResolverInterface::class,
        ), $github ?? $this->createStub(
            GitHubClientInterface::class,
        ));
    }

    /** Selects one existing generated executable in a trusted repository. */
    private function options(): ReleaseOptions
    {
        return new ReleaseOptions('/fixture', repository: 'owner/project', generatedFiles: ['dist/tool']);
    }

    /** Supplies nonrecursive Git tree responses, with one explicit boundary fault when requested. */
    private function remote(string $failure = ''): GitHubClientInterface&GitHubBlobTransportInterface
    {
        $github = $this->createMockForIntersectionOfInterfaces(
            [GitHubClientInterface::class, GitHubBlobTransportInterface::class],
        );
        $github->method('request')->willReturnCallback(static function (string $method, string $endpoint) use (
            $failure
        ): array {
            if (str_contains($endpoint, '/commits/')) {
                return ['tree' => ['sha' => 'missing-root' === $failure ? null : str_repeat('a', 40)]];
            }
            $root = str_ends_with($endpoint, str_repeat('a', 40));
            $entries = $root ? [['path' => 'dist', 'type' => 'tree', 'sha' => str_repeat(
                'b',
                40,
            )]] : [['path' => 'tool', 'type' => 'type' === $failure ? 'tree' : 'blob', 'sha' => str_repeat(
                'c',
                40,
            ), 'mode' => 'mode' === $failure ? '120000' : '100755', 'size' => 'size' === $failure ? '6' : 6]];

            return ['sha' => 'wrong-tree' === $failure ? 'wrong' : str_repeat(
                $root ? 'a' : 'b',
                40,
            ), 'truncated' => 'truncated' === $failure, 'tree' => 'missing-entries' === $failure ? null : ('missing-path' === $failure ? [] : $entries)];
        });

        return $github;
    }
}
