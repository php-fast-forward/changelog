<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Configuration\Factory;

use FastForward\Changelog\Configuration\Factory\ConfigSourceFactory;
use FastForward\Config\PhpFileConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(ConfigSourceFactory::class)]
final class ConfigSourceFactoryTest extends TestCase
{
    /** Construction carries explicit values into a lazy source without loading a host file. */
    public function testCreationIsLazyAndNonPersistent(): void
    {
        $source = new ConfigSourceFactory()->create('/consumer/custom.php');
        self::assertInstanceOf(PhpFileConfig::class, $source);
        self::assertSame(
            '/consumer/custom.php',
            new ReflectionProperty(PhpFileConfig::class, 'file')->getValue($source),
        );
        self::assertFalse(new ReflectionProperty(PhpFileConfig::class, 'persistent')->getValue($source));
    }
}
