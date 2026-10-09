<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Publication\Factory;

use FastForward\Changelog\Publication\Factory\PublicationResultFactory;
use FastForward\Changelog\Publication\PublicationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublicationResultFactory::class)]
#[CoversClass(PublicationResult::class)]
final class PublicationResultFactoryTest extends TestCase
{
    /** Results MUST retain exact approved identity, action order and stable machine fields. */
    public function testCapturesStableMachineResult(): void
    {
        $result = new PublicationResultFactory()->create(
            'published',
            '1.0.1',
            'v1.0.1',
            str_repeat('b', 40),
            'https://github.com/owner/repo/releases/tag/v1.0.1',
            ['create_tag', 'create_release'],
        );
        self::assertSame([
            'state' => 'published', 'version' => '1.0.1', 'tag' => 'v1.0.1', 'sha' => str_repeat(
                'b',
                40,
            ), 'url' => 'https://github.com/owner/repo/releases/tag/v1.0.1', 'actions' => [
                'create_tag',
                'create_release',
            ]],
            $result->summary(),
        );
        $maintenance = new PublicationResultFactory()->create('maintenance', null, null, str_repeat('b', 40), null, []);
        self::assertNull($maintenance->version);
        self::assertNull($maintenance->tag);
        self::assertNull($maintenance->url);
        self::assertSame([], $maintenance->actions);
    }
}
