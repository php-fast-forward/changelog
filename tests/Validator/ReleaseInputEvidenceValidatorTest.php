<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Validator;

use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;
use FastForward\Changelog\Validator\ReleaseInputEvidenceValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ReleaseInputEvidenceValidator::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(ReleasePlan::class)]
final class ReleaseInputEvidenceValidatorTest extends TestCase
{
    private const string SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** The proof reads only managed inputs; non-Markdown entries and the root instruction file remain untouched. */
    public function testCompleteCommittedInputProofDoesNotInspectUnrelatedWorkOrTheIndex(): void
    {
        $state = [];
        $validator = $this->validator($state, ['extra_entries' => [
            ['path' => '.changelog/AGENTS.md', 'mode' => '100644'],
            ['path' => '.changelog/release-plan.json', 'mode' => '100644'],
            ['path' => '.changelog/cache.txt', 'mode' => '120000'],
        ]]);
        $validator->validate($this->plan());
        self::assertSame([
            ['blob', 'CHANGELOG.md'], ['tree', 'CHANGELOG.md'], ['tree', '.changelog'],
            ['blob', '.changelog/a.md'], ['blob', '.changelog/b.md'],
        ], $state['calls']);
    }

    /** A first release can start with no central changelog at the base. */
    public function testAbsentCentralAndExactUnicodeFragmentBytesAreAccepted(): void
    {
        $body = "  descrição\r\n\n";
        $state = [];
        $validator = $this->validator(
            $state,
            ['base_overrides' => ['CHANGELOG.md' => null, '.changelog/a.md' => $body]],
        );
        $validator->validate($this->plan(['originalChangelog' => null, 'consumed' => [
            '/consumer/.changelog/b.md' => hash('sha256', 'beta'), '/consumer/.changelog/a.md' => hash('sha256', $body),
        ]]));
        self::assertNotContains(['tree', 'CHANGELOG.md'], $state['calls']);
        self::assertContains(['blob', '.changelog/a.md'], $state['calls']);
    }

    /** Saved recovery, maintenance and non-Git consolidation use their own transaction contracts. */
    #[TestWith([['resuming' => true]])]
    #[TestWith([['baseSha' => null]])]
    #[TestWith([['nextVersion' => null]])]
    public function testPlansOutsideFreshGitReleaseProofDoNotReadInputs(array $changes): void
    {
        $state = [];
        $this->validator($state)->validate($this->plan($changes));
        self::assertSame([], $state['calls']);
    }

    /** Dirty central bytes, incomplete inventory and dirty/missing blobs fail before a mutation boundary is reached. */
    #[DataProvider('uncommittedInputs')]
    public function testInputsMustMatchTheEntireCommittedBase(array $changes, string $diagnostic): void
    {
        $state = [];
        $validator = $this->validator($state, $changes);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($diagnostic);
        $validator->validate($this->plan());
    }

    /** Models untracked/staged-only files, deleted inherited files and byte changes independently of repository dirt. */
    public static function uncommittedInputs(): array
    {
        return [
            [['base_overrides' => ['CHANGELOG.md' => 'older central']], 'Commit the central changelog'],
            [['base_overrides' => ['CHANGELOG.md' => null]], 'Commit the central changelog'],
            [['tree_overrides' => ['.changelog' => [['path' => '.changelog/a.md', 'mode' => '100644']]]], 'complete pending fragment set'],
            [['extra_entries' => [['path' => '.changelog/deleted.md', 'mode' => '100644']]], 'complete pending fragment set'],
            [['base_overrides' => ['.changelog/a.md' => 'older fragment']], 'accepted fragment bytes'],
            [['base_overrides' => ['.changelog/a.md' => null]], 'accepted fragment bytes'],
        ];
    }

    /** Nested, hidden, symbolic and noncanonical Markdown cannot be omitted from the committed inventory. */
    #[DataProvider('unsafeFragments')]
    public function testUnsafeBaseFragmentsAreRejectedEvenWhenAbsentFromThePlannedSelection(array $entry): void
    {
        $state = [];
        $validator = $this->validator($state, ['extra_entries' => [$entry]]);
        $this->expectExceptionMessage('unsafe or noncanonical fragment');
        $validator->validate($this->plan());
    }

    /** Supplies every committed path/mode family refused by publication evidence. */
    public static function unsafeFragments(): array
    {
        return [
            [['path' => '.changelog/nested/a.md', 'mode' => '100644']],
            [['path' => '.changelog/.hidden.md', 'mode' => '100644']],
            [['path' => '.changelog/README.md', 'mode' => '100644']],
            [['path' => 'outside/a.md', 'mode' => '100644']],
            [['path' => '.changelog/link.md', 'mode' => '120000']],
            [['path' => '.changelog/submodule.md', 'mode' => '160000']],
        ];
    }

    /** Explicit executable templates are byte-bound to the base both before execution and before application. */
    public function testCommittedCustomTemplateIsAcceptedAndReadThroughTheManagedBoundary(): void
    {
        $state = [];
        $validator = $this->validator($state);
        $options = new ReleaseOptions('/consumer', template: 'presentation.php');
        $validator->validateTemplate($options, self::SHA);
        self::assertSame([
            ['tree', 'presentation.php'], ['blob', 'presentation.php'], ['working', '/consumer/presentation.php'],
        ], $state['calls']);
        $state['calls'] = [];
        $validator->validate($this->plan(['options' => $options]));
        self::assertContains(['working', '/consumer/presentation.php'], $state['calls']);
    }

    /** Partial consumption does not require original fragment inventory, but its executable presentation remains immutable. */
    public function testRecoveryChecksOnlyTheSelectedTemplateAgainstItsOriginalBase(): void
    {
        $state = [];
        $validator = $this->validator($state);
        $plan = $this->plan(
            ['resuming' => true, 'options' => new ReleaseOptions(
                '/consumer',
                template: 'presentation.php',
            ), 'originalChangelog' => 'after'],
        );
        $validator->validate($plan);
        self::assertSame([
            ['tree', 'presentation.php'], ['blob', 'presentation.php'], ['working', '/consumer/presentation.php'],
        ], $state['calls']);
    }

    /** Direct transaction recovery cannot bypass the template proof after a descendant changes presentation. */
    public function testRecoveryRejectsChangedCustomTemplateWithoutReadingAlreadyConsumedFragments(): void
    {
        $state = [];
        $validator = $this->validator(
            $state,
            ['working_files' => ['/consumer/presentation.php' => 'changed template']],
        );
        try {
            $validator->validate(
                $this->plan(['resuming' => true, 'options' => new ReleaseOptions(
                    '/consumer',
                    template: 'presentation.php',
                )]),
            );
            self::fail('Changed recovery template must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('custom template', $error->getMessage());
        }
        self::assertSame([
            ['tree', 'presentation.php'], ['blob', 'presentation.php'], ['working', '/consumer/presentation.php'],
        ], $state['calls']);
    }

    /** Local non-Git templates and builtin templates require no template Git/file probes. */
    #[TestWith(['presentation.php', null])]
    #[TestWith(['keep-a-changelog', self::SHA])]
    public function testUnboundAndBuiltinTemplatesRemainReadFree(string $template, ?string $sha): void
    {
        $state = [];
        $this->validator($state)->validateTemplate(new ReleaseOptions('/consumer', template: $template), $sha);
        self::assertSame([], $state['calls']);
    }

    /** Template absence or dirty bytes cannot produce an apparently publishable release. */
    #[TestWith([null, 'trusted PHP'])]
    #[TestWith(['trusted PHP', null])]
    #[TestWith(['trusted PHP', 'dirty PHP'])]
    public function testCustomTemplateMustMatchItsBaseBlob(?string $committed, ?string $working): void
    {
        $state = [];
        $validator = $this->validator($state, [
            'base_overrides' => ['presentation.php' => $committed],
            'working_files' => ['/consumer/presentation.php' => $working],
        ]);
        $this->expectExceptionMessage('Commit the selected custom template');
        $validator->validateTemplate(new ReleaseOptions('/consumer', template: 'presentation.php'), self::SHA);
    }

    /** Every selected central/template path must be exactly one regular blob rather than a link/directory substitution. */
    #[DataProvider('nonRegularFiles')]
    public function testRegularBaseFileIdentityIsRequired(array $entries): void
    {
        $state = [];
        $validator = $this->validator($state, ['tree_overrides' => ['presentation.php' => $entries]]);
        $this->expectExceptionMessage('exact regular committed file');
        $validator->validateTemplate(new ReleaseOptions('/consumer', template: 'presentation.php'), self::SHA);
    }

    /** Missing/duplicate/wrong paths and symbolic/submodule modes fail exact regular-file identity. */
    public static function nonRegularFiles(): array
    {
        return [[[
        ],
        ], [[['path' => 'presentation.php', 'mode' => '100644'], ['path' => 'presentation.php/other', 'mode' => '100644']]],
            [[['path' => 'other.php', 'mode' => '100644']]], [[['path' => 'presentation.php', 'mode' => '120000']]],
            [[['path' => 'presentation.php', 'mode' => '160000']]]];
    }

    /** An executable tracked central blob is still a regular file; modes are preserved rather than rewritten. */
    public function testExecutableRegularModeIsSupported(): void
    {
        $state = [];
        $validator = $this->validator(
            $state,
            ['tree_overrides' => ['CHANGELOG.md' => [['path' => 'CHANGELOG.md', 'mode' => '100755']]]],
        );
        $validator->validate($this->plan());
        self::assertContains(['tree', 'CHANGELOG.md'], $state['calls']);
    }

    /** Constructs deterministic values without touching any filesystem, process or network. */
    private function plan(array $changes = []): ReleasePlan
    {
        return new ReleasePlan(...array_replace([
            'options' => new ReleaseOptions('/consumer'), 'id' => 'id', 'baseSha' => self::SHA,
            'currentVersion' => '1.0.0', 'nextVersion' => '1.0.1', 'impact' => 'patch',
            'consumed' => ['/consumer/.changelog/a.md' => hash('sha256', 'alpha'), '/consumer/.changelog/b.md' => hash(
                'sha256',
                'beta',
            )],
            'historicalVersions' => [], 'changelogPath' => '/consumer/CHANGELOG.md', 'originalChangelog' => 'before',
            'changelogContents' => 'after', 'notes' => '', 'receiptPath' => '/consumer/.changelog/release-plan.json',
            'originalReceipt' => null, 'receiptContents' => 'receipt',
        ], $changes));
    }

    /** All external boundaries are mocked; the call log proves the exact managed read scope and absence of mutations. */
    private function validator(array &$state, array $changes = []): ReleaseInputEvidenceValidator
    {
        $base = ['CHANGELOG.md' => 'before', '.changelog/a.md' => 'alpha', '.changelog/b.md' => 'beta', 'presentation.php' => 'trusted PHP'];
        $state = array_replace(['calls' => [], 'tree_overrides' => [], 'extra_entries' => [],
            'working_files' => ['/consumer/presentation.php' => 'trusted PHP']], $changes);
        $state['base'] = array_replace($base, $changes['base_overrides'] ?? []);
        $entries = array_map(
            static fn(string $path): array => ['path' => $path, 'mode' => '100644'],
            array_keys($base),
        );
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->method('readFileAt')->willReturnCallback(static function (string $directory, string $sha, string $path) use (
            &$state
        ): ?string {
            self::assertSame('/consumer', $directory);
            self::assertSame(self::SHA, $sha);
            $state['calls'][] = ['blob', $path];

            return $state['base'][$path] ?? null;
        });
        $git->method('filesAt')->willReturnCallback(static function (string $directory, string $sha, string $path) use (
            &$state,
            $entries
        ): array {
            self::assertSame('/consumer', $directory);
            self::assertSame(self::SHA, $sha);
            $state['calls'][] = ['tree', $path];

            return $state['tree_overrides'][$path] ?? array_values(array_filter(
                [...$entries, ...$state['extra_entries']],
                static fn(array $entry): bool => $entry['path'] === $path || str_starts_with(
                    $entry['path'],
                    $path . '/',
                ) || ('outside/a.md' === $entry['path'] && '.changelog' === $path),
            ));
        });
        $git->expects(self::never())->method('changesSince');
        $git->expects(self::never())->method('commitFragment');
        $files = $this->createMock(ManagedFileStoreInterface::class);
        $files->method('read')->willReturnCallback(static function (string $path) use (&$state): ?string {
            $state['calls'][] = ['working', $path];

            return $state['working_files'][$path] ?? null;
        });
        $files->expects(self::never())->method('write');
        $paths = $this->createStub(PackagePathResolverInterface::class);
        $paths->method('relativePath')->willReturnCallback(
            static fn(string $path, string $directory): string => substr($path, strlen($directory) + 1),
        );
        $paths->method('absolutePath')->willReturnCallback(
            static fn(string $path, ?string $directory = null): string => $directory . '/' . $path,
        );
        $exceptions = $this->createStub(ReleaseExceptionFactoryInterface::class);
        $exceptions->method('failure')->willReturnCallback(
            static fn(string $message): RuntimeException => new RuntimeException($message),
        );

        return new ReleaseInputEvidenceValidator($git, $files, $paths, $exceptions);
    }
}
