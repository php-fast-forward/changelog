<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Validation;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Validation\ValidationReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidationReport::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(Category::class)]
final class ValidationReportTest extends TestCase
{
    #[Test]
    public function reportIsValidOnlyWhenNoDiagnosticExists(): void
    {
        $changeset = new Changeset('one.md', Category::Added, null, 1, '@bot', 'Body.');
        $valid = new ValidationReport([$changeset], [], false);
        $invalid = new ValidationReport([$changeset], ['one.md' => ['error']], true);

        self::assertSame([$changeset], $valid->changesets);
        self::assertSame([], $valid->errors);
        self::assertFalse($valid->waived);
        self::assertTrue($valid->isValid());
        self::assertFalse($invalid->isValid());
        self::assertTrue($invalid->waived);
    }
}
