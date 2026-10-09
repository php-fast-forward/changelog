<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation\Policy;

use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubEvidence::class)]
final class GitHubEvidenceTest extends TestCase
{
    #[Test]
    #[TestWith([null, false])]
    #[TestWith(['owner/project', true])]
    #[TestWith(['owner/project?token=x', false])]
    public function validatesRepository(?string $value, bool $valid): void
    {
        self::assertSame($valid, GitHubEvidence::repository($value));
    }

    #[Test]
    public function validatesNumericAccountIdentityRatherThanAuthorStrings(): void
    {
        $user = ['login' => 'actor', 'type' => 'User', 'id' => 42];
        self::assertTrue(GitHubEvidence::identity($user, 'actor', 'User'));
        self::assertTrue(GitHubEvidence::identity($user, 'actor', 'User', 42));
        self::assertFalse(GitHubEvidence::identity($user, 'actor', 'User', 43));
        self::assertFalse(GitHubEvidence::identity(['login' => 'actor', 'type' => 'User', 'id' => 0], 'actor', 'User'));
        self::assertFalse(GitHubEvidence::identity('actor', 'actor', 'User'));
    }

    #[Test]
    public function validatesCompleteGitObjectIdsAndPinsContentsReads(): void
    {
        self::assertTrue(GitHubEvidence::sha(str_repeat('a', 40)));
        self::assertTrue(GitHubEvidence::sha(str_repeat('b', 64)));
        self::assertFalse(GitHubEvidence::sha('aaaaaaa'));
        self::assertSame(
            '/repos/owner/project/contents/.changelog/f%20name.md?ref=' . str_repeat('a', 40),
            GitHubEvidence::contentsPath('owner/project', '.changelog/f name.md', str_repeat('a', 40)),
        );
    }

    #[Test]
    #[TestWith([null, null])]
    #[TestWith([['type' => 'dir', 'encoding' => 'base64', 'content' => 'YQ=='], null])]
    #[TestWith([['type' => 'file', 'encoding' => 'none', 'content' => 'a'], null])]
    #[TestWith([['type' => 'file', 'encoding' => 'base64', 'content' => '!'], null])]
    #[TestWith([['type' => 'file', 'encoding' => 'base64', 'content' => 'YQ=='], 'a'])]
    #[TestWith([['type' => 'file', 'encoding' => 'base64', 'content' => ''], ''])]
    public function decodesOnlyCompleteFileEvidence(?array $file, ?string $expected): void
    {
        self::assertSame($expected, GitHubEvidence::content($file));
    }

    #[Test]
    public function separatesReadPolicyForForksFromPrivilegedWritePolicy(): void
    {
        $pr = ['number' => 2, 'state' => 'open', 'base' => ['repo' => ['id' => 5, 'full_name' => 'Owner/Project'], 'sha' => str_repeat(
            'b',
            40,
        )], 'head' => ['repo' => ['id' => 5, 'full_name' => 'owner/project'], 'sha' => str_repeat(
            'a',
            40,
        ), 'ref' => 'safe/branch']];
        self::assertTrue(GitHubEvidence::pullRequest($pr, 'owner/project', 2));
        $pr['head']['repo'] = ['id' => 6, 'full_name' => 'fork/project'];
        self::assertFalse(GitHubEvidence::pullRequest($pr, 'owner/project', 2));
        self::assertTrue(GitHubEvidence::pullRequest($pr, 'owner/project', 2, false));
        $pr['head']['ref'] = '../main';
        self::assertFalse(GitHubEvidence::pullRequest($pr, 'owner/project', 2, false));
        self::assertFalse(GitHubEvidence::pullRequest(null, 'owner/project', 2));
    }
}
