<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Process\Factory;

use FastForward\Changelog\Process\Factory\ProcessFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(ProcessFactory::class)]
final class ProcessFactoryTest extends TestCase
{
    public function testCreatesAnUnstartedConsumerProcessWithCredentialInheritanceDisabled(): void
    {
        $process = new ProcessFactory('/fixture/project')->create(['git', 'status']);
        self::assertInstanceOf(Process::class, $process);
        self::assertSame('/fixture/project', $process->getWorkingDirectory());
        self::assertSame(60.0, $process->getTimeout());
        self::assertFalse($process->isRunning());
        self::assertSame(['GITHUB_TOKEN' => false, 'GH_TOKEN' => false,
            'FF_CHANGELOG_TOKEN' => false, 'FF_CHANGELOG_INPUTS' => false], $process->getEnv());
    }
}
