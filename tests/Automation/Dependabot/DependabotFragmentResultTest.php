<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation\Dependabot;

use Error;
use FastForward\Changelog\Automation\Dependabot\DependabotFragmentResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DependabotFragmentResult::class)]
final class DependabotFragmentResultTest extends TestCase
{
    public function testConflictRetainsAnAlreadyCreatedCommitAndIndependentDiagnostics(): void
    {
        $sha = str_repeat('c', 40);
        $diagnostics = ['The branch changed after creation.', 'Review the created commit before retrying.'];
        $result = new DependabotFragmentResult('conflict', '.changelog/dependabot-7.md', $sha, $diagnostics);
        $diagnostics[] = 'Unrelated later observation.';

        self::assertSame([
            'status' => 'conflict',
            'path' => '.changelog/dependabot-7.md',
            'commitSha' => $sha,
            'diagnostics' => ['The branch changed after creation.', 'Review the created commit before retrying.'],
        ], get_object_vars($result));
    }

    public function testUnchangedOutcomeHasNoCommitAndCannotBeReclassifiedByMutation(): void
    {
        $result = new DependabotFragmentResult('unchanged', '.changelog/dependabot-7.md', null, []);
        self::assertNull($result->commitSha);
        self::assertSame([], $result->diagnostics);
        $this->expectException(Error::class);

        $result->status = 'created';
    }
}
