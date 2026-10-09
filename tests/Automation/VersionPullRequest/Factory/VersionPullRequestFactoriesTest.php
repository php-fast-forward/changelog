<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation\VersionPullRequest\Factory;

use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestExceptionFactory;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestInputFactory;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestResultFactory;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestException;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestInput;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VersionPullRequestInputFactory::class)]
#[CoversClass(VersionPullRequestResultFactory::class)]
#[CoversClass(VersionPullRequestExceptionFactory::class)]
#[CoversClass(VersionPullRequestInput::class)]
#[CoversClass(VersionPullRequestResult::class)]
#[UsesClass(VersionPullRequestException::class)]
final class VersionPullRequestFactoriesTest extends TestCase
{
    #[Test]
    public function retainsTypedDefaultsCustomPresentationAndRemoteOutcome(): void
    {
        $factory = new VersionPullRequestInputFactory(new VersionPullRequestExceptionFactory());
        $default = $factory->create();
        self::assertSame('main', $default->baseBranch);
        self::assertSame('changelog/version', $default->managedBranch);
        self::assertSame('github-actions[bot]', $default->automationActor);
        self::assertSame('chore: update changelog', $default->title);
        self::assertFalse($default->dryRun);
        $custom = $factory->create('support/1.x', 'changelog/support-1.x', 'my-app[bot]', 'Update history', true);
        self::assertTrue($custom->dryRun);
        self::assertSame('my-app[bot]', $custom->automationActor);
        $value = new VersionPullRequestResultFactory()->create(
            'updated',
            7,
            'https://github.com/o/r/pull/7',
            str_repeat('a', 40),
            str_repeat('b', 64),
            null,
            true,
            ['maintenance'],
        );
        self::assertSame('updated', $value->status);
        self::assertSame(7, $value->prNumber);
        self::assertSame('https://github.com/o/r/pull/7', $value->url);
        self::assertSame(str_repeat('a', 40), $value->headSha);
        self::assertSame(str_repeat('b', 64), $value->planId);
        self::assertNull($value->version);
        self::assertTrue($value->maintenance);
        self::assertSame(['maintenance'], $value->diagnostics);
        self::assertNull(new VersionPullRequestResultFactory()->create('none')->prNumber);
        self::assertSame('diagnostic', new VersionPullRequestExceptionFactory()->create('diagnostic')->getMessage());
    }

    #[Test]
    #[TestWith(['main', 'main', 'github-actions[bot]', 'title'])]
    #[TestWith(['../main', 'changelog/version', 'github-actions[bot]', 'title'])]
    #[TestWith(['main', 'changelog//version', 'github-actions[bot]', 'title'])]
    #[TestWith(['main', 'changelog/version/', 'github-actions[bot]', 'title'])]
    #[TestWith(['main', 'changelog/version.', 'github-actions[bot]', 'title'])]
    #[TestWith(['main', 'changelog/.hidden', 'github-actions[bot]', 'title'])]
    #[TestWith(['main', 'changelog/version.lock', 'github-actions[bot]', 'title'])]
    #[TestWith(['main', 'changelog/version', 'human', 'title'])]
    #[TestWith(['main', 'changelog/version', 'github-actions[bot]', ''])]
    #[TestWith(['main', 'changelog/version', 'github-actions[bot]', "title\nsecret"])]
    #[TestWith(['main', 'changelog/version', 'github-actions[bot]', 'long'])]
    #[TestWith(['long', 'changelog/version', 'github-actions[bot]', 'title'])]
    public function rejectsUnsafeAutomationSettings(string $base, string $branch, string $actor, string $title): void
    {
        $this->expectException(VersionPullRequestException::class);
        new VersionPullRequestInputFactory(new VersionPullRequestExceptionFactory())->create(
            'long' === $base ? str_repeat('a', 256) : $base,
            $branch,
            $actor,
            'long' === $title ? str_repeat('a', 257) : $title,
        );
    }
}
