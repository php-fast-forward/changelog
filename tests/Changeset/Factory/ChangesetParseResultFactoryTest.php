<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Changeset\Factory;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\ChangesetParseResult;
use FastForward\Changelog\Changeset\Factory\ChangesetParseResultFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangesetParseResultFactory::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(Category::class)]
#[UsesClass(ChangesetParseResult::class)]
final class ChangesetParseResultFactoryTest extends TestCase
{
    #[Test]
    public function validRetainsTheChangesetAndItsIdentifier(): void
    {
        $changeset = new Changeset('one.md', Category::Added, null, null, '@bot', 'Body.');

        $result = new ChangesetParseResultFactory()->valid($changeset);

        self::assertSame('one.md', $result->id);
        self::assertSame($changeset, $result->changeset);
        self::assertSame([], $result->errors);
    }

    #[Test]
    public function invalidRetainsEveryDiagnosticWithoutAChangeset(): void
    {
        $result = new ChangesetParseResultFactory()->invalid('bad.md', ['first', 'second']);

        self::assertSame('bad.md', $result->id);
        self::assertNull($result->changeset);
        self::assertSame(['first', 'second'], $result->errors);
    }
}
