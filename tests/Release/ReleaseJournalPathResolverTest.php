<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release;

use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Release\ReleaseJournalPathResolver;
use FastForward\Changelog\Release\ReleaseOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseJournalPathResolver::class)]
#[UsesClass(ReleaseOptions::class)]
final class ReleaseJournalPathResolverTest extends TestCase
{
    /** Monorepo packages share Git metadata but cannot overwrite each other's interrupted transactions. */
    public function testGitRecoveryIsScopedByManagedPathsAndStableAcrossPresentationChanges(): void
    {
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::exactly(3))->method('journalPath')->with('/consumer')->willReturn(
            '/external/git/worktrees/consumer/changelog-release-plan.json',
        );
        $resolver = new ReleaseJournalPathResolver($git, '/synthetic-temp');
        $first = $resolver->resolve(new ReleaseOptions('/consumer'));
        $second = $resolver->resolve(
            new ReleaseOptions(
                '/consumer',
                fragmentDirectory: '.changelog/package-b',
                changelogFile: 'packages/b/CHANGELOG.md',
            ),
        );
        $locale = $resolver->resolve(new ReleaseOptions('/consumer', locale: 'pt-BR'));
        self::assertStringStartsWith('/external/git/worktrees/consumer/changelog-release-plan-', $first);
        self::assertNotSame($first, $second);
        self::assertSame($first, $locale);
    }

    /** Non-Git consumers retain cross-process recovery in injected temporary storage, never in their project. */
    public function testNonGitJournalUsesInjectedTemporaryDirectoryWithoutHostState(): void
    {
        $git = $this->createStub(GitRepositoryInterface::class);
        $git->method('journalPath')->willReturn(null);
        $resolver = new ReleaseJournalPathResolver($git, 'C:\\synthetic-temp\\');
        $first = $resolver->resolve(new ReleaseOptions('/consumer'));
        self::assertStringStartsWith('C:/synthetic-temp/fast-forward-changelog/', $first);
        self::assertStringEndsWith('/release-plan.json', $first);
        self::assertNotSame($first, $resolver->resolve(new ReleaseOptions('/another')));
    }
}
