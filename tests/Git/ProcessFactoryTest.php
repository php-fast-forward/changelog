<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Git;

use FastForward\Changelog\Git\ProcessFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(ProcessFactory::class)]
final class ProcessFactoryTest extends TestCase
{
    #[Test]
    public function createBuildsAnUnstartedSymfonyProcess(): void
    {
        $process = (new ProcessFactory())->create(['git', 'status']);

        self::assertInstanceOf(Process::class, $process);
        self::assertSame("'git' 'status'", $process->getCommandLine());
        self::assertFalse($process->isRunning());
    }
}
