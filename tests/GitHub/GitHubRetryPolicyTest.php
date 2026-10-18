<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\GitHub;

use FastForward\Changelog\GitHub\GitHubRetryPolicy;
use FastForward\Changelog\GitHub\GitHubTransientException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(GitHubRetryPolicy::class)]
#[CoversClass(GitHubTransientException::class)]
final class GitHubRetryPolicyTest extends TestCase
{
    /**
     * Records bounded waits and verifies the final failed attempt cannot create a fourth request.
     *
     * @return void No wall clock or real delay is consulted.
     */
    public function testTwoExtraAttemptsHonorTheServerDeltaWithinTheCap(): void
    {
        $waits = [];
        $policy = new GitHubRetryPolicy(static function (int $microseconds) use (&$waits): void {
            $waits[] = $microseconds;
        });
        self::assertTrue($policy->retry(new GitHubTransientException('controlled'), 1));
        self::assertTrue($policy->retry(new GitHubTransientException('controlled', 30), 2));
        self::assertFalse($policy->retry(new GitHubTransientException('controlled'), 3));
        self::assertSame([1_000_000, 30_000_000], $waits);
    }

    /**
     * Refuses unsupported attempt/delay evidence without consulting the injected sleeper.
     *
     * @param  ?int $after   Controlled delta seconds or an unsupported server-date marker.
     * @param  int  $attempt Failed request index used to enforce the three-attempt ceiling.
     * @return void No failed eligibility test may sleep.
     */
    #[TestWith([null, 1])]
    #[TestWith([-1, 1])]
    #[TestWith([31, 1])]
    #[TestWith([0, 0])]
    #[TestWith([0, 3])]
    public function testUnboundedOrUnsupportedEvidenceNeverSleeps(?int $after, int $attempt): void
    {
        $policy = new GitHubRetryPolicy(static function (): void {
            self::fail('An unsupported retry must not sleep.');
        });
        self::assertFalse($policy->retry(new GitHubTransientException('controlled', $after), $attempt));
        self::assertFalse($policy->retry(new RuntimeException('unclassified'), 1));
        self::assertFalse($policy->retry(null, 1));
    }
}
