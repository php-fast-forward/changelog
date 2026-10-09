<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Fragment\Factory;

use FastForward\Changelog\Fragment\Factory\IdentifierGeneratorFactory;
use FastForward\Changelog\Fragment\RandomIdentifierGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Random\Engine;

#[CoversClass(IdentifierGeneratorFactory::class)]
#[UsesClass(RandomIdentifierGenerator::class)]
final class IdentifierGeneratorFactoryTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function composesASecureDefaultWithoutRequestingRandomBytes(): void
    {
        self::assertInstanceOf(RandomIdentifierGenerator::class, new IdentifierGeneratorFactory()->create());
    }

    #[Test]
    public function honorsASuppliedEngineWithoutTouchingRealEntropy(): void
    {
        $engine = $this->prophesize(Engine::class);
        $engine->generate()->willReturn(str_repeat('b', 8))->shouldBeCalledTimes(2);
        self::assertSame(
            'change-' . str_repeat('62', 16) . '.md',
            new IdentifierGeneratorFactory()->create($engine->reveal())->generate(),
        );
    }
}
