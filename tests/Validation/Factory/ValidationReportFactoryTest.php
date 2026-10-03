<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Validation\Factory;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Validation\Factory\ValidationReportFactory;
use FastForward\Changelog\Validation\ValidationReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidationReportFactory::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(Category::class)]
#[UsesClass(ValidationReport::class)]
final class ValidationReportFactoryTest extends TestCase
{
    #[Test]
    public function createForwardsSuccessfulFragmentsDiagnosticsAndWaiver(): void
    {
        $changeset = new Changeset('one.md', Category::Fixed, null, 1, '@bot', 'Fix.');
        $errors = ['two.md' => ['invalid']];

        $hashes = ['/consumer/.changelog/one.md' => hash('sha256', 'exact')];
        $report = new ValidationReportFactory()->create([$changeset], $errors, true, $hashes);

        self::assertSame([$changeset], $report->changesets);
        self::assertSame($errors, $report->errors);
        self::assertTrue($report->waived);
        self::assertSame($hashes, $report->hashes);
    }
}
