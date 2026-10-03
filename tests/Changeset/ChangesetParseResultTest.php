<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Changeset;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\ChangesetParseResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangesetParseResult::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(Category::class)]
final class ChangesetParseResultTest extends TestCase
{
    #[Test]
    public function validResultRequiresAChangesetWithoutErrors(): void
    {
        $changeset = new Changeset('one.md', Category::Added, null, null, '@bot', 'Body.');

        self::assertTrue(new ChangesetParseResult('one.md', $changeset, [])->isValid());
        self::assertFalse(new ChangesetParseResult('one.md', null, [])->isValid());
        self::assertFalse(new ChangesetParseResult('one.md', $changeset, ['error'])->isValid());
    }
}
