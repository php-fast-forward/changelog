<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release;

use DateTimeImmutable;
use DateTimeZone;
use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\VersionImpact;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryCodecInterface;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\History\Import\HistoryImporterInterface;
use FastForward\Changelog\History\Import\HistoryImportResult;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\Factory\ReleasePlanFactoryInterface;
use FastForward\Changelog\Release\ReceiptCodecInterface;
use FastForward\Changelog\Release\ReleaseNotesRendererInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;
use FastForward\Changelog\Release\ReleasePlanner;
use FastForward\Changelog\Release\ReleaseReceipt;
use FastForward\Changelog\Template\TemplateInterface;
use FastForward\Changelog\Template\TemplateResolverInterface;
use FastForward\Changelog\Validation\ValidationReport;
use FastForward\Changelog\Validator\ChangesetValidatorInterface;
use FastForward\Changelog\Version\NextVersionResolverInterface;
use FastForward\Changelog\Version\VersionResolution;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

#[CoversClass(ReleasePlanner::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(ReleasePlan::class)]
#[UsesClass(ReleaseReceipt::class)]
#[UsesClass(HistoryDocument::class)]
#[UsesClass(HistoryRelease::class)]
#[UsesClass(HistoryImportResult::class)]
#[UsesClass(ValidationReport::class)]
#[UsesClass(VersionResolution::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(Category::class)]
final class ReleasePlannerTest extends TestCase
{
    private const string SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Status and version get the same immutable transaction and exact extracted notes. */
    public function testPlanningCapturesValidatedHashesAndNormalizesTheInjectedClockToUtc(): void
    {
        $unreleased = new HistoryRelease('unreleased', body: 'Legacy pending text');
        $older = new HistoryRelease('1.0.0', body: 'Rich history');
        $document = new HistoryDocument([$unreleased, $older]);
        [$planner, $parts] = $this->planner(['document' => $document, 'fragments' => true, 'releaseMock' => true, 'historyMock' => true]);
        $parts['releases']->expects(self::once())->method('create')->with('1.0.1', '2026-10-04', 'release-plan', 'Rendered fragments')->willReturn(new HistoryRelease('1.0.1', body: 'Rendered fragments'));
        $parts['history']->expects(self::once())->method('render')->willReturnCallback(static function (HistoryDocument $actual, TemplateInterface $template, bool $preserve): string {
            self::assertTrue($preserve);
            self::assertSame(['unreleased', '1.0.1', '1.0.0'], array_map(static fn(HistoryRelease $release): string => $release->getVersion(), $actual->getReleases()));
            self::assertSame('Legacy pending text', $actual->getReleases()[0]->getBody());
            self::assertSame('Rich history', $actual->getReleases()[2]->getBody());
            return 'after';
        });
        $parts['plans']->expects(self::once())->method('create')->willReturnCallback(function (...$arguments) use ($parts): ReleasePlan {
            self::assertSame(self::SHA, $arguments[1]);
            self::assertSame('1.0.0', $arguments[2]);
            self::assertSame('1.0.1', $arguments[3]);
            self::assertSame('patch', $arguments[4]);
            self::assertSame(['/consumer/.changelog/new.md' => hash('sha256', 'accepted bytes')], $arguments[5]);
            self::assertSame('after', $arguments[9]);
            self::assertSame("Exact notes\n", $arguments[10]);
            return $parts['plan'];
        });
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** Reformatting operates on existing history without reading fragments or fetching releases. */
    public function testFormatDoesNotImportOrConsumeFragments(): void
    {
        [$planner, $parts] = $this->planner(['importerMock' => true, 'validatorMock' => true, 'historyMock' => true]);
        $parts['importer']->expects(self::never())->method('import');
        $parts['validator']->expects(self::never())->method('validate');
        $parts['history']->expects(self::once())->method('render')->with($parts['document'], $parts['template'], false)->willReturn('translated');
        $parts['plans']->expects(self::once())->method('create')->willReturnCallback(function (...$arguments) use ($parts): ReleasePlan {
            self::assertNull($arguments[3]);
            self::assertSame([], $arguments[5]);
            self::assertSame('translated', $arguments[9]);
            return $parts['plan'];
        });
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer'), 'format'));
    }

    /** Backfill adds only missing history and cannot turn pending changes into a release. */
    public function testHistoricalOnlyMaintenanceDoesNotResolveSemverOrObserveTheClock(): void
    {
        [$planner, $parts] = $this->planner(['missing' => ['0.1.0'], 'validatorMock' => true, 'versionsMock' => true, 'clockMock' => true, 'historyMock' => true]);
        $parts['validator']->expects(self::never())->method('validate');
        $parts['versions']->expects(self::never())->method('resolve');
        $parts['clock']->expects(self::never())->method('now');
        $parts['history']->expects(self::once())->method('render')->with($parts['document'], $parts['template'], true)->willReturn('imported');
        $parts['plans']->expects(self::once())->method('create')->willReturnCallback(function (...$arguments) use ($parts): ReleasePlan {
            self::assertNull($arguments[3]);
            self::assertSame([], $arguments[5]);
            self::assertSame(['0.1.0'], $arguments[6]);
            return $parts['plan'];
        });
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer'), 'backfill'));
    }

    /** A project with no tags or changes MUST NOT acquire an empty generated document. */
    #[TestWith([null])]
    #[TestWith(['before'])]
    public function testNoChangePreservesExactOriginalBytes(?string $original): void
    {
        [$planner, $parts] = $this->planner(['original' => $original, 'repository' => false, 'historyMock' => true]);
        $parts['history']->expects(self::never())->method('render');
        $parts['plans']->expects(self::once())->method('create')->willReturnCallback(function (...$arguments) use ($parts, $original): ReleasePlan {
            self::assertNull($arguments[1]);
            self::assertSame($original ?? '', $arguments[9]);
            return $parts['plan'];
        });
        $planner->plan(new ReleaseOptions('/consumer'));
    }

    /** Partial application restores the saved identity instead of calculating a second increment. */
    public function testUnpublishedPlanResumesExactEvidence(): void
    {
        $receipt = $this->receipt();
        [$planner, $parts] = $this->planner(['receipt' => $receipt, 'versionsMock' => true, 'importerMock' => true]);
        $parts['versions']->expects(self::never())->method('resolve');
        $parts['importer']->expects(self::never())->method('import');
        $parts['plans']->expects(self::once())->method('resume')->with(self::isInstanceOf(ReleaseOptions::class), $receipt, '/consumer/CHANGELOG.md', 'before', '/consumer/.changelog/release-plan.json', 'receipt')->willReturn($parts['plan']);
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** Published receipt evidence does not prevent the next independent release calculation. */
    public function testPublishedReceiptAllowsANewPlan(): void
    {
        [$planner, $parts] = $this->planner(['receipt' => $this->receipt(), 'tags' => [['name' => 'v1.0.1', 'sha' => self::SHA]]]);
        $parts['plans']->expects(self::never())->method('resume');
        $parts['plans']->expects(self::once())->method('create')->willReturn($parts['plan']);
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** Unrelated branch tags cannot select the current version or enter historical import. */
    public function testOnlyAncestorTagsReachTheVersionAndImportAuthorities(): void
    {
        $reachable = ['name' => 'v1.0.0', 'sha' => self::SHA, 'date' => null, 'date_source' => null];
        $unrelated = ['name' => 'v9.0.0', 'sha' => str_repeat('9', 40), 'date' => null, 'date_source' => null];
        [$planner, $parts] = $this->planner(['tags' => [$unrelated, $reachable], 'importerMock' => true]);
        $parts['importer']->expects(self::once())->method('currentVersion')->with([$reachable], 'v')->willReturn('1.0.0');
        $parts['importer']->expects(self::once())->method('import')->willReturnCallback(static function (HistoryDocument $document, ...$arguments) use ($reachable): HistoryImportResult {
            self::assertContains([$reachable], $arguments);
            return new HistoryImportResult($document, [], '1.0.0');
        });
        $parts['plans']->expects(self::once())->method('create')->willReturn($parts['plan']);
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** A matching version on another branch does not prove that this saved plan was published. */
    public function testUnrelatedTagCannotMarkAnUnpublishedReceiptAsPublished(): void
    {
        [$planner, $parts] = $this->planner(['receipt' => $this->receipt(), 'tags' => [['name' => 'v1.0.1', 'sha' => str_repeat('9', 40)]]]);
        $parts['plans']->expects(self::once())->method('resume')->willReturn($parts['plan']);
        $parts['plans']->expects(self::never())->method('create');
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** An explicit base MUST identify the inspected checkout, not a different branch. */
    public function testDifferentBaseFailsBeforeConsolidation(): void
    {
        [$planner, $parts] = $this->planner(['baseMismatch' => true]);
        $parts['plans']->expects(self::never())->method('create');
        $this->expectExceptionMessage('Check out the selected base');
        $planner->plan(new ReleaseOptions('/consumer'));
    }

    /** Malformed or incompletely evidenced inventory is rejected before applying anything. */
    #[TestWith(['invalid'])]
    #[TestWith(['hashless'])]
    #[TestWith(['semver'])]
    #[TestWith(['duplicate'])]
    public function testReleasePlanningFailuresAreDiagnostic(string $failure): void
    {
        $document = 'duplicate' === $failure ? new HistoryDocument([new HistoryRelease('1.0.1')]) : new HistoryDocument();
        [$planner, $parts] = $this->planner(['fragments' => true, 'failure' => $failure, 'document' => $document]);
        $parts['plans']->expects(self::never())->method('create');
        $this->expectException('duplicate' === $failure || 'hashless' === $failure ? RuntimeException::class : InvalidArgumentException::class);
        $planner->plan(new ReleaseOptions('/consumer'));
    }

    /** Saved plans cannot consume later fragments or silently change presentation settings. */
    #[TestWith(['settings'])]
    #[TestWith(['document'])]
    #[TestWith(['notes'])]
    #[TestWith(['new'])]
    #[TestWith(['changed'])]
    #[TestWith(['absent'])]
    #[TestWith(['maintenance'])]
    public function testInconsistentPendingPlansFailClosed(string $failure): void
    {
        $receipt = $this->receipt();
        $data = $receipt->data;
        if ('settings' === $failure) {
            $data['locale'] = 'pt-BR';
        } elseif ('document' === $failure) {
            $data['after_changelog_sha256'] = hash('sha256', 'changed');
        } elseif ('notes' === $failure) {
            $data['notes_sha256'] = hash('sha256', 'changed');
        } elseif ('changed' === $failure) {
            $data['consumed']['.changelog/new.md'] = hash('sha256', 'older');
        }
        [$planner, $parts] = $this->planner(['receipt' => new ReleaseReceipt($data), 'fragments' => in_array($failure, ['new', 'changed'], true), 'original' => 'absent' === $failure ? null : 'before']);
        $parts['plans']->expects(self::never())->method('resume');
        $this->expectException(RuntimeException::class);
        $planner->plan(new ReleaseOptions('/consumer'), 'maintenance' === $failure ? 'format' : 'version');
    }

    /** A valid remaining consumed fragment is recoverable after sibling removal. */
    public function testRemainingApprovedFragmentCanResume(): void
    {
        $data = $this->receipt()->data;
        $data['consumed']['.changelog/new.md'] = hash('sha256', 'accepted bytes');
        [$planner, $parts] = $this->planner(['receipt' => new ReleaseReceipt($data), 'fragments' => true]);
        $parts['plans']->expects(self::once())->method('resume')->willReturn($parts['plan']);
        $planner->plan(new ReleaseOptions('/consumer'));
    }

    /** A saved journal survives failure before writing a previously absent central document. */
    #[TestWith([null])]
    #[TestWith(['original'])]
    public function testPreparedJournalRestoresTheSavedOutput(?string $original): void
    {
        $data = $this->receipt()->data;
        $data['before_changelog_sha256'] = null === $original ? null : hash('sha256', $original);
        $data['consumed'] = ['.changelog/new.md' => hash('sha256', 'accepted bytes')];
        $receipt = new ReleaseReceipt($data);
        [$planner, $parts] = $this->planner(['receipt' => $receipt, 'original' => $original, 'fragments' => true, 'clockMock' => true]);
        $parts['clock']->expects(self::never())->method('now');
        $parts['plans']->expects(self::once())->method('resume')->with(self::isInstanceOf(ReleaseOptions::class), $receipt, '/consumer/CHANGELOG.md', $original, '/consumer/.changelog/release-plan.json', 'receipt')->willReturn($parts['plan']);
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** Missing approved input before the central write prevents an incomplete recovered release. */
    public function testPreparedJournalRequiresAllConsumedFragments(): void
    {
        $data = $this->receipt()->data;
        $data['consumed'] = ['.changelog/new.md' => hash('sha256', 'accepted bytes')];
        [$planner, $parts] = $this->planner(['receipt' => new ReleaseReceipt($data), 'original' => 'original']);
        $parts['plans']->expects(self::never())->method('resume');
        $this->expectExceptionMessage('All approved fragments must remain available');
        $planner->plan(new ReleaseOptions('/consumer'));
    }

    /** A prepared maintenance journal cannot inspect, reject or consume pending fragments. */
    #[TestWith(['version'])]
    #[TestWith(['backfill'])]
    #[TestWith(['format'])]
    public function testPreparedMaintenanceRecoversWithoutInspectingPendingInventory(string $operation): void
    {
        $data = $this->receipt()->data;
        $data['next_version'] = null;
        $data['notes_sha256'] = hash('sha256', '');
        [$planner, $parts] = $this->planner(['receipt' => new ReleaseReceipt($data), 'original' => 'original', 'failure' => 'invalid', 'fragments' => true, 'validatorMock' => true]);
        $parts['validator']->expects(self::never())->method('validate');
        $parts['plans']->expects(self::once())->method('resume')->willReturn($parts['plan']);
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer'), $operation));
    }

    /** Invalid operation names cannot enter filesystem or Git boundaries. */
    public function testUnknownOperationIsRejected(): void
    {
        [$planner, $parts] = $this->planner();
        $parts['files']->expects(self::never())->method('read');
        $parts['plans']->expects(self::never())->method('create');
        $this->expectException(InvalidArgumentException::class);
        $planner->plan(new ReleaseOptions('/consumer'), 'guess');
    }

    /** Builds explicit saved evidence for recovery-policy unit tests. */
    private function receipt(): ReleaseReceipt
    {
        return new ReleaseReceipt(['next_version' => '1.0.1', 'changelog_file' => 'CHANGELOG.md',
            'fragment_directory' => '.changelog', 'locale' => 'en', 'template' => 'keep-a-changelog',
            'tag_prefix' => 'v', 'repository' => null, 'consumed' => [],
            'before_changelog_sha256' => hash('sha256', 'original'), 'changelog_contents' => 'before', 'after_changelog_sha256' => hash('sha256', 'before'), 'notes_sha256' => hash('sha256', "Exact notes\n")]);
    }

    /** All external collaborators are doubles; clock/date values are fixed input data. */
    private function planner(array $settings = []): array
    {
        $options = new ReleaseOptions('/consumer');
        $document = $settings['document'] ?? new HistoryDocument();
        $template = $this->createStub(TemplateInterface::class);
        $plan = new ReleasePlan($options, 'id', self::SHA, '1.0.0', null, null, [], [], '/consumer/CHANGELOG.md', 'before', 'after', '', '/consumer/.changelog/release-plan.json', null, 'receipt');
        $paths = $this->createStub(PackagePathResolverInterface::class);
        $paths->method('absolutePath')->willReturnCallback(static fn(string $path): string => '/consumer/' . $path);
        $paths->method('relativePath')->willReturnCallback(static fn(string $path): string => substr($path, strlen('/consumer/')));
        $files = $this->createMock(ManagedFileStoreInterface::class);
        $original = array_key_exists('original', $settings) ? $settings['original'] : 'before';
        $files->method('read')->willReturnMap([['/consumer/CHANGELOG.md', $original], ['/consumer/.changelog/release-plan.json', isset($settings['receipt']) ? 'receipt' : null]]);
        $files->expects(self::never())->method('write');
        $git = $this->createStub(GitRepositoryInterface::class);
        $git->method('isRepository')->willReturn($settings['repository'] ?? true);
        $git->method('resolveRef')->willReturnOnConsecutiveCalls(self::SHA, ($settings['baseMismatch'] ?? false) ? str_repeat('b', 40) : self::SHA);
        $git->method('tags')->willReturn($settings['tags'] ?? []);
        $git->method('isAncestor')->willReturnCallback(static fn(string $directory, string $ancestor): bool => $ancestor !== str_repeat('9', 40));
        $history = ($settings['historyMock'] ?? false) ? $this->createMock(HistoryCodecInterface::class) : $this->createStub(HistoryCodecInterface::class);
        $history->method('parse')->willReturn($document);
        $history->method('notes')->willReturn("Exact notes\n");
        $importer = ($settings['importerMock'] ?? false) ? $this->createMock(HistoryImporterInterface::class) : $this->createStub(HistoryImporterInterface::class);
        $importer->method('currentVersion')->willReturn('1.0.0');
        $importer->method('import')->willReturn(new HistoryImportResult($document, $settings['missing'] ?? [], '1.0.0'));
        $templates = $this->createStub(TemplateResolverInterface::class);
        $templates->method('resolve')->willReturn($template);
        $change = new Changeset('new.md', Category::Fixed, null, null, null, 'Description');
        $fragments = ($settings['fragments'] ?? false) ? [$change] : [];
        $hashes = [] === $fragments || 'hashless' === ($settings['failure'] ?? '') ? [] : ['/consumer/.changelog/new.md' => hash('sha256', 'accepted bytes')];
        $validator = ($settings['validatorMock'] ?? false) ? $this->createMock(ChangesetValidatorInterface::class) : $this->createStub(ChangesetValidatorInterface::class);
        $validator->method('validate')->willReturn(new ValidationReport($fragments, 'invalid' === ($settings['failure'] ?? '') ? ['new.md' => ['bad category']] : [], false, $hashes));
        $versions = ($settings['versionsMock'] ?? false) ? $this->createMock(NextVersionResolverInterface::class) : $this->createStub(NextVersionResolverInterface::class);
        $versions->method('resolve')->willReturn('semver' === ($settings['failure'] ?? '') ? new VersionResolution(null, null, ['invalid tag']) : new VersionResolution('1.0.1', VersionImpact::Patch, []));
        $renderer = $this->createStub(ReleaseNotesRendererInterface::class);
        $renderer->method('render')->willReturn('Rendered fragments');
        $releases = ($settings['releaseMock'] ?? false) ? $this->createMock(HistoryReleaseFactoryInterface::class) : $this->createStub(HistoryReleaseFactoryInterface::class);
        $clock = ($settings['clockMock'] ?? false) ? $this->createMock(ClockInterface::class) : $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-10-03T23:30:00-03:00'));
        $receipts = $this->createStub(ReceiptCodecInterface::class);
        $receipts->method('decode')->willReturn($settings['receipt'] ?? $this->receipt());
        $plans = $this->createMock(ReleasePlanFactoryInterface::class);
        $exceptions = $this->createStub(ReleaseExceptionFactoryInterface::class);
        $exceptions->method('invalid')->willReturnCallback(static fn(string $message): InvalidArgumentException => new InvalidArgumentException($message));
        $exceptions->method('failure')->willReturnCallback(static fn(string $message): RuntimeException => new RuntimeException($message));
        return [new ReleasePlanner(
            $paths,
            $files,
            $git,
            $history,
            $importer,
            $templates,
            $validator,
            $versions,
            $renderer,
            $releases,
            $clock,
            new DateTimeZone('UTC'),
            $receipts,
            $plans,
            $exceptions,
        ),
            compact('document', 'template', 'plan', 'files', 'git', 'history', 'importer', 'validator', 'versions', 'releases', 'clock', 'plans')];
    }
}
