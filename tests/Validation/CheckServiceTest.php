<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Validation;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Validation\CheckService;
use FastForward\Changelog\Validation\Factory\ValidationReportFactoryInterface;
use FastForward\Changelog\Validation\ValidationReport;
use FastForward\Changelog\Validator\ChangesetValidatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use RuntimeException;

#[UsesClass(ReleaseOptions::class)]
#[CoversClass(CheckService::class)]
#[UsesClass(Category::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(ValidationReport::class)]
final class CheckServiceTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function localCheckingAcceptsAnEmptyInventoryWithoutReadingGit(): void
    {
        $validator = $this->prophesize(ChangesetValidatorInterface::class);
        $validator->validate('/repo/.changelog', false, false)->willReturn(new ValidationReport([], [], false))->shouldBeCalledOnce();
        $git = $this->prophesize(GitRepositoryInterface::class);
        $git->changesSince(Argument::any(), Argument::any())->shouldNotBeCalled();
        self::assertTrue($this->checker($validator, $git)->check(new ReleaseOptions('/repo'))->isValid());
    }

    #[Test]
    #[TestWith(['A', null])]
    #[TestWith(['C75', '.changelog/old.md'])]
    #[TestWith(['R100', 'draft.md'])]
    public function countsOnlyValidNewAddedCopiedOrImportedRenamedFragments(string $status, ?string $previous): void
    {
        $old = new Changeset('old.md', Category::Fixed, null, null, null, 'Older pending.');
        $new = new Changeset('new.md', Category::Changed, null, null, null, 'New change.');
        $validator = $this->prophesize(ChangesetValidatorInterface::class);
        $validator->validate('/repo/.changelog', false, false)->willReturn(new ValidationReport([$old, $new], [], false, ['/repo/.changelog/new.md' => hash('sha256', 'accepted')]))->shouldBeCalledOnce();
        $validator->validatePaths('/repo/.changelog', ['/repo/.changelog/new.md'], false, false)->willReturn(new ValidationReport([$new], [], false))->shouldBeCalledOnce();
        $git = $this->prophesize(GitRepositoryInterface::class);
        $git->changesSince('/repo', 'origin/main')->willReturn([['status' => $status, 'path' => './.changelog/new.md', 'previous' => $previous]])->shouldBeCalledOnce();
        $report = $this->checker($validator, $git)->check(new ReleaseOptions('/repo'), 'origin/main');
        self::assertTrue($report->isValid());
        self::assertSame([$old, $new], $report->changesets);
        self::assertSame(['/repo/.changelog/new.md' => hash('sha256', 'accepted')], $report->hashes);
    }

    #[Test]
    public function oldPendingFragmentsDoNotSatisfyThePullRequestContributionGate(): void
    {
        $old = new Changeset('old.md', Category::Fixed, null, null, null, 'Older pending.');
        $validator = $this->prophesize(ChangesetValidatorInterface::class);
        $validator->validate('/repo/.changelog', false, false)->willReturn(new ValidationReport([$old], [], false))->shouldBeCalledOnce();
        $validator->validatePaths('/repo/.changelog', [], false, false)->willReturn(new ValidationReport([], [], false))->shouldBeCalledOnce();
        $git = $this->prophesize(GitRepositoryInterface::class);
        $git->changesSince('/repo', 'base')->willReturn([
            ['status' => 'M', 'path' => 'README.md', 'previous' => null],
            ['status' => 'A', 'path' => '.changelog/readme.txt', 'previous' => null],
            ['status' => 'A', 'path' => '.changelog/AGENTS.md', 'previous' => null],
        ])->shouldBeCalledOnce();
        $report = $this->checker($validator, $git)->check(new ReleaseOptions('/repo'), 'base');
        self::assertFalse($report->isValid());
        self::assertStringContainsString('existing pending fragments do not satisfy', $report->errors['@contribution'][0]);
    }

    #[Test]
    #[TestWith(['M', '.changelog/old.md', null])]
    #[TestWith(['D', '.changelog/old.md', null])]
    #[TestWith(['T', '.changelog/old.md', null])]
    #[TestWith(['R100', '.changelog/renamed.md', '.changelog/old.md'])]
    #[TestWith(['R100', 'outside.md', '.changelog/old.md'])]
    #[TestWith(['U', '.changelog/old.md', null])]
    public function inheritedMutationDeletionAndRenameFailOrdinaryChecks(string $status, string $path, ?string $previous): void
    {
        $validator = $this->emptyValidator();
        $git = $this->prophesize(GitRepositoryInterface::class);
        $git->changesSince('/repo', 'base')->willReturn([['status' => $status, 'path' => $path, 'previous' => $previous]])->shouldBeCalledOnce();
        $report = $this->checker($validator, $git)->check(new ReleaseOptions('/repo'), 'base', waiverAuthorized: true);
        self::assertFalse($report->isValid());
        self::assertStringContainsString('status ' . $status, $report->errors[$path][0]);
        self::assertArrayNotHasKey('@contribution', $report->errors);
    }

    #[Test]
    public function verifiedManagedVersionOperationsMayConsumeFragmentsWithoutFabricatingAnAddition(): void
    {
        $validator = $this->emptyValidator();
        $git = $this->prophesize(GitRepositoryInterface::class);
        $git->changesSince('/repo', 'base')->willReturn([
            ['status' => 'M', 'path' => 'CHANGELOG.md', 'previous' => null],
            ['status' => 'A', 'path' => '.changelog/release-plan.json', 'previous' => null],
            ['status' => 'D', 'path' => '.changelog/consumed.md', 'previous' => null],
        ])->shouldBeCalledOnce();
        self::assertTrue($this->checker($validator, $git)->check(new ReleaseOptions('/repo'), 'base', centralChangeAuthorized: true, authorizationKind: 'managed-version')->isValid());
    }

    #[Test]
    #[TestWith(['A', '.changelog/release-plan.json', null])]
    #[TestWith(['M', './.changelog/release-plan.json', null])]
    #[TestWith(['D', '.changelog/release-plan.json', null])]
    #[TestWith(['T', '.changelog/release-plan.json', null])]
    #[TestWith(['R100', 'other.json', '.changelog/release-plan.json'])]
    #[TestWith(['R100', '.changelog/release-plan.json', 'other.json'])]
    public function ordinaryReceiptChangesFailEvenAlongsideAValidNewFragment(string $status, string $path, ?string $previous): void
    {
        $fragment = new Changeset('new.md', Category::Fixed, null, null, null, 'A valid contribution.');
        $validator = $this->prophesize(ChangesetValidatorInterface::class);
        $validator->validate('/repo/.changelog', false, false)->willReturn(new ValidationReport([$fragment], [], false))->shouldBeCalledOnce();
        $validator->validatePaths('/repo/.changelog', ['/repo/.changelog/new.md'], false, false)->willReturn(new ValidationReport([$fragment], [], false))->shouldBeCalledOnce();
        $git = $this->prophesize(GitRepositoryInterface::class);
        $git->changesSince('/repo', 'base')->willReturn([
            ['status' => $status, 'path' => $path, 'previous' => $previous],
            ['status' => 'A', 'path' => '.changelog/new.md', 'previous' => null],
        ])->shouldBeCalledOnce();

        $report = $this->checker($validator, $git)->check(new ReleaseOptions('/repo'), 'base');

        self::assertFalse($report->isValid());
        self::assertSame(['@receipt'], array_keys($report->errors));
        self::assertStringContainsString('verified managed version transaction', $report->errors['@receipt'][0]);
    }

    #[Test]
    #[TestWith([false, true, 'waiver'])]
    #[TestWith([true, true, 'maintenance'])]
    #[TestWith([true, false, 'ordinary'])]
    #[TestWith([true, false, 'unknown'])]
    #[TestWith([false, true, 'managed-version'])]
    public function receiptAuthorityCannotComeFromWaiversMaintenanceOrAnUnboundKind(bool $central, bool $waiver, string $kind): void
    {
        $validator = $this->emptyValidator();
        $git = $this->prophesize(GitRepositoryInterface::class);
        $git->changesSince('/repo', 'base')->willReturn([['status' => 'M', 'path' => '.changelog/release-plan.json', 'previous' => null]])->shouldBeCalledOnce();

        $report = $this->checker($validator, $git)->check(new ReleaseOptions('/repo'), 'base', $central, $waiver, $kind);

        self::assertFalse($report->isValid());
        self::assertArrayHasKey('@receipt', $report->errors);
    }

    #[Test]
    #[TestWith(['A'])]
    #[TestWith(['M'])]
    #[TestWith(['D'])]
    public function verifiedManagedVersionMayUpdateItsReceiptDuringPreparationOrRecovery(string $status): void
    {
        $validator = $this->emptyValidator();
        $git = $this->prophesize(GitRepositoryInterface::class);
        $git->changesSince('/repo', 'base')->willReturn([['status' => $status, 'path' => '.changelog/release-plan.json', 'previous' => null]])->shouldBeCalledOnce();

        self::assertTrue($this->checker($validator, $git)->check(new ReleaseOptions('/repo'), 'base', centralChangeAuthorized: true, authorizationKind: 'managed-version')->isValid());
    }

    #[Test]
    #[TestWith([true, false, 'maintenance'])]
    #[TestWith([true, true, 'maintenance'])]
    #[TestWith([true, false, 'ordinary'])]
    #[TestWith([false, true, 'managed-version'])]
    public function maintenanceAndWaiversCannotConsumeInheritedPendingFragments(bool $central, bool $waiver, string $kind): void
    {
        $validator = $this->emptyValidator();
        $git = $this->prophesize(GitRepositoryInterface::class);
        $git->changesSince('/repo', 'base')->willReturn([
            ['status' => 'M', 'path' => 'CHANGELOG.md', 'previous' => null],
            ['status' => 'D', 'path' => '.changelog/pending.md', 'previous' => null],
        ])->shouldBeCalledOnce();

        $report = $this->checker($validator, $git)->check(new ReleaseOptions('/repo'), 'base', $central, $waiver, $kind);

        self::assertFalse($report->isValid());
        self::assertArrayHasKey('.changelog/pending.md', $report->errors);
    }

    #[Test]
    public function maintenanceMayReformatCentralHistoryWhilePreservingPendingFragments(): void
    {
        $validator = $this->emptyValidator();
        $git = $this->prophesize(GitRepositoryInterface::class);
        $git->changesSince('/repo', 'base')->willReturn([['status' => 'M', 'path' => 'CHANGELOG.md', 'previous' => null]])->shouldBeCalledOnce();

        self::assertTrue($this->checker($validator, $git)->check(new ReleaseOptions('/repo'), 'base', centralChangeAuthorized: true, authorizationKind: 'maintenance')->isValid());
    }

    #[Test]
    public function receiptProtectionUsesTheConfiguredRepositoryRelativeFragmentRoot(): void
    {
        $validator = $this->prophesize(ChangesetValidatorInterface::class);
        $validator->validate('/repo/packages/lib/.changes', false, false)->willReturn(new ValidationReport([], [], false))->shouldBeCalledOnce();
        $validator->validatePaths('/repo/packages/lib/.changes', [], false, false)->willReturn(new ValidationReport([], [], false))->shouldBeCalledOnce();
        $git = $this->prophesize(GitRepositoryInterface::class);
        $git->changesSince('/repo', 'base')->willReturn([['status' => 'M', 'path' => '.\\packages\\lib\\.changes\\release-plan.json', 'previous' => null]])->shouldBeCalledOnce();

        $report = $this->checker($validator, $git)->check(new ReleaseOptions('/repo', fragmentDirectory: 'packages\\lib\\.changes'), 'base', waiverAuthorized: true);

        self::assertFalse($report->isValid());
        self::assertSame(['@receipt'], array_keys($report->errors));
    }

    #[Test]
    #[TestWith(['CHANGELOG.md', null])]
    #[TestWith(['new-document.md', 'CHANGELOG.md'])]
    public function verifiedWaiversNeverAuthorizeCentralDocumentEditsOrRenames(string $path, ?string $previous): void
    {
        $validator = $this->emptyValidator();
        $git = $this->prophesize(GitRepositoryInterface::class);
        $git->changesSince('/repo', 'base')->willReturn([['status' => 'R100', 'path' => $path, 'previous' => $previous]])->shouldBeCalledOnce();
        $report = $this->checker($validator, $git)->check(new ReleaseOptions('/repo'), 'base', waiverAuthorized: true);
        self::assertFalse($report->isValid());
        self::assertArrayHasKey('@changelog', $report->errors);
    }

    #[Test]
    public function invalidNewFragmentsAccumulateDiagnosticsEvenWithAVerifiedWaiver(): void
    {
        $validator = $this->prophesize(ChangesetValidatorInterface::class);
        $validator->validate('/repo/.changelog', false, false)->willReturn(new ValidationReport([], ['bad.md' => ['empty body']], false))->shouldBeCalledOnce();
        $validator->validatePaths('/repo/.changelog', ['/repo/.changelog/bad.md'], false, false)->willReturn(new ValidationReport([], ['bad.md' => ['empty body', 'bad category']], false))->shouldBeCalledOnce();
        $git = $this->prophesize(GitRepositoryInterface::class);
        $git->changesSince('/repo', 'base')->willReturn([
            ['status' => 'A', 'path' => '.changelog/bad.md', 'previous' => null],
            ['status' => 'A', 'path' => '.changelog/bad.md', 'previous' => null],
        ])->shouldBeCalledOnce();
        $report = $this->checker($validator, $git)->check(new ReleaseOptions('/repo'), 'base', waiverAuthorized: true);
        self::assertFalse($report->isValid());
        self::assertSame(['empty body', 'bad category'], $report->errors['bad.md']);
        self::assertTrue($report->waived);
    }

    #[Test]
    public function copyingAnInheritedFragmentOutOfTheScopeDoesNotMutateIt(): void
    {
        $validator = $this->emptyValidator();
        $git = $this->prophesize(GitRepositoryInterface::class);
        $git->changesSince('/repo', 'base')->willReturn([['status' => 'C100', 'path' => 'notes.md', 'previous' => './.changelog/old.md']])->shouldBeCalledOnce();
        self::assertTrue($this->checker($validator, $git)->check(new ReleaseOptions('/repo'), 'base', waiverAuthorized: true)->isValid());
    }

    #[Test]
    #[TestWith(['.changelog', null])]
    #[TestWith(['outside', '.changelog'])]
    public function fragmentRootCannotBecomeATrackedFileEvenInAuthorizedCentralOperations(string $path, ?string $previous): void
    {
        $validator = $this->emptyValidator();
        $git = $this->prophesize(GitRepositoryInterface::class);
        $git->changesSince('/repo', 'base')->willReturn([['status' => 'R100', 'path' => $path, 'previous' => $previous]])->shouldBeCalledOnce();
        $report = $this->checker($validator, $git)->check(new ReleaseOptions('/repo'), 'base', centralChangeAuthorized: true);
        self::assertFalse($report->isValid());
        self::assertArrayHasKey('@directory', $report->errors);
    }

    #[Test]
    public function missingGitContextDoesNotDiscardExistingSchemaDiagnostics(): void
    {
        $validator = $this->prophesize(ChangesetValidatorInterface::class);
        $validator->validate('/repo/.changelog', false, false)->willReturn(new ValidationReport([], ['old.md' => ['bad metadata']], false))->shouldBeCalledOnce();
        $validator->validatePaths(Argument::any(), Argument::any(), Argument::any(), Argument::any())->shouldNotBeCalled();
        $git = $this->prophesize(GitRepositoryInterface::class);
        $git->changesSince('/repo', 'missing')->willThrow(new RuntimeException('revision missing'))->shouldBeCalledOnce();
        $report = $this->checker($validator, $git)->check(new ReleaseOptions('/repo'), 'missing');
        self::assertFalse($report->isValid());
        self::assertSame(['bad metadata'], $report->errors['old.md']);
        self::assertStringContainsString('revision missing', $report->errors['@git'][0]);
    }

    /** Supplies a valid empty inventory and a valid empty selected-path report. */
    private function emptyValidator(): ObjectProphecy
    {
        $validator = $this->prophesize(ChangesetValidatorInterface::class);
        $validator->validate('/repo/.changelog', false, false)->willReturn(new ValidationReport([], [], false))->shouldBeCalledOnce();
        $validator->validatePaths('/repo/.changelog', [], false, false)->willReturn(new ValidationReport([], [], false))->shouldBeCalledOnce();
        return $validator;
    }

    /** Composes the real checker from mocked boundaries and preserves report arguments. */
    private function checker(ObjectProphecy $validator, ObjectProphecy $git): CheckService
    {
        $reports = $this->prophesize(ValidationReportFactoryInterface::class);
        $reports->create(Argument::type('array'), Argument::type('array'), Argument::type('bool'), Argument::type('array'))
            ->will(static fn(array $arguments): ValidationReport => new ValidationReport(...$arguments))->shouldBeCalledOnce();
        return new CheckService($validator->reveal(), $git->reveal(), $reports->reveal());
    }
}
