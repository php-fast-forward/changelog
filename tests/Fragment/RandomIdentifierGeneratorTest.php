<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Fragment;

use FastForward\Changelog\Fragment\RandomIdentifierGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Random\Engine;
use Random\Randomizer;

#[CoversClass(RandomIdentifierGenerator::class)]
final class RandomIdentifierGeneratorTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function usesOnlyTheInjectedEngineAndReturnsACanonicalStableIdentity(): void
    {
        $engine = $this->prophesize(Engine::class);
        $engine->generate()->willReturn(str_repeat('a', 8))->shouldBeCalledTimes(2);
        $generator = new RandomIdentifierGenerator(new Randomizer($engine->reveal()));
        self::assertSame('change-' . str_repeat('61', 16) . '.md', $generator->generate());
    }
}
