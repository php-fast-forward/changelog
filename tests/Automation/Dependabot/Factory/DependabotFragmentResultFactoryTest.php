<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation\Dependabot\Factory;

use FastForward\Changelog\Automation\Dependabot\DependabotFragmentResult;
use FastForward\Changelog\Automation\Dependabot\Factory\DependabotFragmentResultFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DependabotFragmentResultFactory::class)]
#[CoversClass(DependabotFragmentResult::class)]
final class DependabotFragmentResultFactoryTest extends TestCase
{
    #[Test]
    public function preservesCreatedCommitAndUncertainMutationState(): void
    {
        $factory = new DependabotFragmentResultFactory();
        $value = $factory->create(
            'conflict',
            '.changelog/dependabot-7.md',
            str_repeat('c', 40),
            ['Review changed branch.'],
        );
        self::assertSame('conflict', $value->status);
        self::assertSame('.changelog/dependabot-7.md', $value->path);
        self::assertSame(str_repeat('c', 40), $value->commitSha);
        self::assertSame(['Review changed branch.'], $value->diagnostics);
        $default = $factory->create('unchanged', '.changelog/dependabot-7.md');
        self::assertNull($default->commitSha);
        self::assertSame([], $default->diagnostics);
    }
}
