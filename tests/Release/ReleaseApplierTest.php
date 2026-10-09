<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release;

use FastForward\Changelog\Changeset\Store\ChangesetStoreInterface;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\ReceiptCodecInterface;
use FastForward\Changelog\Release\ReleaseApplier;
use FastForward\Changelog\Release\ReleaseJournalPathResolverInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;
use FastForward\Changelog\Release\ReleaseReceipt;
use FastForward\Changelog\Validator\ReleaseInputEvidenceValidatorInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Throwable;

#[CoversClass(ReleaseApplier::class)]
#[UsesClass(ReleasePlan::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(ReleaseReceipt::class)]
final class ReleaseApplierTest extends TestCase
{
    use ReceiptFixtureTrait;

    /** The full set and every hash MUST be checked before central/receipt writes and scoped removals. */
    public function testFreshApplicationWritesBothOutputsBeforeRemovingExactApprovedPaths(): void
    {
        $plan = $this->plan();
        $state = $this->state($plan);
        $applier = $this->applier($plan, $state);
        self::assertTrue($applier->apply($plan));
        self::assertSame([
            ['write', '/consumer/.git/changelog-release-plan.json'],
            ['write', '/consumer/CHANGELOG.md'],
            ['remove', ['/consumer/.changelog/a.md', '/consumer/.changelog/b.md']],
        ], $state['operations']);
        self::assertSame('after', $state['central']);
        self::assertNull($state['receipt']);
        self::assertSame(1, $state['journal_removals']);
        self::assertSame([], $state['fragments']);
        self::assertSame(2, $state['inventory_reads']);
        self::assertSame(4, $state['fragment_reads']);
        self::assertSame(1, $state['releases']);
        self::assertSame(2, $state['head_reads']);
    }

    /** Read-only checks and repeated apply MUST perform no file writes/removals. */
    public function testAlreadyAppliedPlanIsIdempotentAndCheckConstructsNoLock(): void
    {
        $plan = $this->plan();
        $state = $this->state($plan, ['central' => 'after', 'receipt' => $plan->receiptContents, 'fragments' => []]);
        $applier = $this->applier($plan, $state);
        self::assertTrue($applier->isApplied($plan));
        self::assertSame(0, $state['lock_constructions']);
        self::assertSame([], $state['operations']);
        self::assertFalse($applier->apply($plan));
        self::assertSame([], $state['operations']);
        self::assertSame(1, $state['releases']);
    }

    /** A pending check MUST return false without a lock, managed mutation or Git mutation. */
    public function testPendingCheckReadsEvidenceWithoutAnyMutation(): void
    {
        $plan = $this->plan();
        $state = $this->state($plan);
        self::assertFalse($this->applier($plan, $state)->isApplied($plan));
        self::assertSame(0, $state['lock_constructions']);
        self::assertSame([], $state['operations']);
        self::assertSame(0, $state['input_evidence_checks']);
    }

    /** A source-proof failure aborts before the recovery journal, central output or fragment removals. */
    public function testUncommittedReleaseInputsCannotReachTheFirstWrite(): void
    {
        $plan = $this->plan();
        $state = $this->state($plan, ['input_evidence_failure' => true]);
        try {
            $this->applier($plan, $state)->apply($plan);
            self::fail('Uncommitted release inputs must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('differs from the approved base', $error->getMessage());
        }
        self::assertSame([], $state['operations']);
        self::assertSame('before', $state['central']);
        self::assertNull($state['receipt']);
        self::assertSame([
            '/consumer/.changelog/a.md' => 'alpha', '/consumer/.changelog/b.md' => 'beta'],
            $state['fragments'],
        );
        self::assertSame(1, $state['input_evidence_checks']);
        self::assertSame(1, $state['releases']);
    }

    /** An empty plan MUST be a no-op and MUST not touch I/O, a lock or Git. */
    public function testNoChangePlanDoesNotConsultCollaborators(): void
    {
        $plan = $this->plan(
            ['nextVersion' => null, 'impact' => null, 'historicalVersions' => [], 'originalChangelog' => 'same', 'changelogContents' => 'same'],
        );
        $state = $this->state($plan);
        $applier = $this->applier($plan, $state);
        self::assertFalse($applier->apply($plan));
        self::assertTrue($applier->isApplied($plan));
        self::assertSame(0, $state['lock_constructions']);
        self::assertSame(0, $state['inventory_reads']);
        self::assertSame(0, $state['codec_reads']);
        self::assertSame([], $state['operations']);
    }

    /** Lock refusal MUST fail before the first managed read and cannot release an unowned lock. */
    public function testUnavailableLockFailsBeforeAnyFileAccess(): void
    {
        $plan = $this->plan();
        $state = $this->state($plan, ['acquired' => false]);
        try {
            $this->applier($plan, $state)->apply($plan);
            self::fail('Lock refusal must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Cannot acquire release lock', $error->getMessage());
        }
        self::assertSame(0, $state['codec_reads']);
        self::assertSame(0, $state['managed_reads']);
        self::assertSame(0, $state['releases']);
        self::assertSame([], $state['operations']);
    }

    /** Every changed input MUST abort the full transaction before any write or removal. */
    #[DataProvider('changedInputs')]
    public function testChangedInputsFailBeforeMutation(array $changes, string $diagnostic): void
    {
        $plan = $this->plan();
        $state = $this->state($plan, $changes);
        try {
            $this->applier($plan, $state)->apply($plan);
            self::fail('Changed input must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString($diagnostic, $error->getMessage());
            self::assertStringContainsString('retry this exact approved plan', $error->getMessage());
            self::assertNotNull($error->getPrevious());
        }
        self::assertSame([], $state['operations']);
        self::assertSame(1, $state['releases']);
    }

    /** Supplies moved HEAD, changed bytes, new/missing fragment paths and unsafe discovery. */
    public static function changedInputs(): iterable
    {
        yield [['head' => str_repeat('b', 40)], 'HEAD'];
        yield [['central' => 'manual edit'], 'Central changelog changed'];
        yield [['receipt' => 'manual edit'], 'Release receipt changed'];
        yield [['fragments' => ['/consumer/.changelog/a.md' => 'alpha']], 'Approved fragments are missing'];
        yield [['fragments' => ['/consumer/.changelog/a.md' => 'alpha', '/consumer/.changelog/b.md' => 'beta', '/consumer/.changelog/new.md' => 'new']], 'unapproved fragments'];
        yield [['unsafe_directory' => true], 'Unsafe fragment directory'];
        yield [['fragments' => ['/consumer/.changelog/a.md' => 'edited', '/consumer/.changelog/b.md' => 'beta']], 'fragment is unsafe or changed'];
        yield [['fragments' => ['/consumer/.changelog/a.md' => null, '/consumer/.changelog/b.md' => 'beta']], 'fragment is unsafe or changed'];
    }

    /** Receipt/plan mismatches and path substitutions MUST fail before managed reads. */
    #[DataProvider('tamperedPlans')]
    public function testTamperedPlanCannotUseValidReceiptToAuthorizeDifferentMutation(array $changes): void
    {
        $approved = $this->plan();
        $plan = $this->plan($changes);
        $state = $this->state($approved);
        try {
            $this->applier($approved, $state)->apply($plan);
            self::fail('Tampered plan must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('approved receipt', $error->getMessage());
        }
        self::assertSame(0, $state['managed_reads']);
        self::assertSame([], $state['operations']);
        self::assertSame(1, $state['releases']);
    }

    /** Covers immutable fields, before/after hashes and both managed output paths. */
    public static function tamperedPlans(): iterable
    {
        yield [['id' => 'other']];
        yield [['baseSha' => null]];
        yield [['currentVersion' => '9.0.0']];
        yield [['nextVersion' => '9.0.1']];
        yield [['impact' => 'major']];
        yield [['historicalVersions' => []]];
        yield [['notes' => 'changed']];
        yield [['consumed' => []]];
        yield [['changelogContents' => 'altered']];
        yield [['originalChangelog' => null]];
        yield [['changelogPath' => '/outside/CHANGELOG.md']];
        yield [['receiptPath' => '/outside/receipt.json']];
        yield [['options' => new ReleaseOptions('/consumer', locale: 'pt-BR')]];
    }

    /** A central write failure MUST preserve the recovery journal, both fragments and release the lock. */
    public function testCentralWriteFailureKeepsRecoverableJournalAndEveryFragment(): void
    {
        $plan = $this->plan();
        $state = $this->state($plan, ['fail_write' => $plan->changelogPath]);
        $applier = $this->applier($plan, $state);
        try {
            $applier->apply($plan);
            self::fail('Write failure must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('disk failure', $error->getMessage());
        }
        self::assertSame('before', $state['central']);
        self::assertSame($plan->receiptContents, $state['receipt']);
        self::assertCount(2, $state['fragments']);
        self::assertSame([['write', $plan->receiptPath]], $state['operations']);
        self::assertSame(1, $state['releases']);
    }

    /** A failed journal write MUST preserve original central bytes and retry the same approved transaction. */
    public function testJournalFailurePreservesCentralAndRetryWritesBothApprovedOutputs(): void
    {
        $plan = $this->plan();
        $state = $this->state($plan, ['fail_write' => $plan->receiptPath]);
        $applier = $this->applier($plan, $state);
        try {
            $applier->apply($plan);
            self::fail('Receipt write failure must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('disk failure', $error->getMessage());
        }
        self::assertSame('before', $state['central']);
        self::assertNull($state['receipt']);
        self::assertCount(2, $state['fragments']);
        self::assertSame([], $state['operations']);
        $state['fail_write'] = null;
        self::assertTrue($applier->apply($plan));
        self::assertSame(
            [['write', $plan->receiptPath], ['write', $plan->changelogPath], ['remove', array_keys($plan->consumed)]],
            $state['operations'],
        );
        self::assertSame([], $state['fragments']);
        self::assertSame(2, $state['releases']);
    }

    /** A removal interruption MUST preserve receipt evidence and safely consume only remaining unchanged files on retry. */
    public function testPartialRemovalIsRecoverableAndAlreadyAppliedRetryIsNoOp(): void
    {
        $plan = $this->plan();
        $state = $this->state($plan, ['fail_remove' => true]);
        $applier = $this->applier($plan, $state);
        try {
            $applier->apply($plan);
            self::fail('Removal interruption must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('removal interruption', $error->getMessage());
        }
        self::assertSame('after', $state['central']);
        self::assertSame($plan->receiptContents, $state['receipt']);
        self::assertSame(['/consumer/.changelog/b.md' => 'beta'], $state['fragments']);
        $state['fail_remove'] = false;
        self::assertTrue($applier->apply($plan));
        self::assertSame(['remove', ['/consumer/.changelog/b.md']], end($state['operations']));
        $operations = $state['operations'];
        self::assertFalse($applier->apply($plan));
        self::assertSame($operations, $state['operations']);
        self::assertSame(3, $state['releases']);
    }

    /** Failure to remove a completed local journal can be retried without recreating history or consuming another set. */
    public function testCompletedJournalCleanupFailureRetriesWithoutAnotherRelease(): void
    {
        $plan = $this->plan();
        $state = $this->state($plan, ['fail_journal_cleanup' => true]);
        $applier = $this->applier($plan, $state);
        try {
            $applier->apply($plan);
            self::fail('Interrupted journal cleanup must be reported.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('journal cleanup interruption', $error->getMessage());
        }
        self::assertSame('after', $state['central']);
        self::assertSame([], $state['fragments']);
        self::assertSame($plan->receiptContents, $state['receipt']);
        $operations = $state['operations'];
        $state['fail_journal_cleanup'] = false;
        self::assertFalse($applier->apply($plan));
        self::assertSame($operations, $state['operations']);
        self::assertNull($state['receipt']);
        self::assertSame(1, $state['journal_removals']);
    }

    /** An external writer racing either durable write MUST not make an unknown/modified fragment removable. */
    #[DataProvider('writeRaces')]
    public function testPostWriteInventoryAndHashRecheckRejectsRacingInputs(array $racing): void
    {
        $plan = $this->plan();
        $state = $this->state($plan, ['after_receipt' => $racing]);
        try {
            $this->applier($plan, $state)->apply($plan);
            self::fail('Post-write race must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Cannot apply release', $error->getMessage());
        }
        self::assertSame([['write', $plan->receiptPath], ['write', $plan->changelogPath]], $state['operations']);
        foreach ($racing as $path => $body) {
            self::assertSame($body, $state['fragments'][$path]);
        }
        self::assertSame(1, $state['releases']);
    }

    /** Supplies one newly created file and one changed approved file. */
    public static function writeRaces(): iterable
    {
        yield [['/consumer/.changelog/new.md' => 'new']];
        yield [['/consumer/.changelog/a.md' => 'changed']];
    }

    /** A resumed plan can outlive a scoped release commit if its original base remains an ancestor. */
    public function testResumingUsesBaseAncestryAndCompleteApprovedConsumedSet(): void
    {
        $plan = $this->plan(
            ['resuming' => true, 'originalChangelog' => 'after', 'originalReceipt' => 'approved receipt'],
        );
        $state = $this->state(
            $plan,
            ['central' => 'after', 'receipt' => 'approved receipt', 'head' => str_repeat(
                'b',
                40,
            ), 'fragments' => ['/consumer/.changelog/b.md' => 'beta']],
        );
        self::assertTrue($this->applier($plan, $state)->apply($plan));
        self::assertSame([['remove', ['/consumer/.changelog/b.md']]], $state['operations']);
        self::assertSame(0, $state['head_reads']);
        self::assertSame(2, $state['ancestor_reads']);
    }

    /** Resume MUST reject unrelated history, changed central or changed original receipt. */
    #[DataProvider('resumeFailures')]
    public function testResumeRequiresOriginalHistoryAndBothApprovedDurableOutputs(
        array $changes,
        string $diagnostic,
    ): void {
        $plan = $this->plan(
            ['resuming' => true, 'originalChangelog' => 'after', 'originalReceipt' => 'approved receipt'],
        );
        $state = $this->state($plan, array_replace(['central' => 'after', 'receipt' => 'approved receipt'], $changes));
        try {
            $this->applier($plan, $state)->apply($plan);
            self::fail('Invalid resume must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString($diagnostic, $error->getMessage());
        }
        self::assertSame([], $state['operations']);
        self::assertSame(1, $state['releases']);
    }

    /** Supplies each missing prerequisite for a trusted receipt resume. */
    public static function resumeFailures(): iterable
    {
        yield [['ancestor' => false], 'HEAD'];
        yield [['central' => 'manual edit'], 'Central changelog changed'];
        yield [['receipt' => 'manual edit'], 'Release receipt changed'];
    }

    /** Historical maintenance without a Git repository MUST write both outputs without removing anything. */
    public function testMaintenanceAndPreviouslyAbsentCentralHaveNoGitOrFragmentMutation(): void
    {
        $plan = $this->plan(
            ['baseSha' => null, 'nextVersion' => null, 'impact' => null, 'consumed' => [], 'originalChangelog' => null],
        );
        $state = $this->state(
            $plan,
            ['central' => null, 'fragments' => ['/consumer/.changelog/.invalid.md' => 'invalid pending']],
        );
        self::assertTrue($this->applier($plan, $state)->apply($plan));
        self::assertSame([['write', $plan->receiptPath], ['write', $plan->changelogPath]], $state['operations']);
        self::assertSame(0, $state['head_reads']);
        self::assertSame(0, $state['ancestor_reads']);
        self::assertSame(0, $state['inventory_reads']);
        self::assertSame(0, $state['fragment_reads']);
        self::assertSame(['/consumer/.changelog/.invalid.md' => 'invalid pending'], $state['fragments']);
    }

    /** A new planning invocation can restore its prepared journal before the central write without bump/date recomputation. */
    public function testPreparedJournalResumesFromOriginalCentralWithoutRewritingReceipt(): void
    {
        $plan = $this->plan(['resuming' => true, 'originalReceipt' => 'approved receipt']);
        $state = $this->state($plan, ['receipt' => 'approved receipt']);
        self::assertTrue($this->applier($plan, $state)->apply($plan));
        self::assertSame(
            [['write', $plan->changelogPath], ['remove', array_keys($plan->consumed)]],
            $state['operations'],
        );
        self::assertSame(2, $state['ancestor_reads']);
        self::assertSame('after', $state['central']);
    }

    /** Prepared maintenance can resume an originally absent document while pending fragments remain untouched. */
    public function testPreparedMaintenanceResumesWithAbsentOriginalAndUntouchedInvalidPending(): void
    {
        $plan = $this->plan(['resuming' => true, 'baseSha' => null, 'nextVersion' => null, 'impact' => null,
            'consumed' => [], 'originalChangelog' => null, 'originalReceipt' => 'approved receipt']);
        $pending = ['/consumer/.changelog/.invalid.md' => 'invalid pending'];
        $state = $this->state($plan, ['central' => null, 'receipt' => 'approved receipt', 'fragments' => $pending]);
        self::assertTrue($this->applier($plan, $state)->apply($plan));
        self::assertSame([['write', $plan->changelogPath]], $state['operations']);
        self::assertSame($pending, $state['fragments']);
        self::assertSame(0, $state['inventory_reads']);
        self::assertSame(0, $state['fragment_reads']);
    }

    /** Provides immutable plan values; production collaborators are always replaced by test doubles. */
    private function plan(array $changes = []): ReleasePlan
    {
        return new ReleasePlan(...array_replace([
            'options' => new ReleaseOptions('/consumer'), 'id' => str_repeat('f', 64), 'baseSha' => str_repeat('a', 40),
            'currentVersion' => '1.0.0', 'nextVersion' => '1.0.1', 'impact' => 'patch',
            'consumed' => ['/consumer/.changelog/a.md' => hash('sha256', 'alpha'), '/consumer/.changelog/b.md' => hash(
                'sha256',
                'beta',
            )],
            'historicalVersions' => [
                '0.1.0',
            ], 'changelogPath' => '/consumer/CHANGELOG.md', 'originalChangelog' => 'before',
            'changelogContents' => 'after', 'notes' => "Exact  notes\n", 'receiptPath' => '/consumer/.git/changelog-release-plan.json',
            'originalReceipt' => null, 'receiptContents' => 'approved receipt', 'resuming' => false,
        ], $changes));
    }

    /** Represents an isolated deterministic filesystem and Git snapshot entirely in memory. */
    private function state(ReleasePlan $plan, array $changes = []): array
    {
        return array_replace([
            'central' => $plan->originalChangelog, 'receipt' => $plan->originalReceipt,
            'fragments' => ['/consumer/.changelog/a.md' => 'alpha', '/consumer/.changelog/b.md' => 'beta'],
            'head' => $plan->baseSha, 'ancestor' => true, 'acquired' => true,
            'operations' => [], 'releases' => 0, 'lock_constructions' => 0, 'inventory_reads' => 0,
            'fragment_reads' => 0, 'managed_reads' => 0, 'codec_reads' => 0, 'head_reads' => 0, 'ancestor_reads' => 0,
            'fail_write' => null, 'fail_remove' => false, 'unsafe_directory' => false, 'after_receipt' => [],
            'input_evidence_failure' => false, 'input_evidence_checks' => 0,
        ], $changes);
    }

    /** Mocks all boundaries and records every read, mutation, lock and ancestry check without native I/O. */
    private function applier(ReleasePlan $approved, array &$state): ReleaseApplier
    {
        $data = array_replace(self::evidence(), [
            'id' => $approved->id, 'base_sha' => $approved->baseSha, 'current_version' => $approved->currentVersion,
            'next_version' => $approved->nextVersion, 'impact' => $approved->impact, 'historical_versions' => $approved->historicalVersions,
            'consumed' => [], 'notes' => $approved->notes, 'notes_sha256' => hash('sha256', $approved->notes),
            'changelog_contents' => $approved->changelogContents,
            'after_changelog_sha256' => hash('sha256', $approved->changelogContents),
            'before_changelog_sha256' => ($approved->resuming && 'after' === $approved->originalChangelog) ? hash(
                'sha256',
                'before',
            ) : (null === $approved->originalChangelog ? null : hash(
                'sha256',
                $approved->originalChangelog,
            )),
        ]);
        foreach ($approved->consumed as $path => $hash) {
            $data['consumed'][substr($path, strlen('/consumer/'))] = $hash;
        }
        $codec = $this->createStub(ReceiptCodecInterface::class);
        $codec->method('decode')->willReturnCallback(static function (string $contents) use (
            $approved,
            $data,
            &$state
        ): ReleaseReceipt {
            self::assertSame($approved->receiptContents, $contents);
            ++$state['codec_reads'];

            return new ReleaseReceipt($data);
        });
        $files = $this->createStub(ManagedFileStoreInterface::class);
        $files->method('read')->willReturnCallback(static function (string $path) use ($approved, &$state): ?string {
            ++$state['managed_reads'];

            return $path === $approved->changelogPath ? $state['central'] : $state['receipt'];
        });
        $files->method('write')->willReturnCallback(static function (string $path, string $contents) use (
            $approved,
            &$state
        ): void {
            if ($path === $state['fail_write']) {
                throw new RuntimeException('disk failure at ' . $path);
            }
            $state['operations'][] = ['write', $path];
            $state[$path === $approved->changelogPath ? 'central' : 'receipt'] = $contents;
            if ($path === $approved->receiptPath) {
                $state['fragments'] = array_replace($state['fragments'], $state['after_receipt']);
            }
        });
        $files->method('remove')->willReturnCallback(static function (string $path) use ($approved, &$state): void {
            self::assertSame($approved->receiptPath, $path);
            if ($state['fail_journal_cleanup'] ?? false) {
                throw new RuntimeException('journal cleanup interruption');
            }
            if (null !== $state['receipt']) {
                $state['journal_removals'] = ($state['journal_removals'] ?? 0) + 1;
                $state['receipt'] = null;
            }
        });
        $fragments = $this->createStub(ChangesetStoreInterface::class);
        $fragments->method('lockResource')->willReturnCallback(static function (string $directory): string {
            self::assertSame('/consumer/.changelog', $directory);

            return 'shared directory resource';
        });
        $fragments->method('paths')->willReturnCallback(static function (string $directory) use (&$state): ?array {
            self::assertSame('/consumer/.changelog', $directory);
            ++$state['inventory_reads'];

            return $state['unsafe_directory'] ? null : array_keys($state['fragments']);
        });
        $fragments->method('read')->willReturnCallback(static function (string $path) use (&$state): ?string {
            ++$state['fragment_reads'];

            return $state['fragments'][$path];
        });
        $fragments->method('remove')->willReturnCallback(static function (array $paths) use (&$state): void {
            $state['operations'][] = ['remove', $paths];
            foreach ($paths as $path) {
                unset($state['fragments'][$path]);
                if ($state['fail_remove']) {
                    throw new RuntimeException('removal interruption');
                }
            }
        });
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->method('resolveRef')->willReturnCallback(static function (string $directory, string $reference) use (
            &$state
        ): string {
            self::assertSame('/consumer', $directory);
            self::assertSame('HEAD', $reference);
            ++$state['head_reads'];

            return $state['head'];
        });
        $git->method('isAncestor')->willReturnCallback(
            static function (string $directory, string $ancestor, string $descendant) use ($approved, &$state): bool {
                self::assertSame('/consumer', $directory);
                self::assertSame($approved->baseSha, $ancestor);
                self::assertSame('HEAD', $descendant);
                ++$state['ancestor_reads'];

                return $state['ancestor'];
            },
        );
        $git->expects(self::never())->method('commitFragment');
        $lock = $this->createStub(SharedLockInterface::class);
        $lock->method('acquire')->willReturnCallback(static function (bool $blocking) use (&$state): bool {
            self::assertTrue($blocking);

            return $state['acquired'];
        });
        $lock->method('release')->willReturnCallback(static function () use (&$state): void {
            ++$state['releases'];
        });
        $locks = $this->createStub(LockFactory::class);
        $locks->method('createLock')->willReturnCallback(static function (string $resource) use (
            $lock,
            &$state
        ): SharedLockInterface {
            self::assertSame('shared directory resource', $resource);
            ++$state['lock_constructions'];

            return $lock;
        });
        $exceptions = $this->createStub(ReleaseExceptionFactoryInterface::class);
        $exceptions->method('invalid')->willReturnCallback(
            static fn(string $message, ?Throwable $previous = null): InvalidArgumentException => new InvalidArgumentException(
                $message,
                previous: $previous,
            ),
        );
        $exceptions->method('failure')->willReturnCallback(
            static fn(string $message, ?Throwable $previous = null): RuntimeException => new RuntimeException(
                $message,
                previous: $previous,
            ),
        );
        $inputs = $this->createStub(ReleaseInputEvidenceValidatorInterface::class);
        $inputs->method('validate')->willReturnCallback(static function (ReleasePlan $received) use (
            $approved,
            &$state
        ): void {
            self::assertSame($approved, $received);
            ++$state['input_evidence_checks'];
            if ($state['input_evidence_failure']) {
                throw new RuntimeException('A release input differs from the approved base.');
            }
        });
        $journals = $this->createStub(ReleaseJournalPathResolverInterface::class);
        $journals->method('resolve')->willReturn('/consumer/.git/changelog-release-plan.json');

        return new ReleaseApplier($git, $fragments, $locks, $files, $codec, $exceptions, $inputs, $journals);
    }
}
