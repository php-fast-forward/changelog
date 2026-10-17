<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Validation;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Validation\CheckService;
use FastForward\Changelog\Validation\ValidationReport;
use FastForward\Changelog\Validator\ChangesetValidatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CheckService::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(ValidationReport::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(Category::class)]
final class PendingNoteCurationTest extends TestCase
{
    #[TestWith(['delete', true])]
    #[TestWith(['modify', true])]
    #[TestWith(['executable', true])]
    #[TestWith(['no-replacement', false])]
    #[TestWith(['no-authority', false])]
    #[TestWith(['ordinary', false])]
    #[TestWith(['waiver', false])]
    #[TestWith(['unbound-kind', false])]
    #[TestWith(['copy-replacement', false])]
    #[TestWith(['rename-replacement', false])]
    #[TestWith(['replacement-has-previous', false])]
    #[TestWith(['replacement-existed', false])]
    #[TestWith(['replacement-base-entry', false])]
    #[TestWith(['replacement-missing', false])]
    #[TestWith(['replacement-symlink', false])]
    #[TestWith(['replacement-wrong-path', false])]
    #[TestWith(['replacement-hash', false])]
    #[TestWith(['replacement-git-failure', false])]
    #[TestWith(['old-missing', false])]
    #[TestWith(['old-symlink', false])]
    #[TestWith(['old-wrong-path', false])]
    #[TestWith(['old-absent-entry', false])]
    #[TestWith(['deletion-retains-blob', false])]
    #[TestWith(['deletion-retains-entry', false])]
    #[TestWith(['modified-mode', false])]
    #[TestWith(['modified-hash', false])]
    #[TestWith(['modified-missing', false])]
    #[TestWith(['modified-symlink', false])]
    #[TestWith(['old-git-failure', false])]
    #[TestWith(['inherited-rename', false])]
    #[TestWith(['inherited-type', false])]
    #[TestWith(['invalid-inventory', false])]
    #[TestWith(['invalid-replacement', false])]
    #[TestWith(['unselected-replacement', false])]
    #[TestWith(['nested-inherited', false])]
    #[TestWith(['readme-inherited', false])]
    #[TestWith(['hidden-inherited', false])]
    public function testCurationRequiresHumanAuthorityNewSummaryAndExactCommittedFiles(
        string $scenario,
        bool $expected,
    ): void {
        $replacement = new Changeset('summary.md', Category::Added, null, null, null, 'Initial delivery.');
        $modified = in_array($scenario, [
            'modify', 'executable', 'modified-mode', 'modified-hash', 'modified-missing', 'modified-symlink',
        ], true);
        $oldPath = match ($scenario) {
            'nested-inherited' => '.changelog/nested/old.md',
            'readme-inherited' => '.changelog/README.md',
            'hidden-inherited' => '.changelog/.hidden.md',
            default => '.changelog/old.md',
        };
        $newPath = '.changelog/summary.md';
        $before = [
            $oldPath => 'Original pending note.',
            $newPath => 'replacement-existed' === $scenario ? 'Pre-existing note.' : null,
        ];
        $after = [
            $oldPath => $modified || 'deletion-retains-blob' === $scenario ? 'Revised pending note.' : null,
            $newPath => 'Initial delivery.',
        ];
        $before[$oldPath] = 'old-missing' === $scenario ? null : $before[$oldPath];
        if (in_array($scenario, ['replacement-missing', 'modified-missing'], true)) {
            $after['replacement-missing' === $scenario ? $newPath : $oldPath] = null;
        }
        $mode = 'executable' === $scenario ? '100755' : '100644';
        $oldEntries = [
            $oldPath => [['path' => $oldPath, 'mode' => $mode]],
            $newPath => null === $before[$newPath] ? [] : [['path' => $newPath, 'mode' => '100644']],
        ];
        $newEntries = [
            $oldPath => $modified ? [['path' => $oldPath, 'mode' => $mode]] : [],
            $newPath => [['path' => $newPath, 'mode' => '100644']],
        ];
        match ($scenario) {
            'replacement-base-entry' => $oldEntries[$newPath] = $newEntries[$newPath],
            'replacement-symlink' => $newEntries[$newPath][0]['mode'] = '120000',
            'replacement-wrong-path' => $newEntries[$newPath][0]['path'] = '.changelog/other.md',
            'old-symlink' => $oldEntries[$oldPath][0]['mode'] = '120000',
            'old-wrong-path' => $oldEntries[$oldPath][0]['path'] = '.changelog/other.md',
            'old-absent-entry' => $oldEntries[$oldPath] = [],
            'deletion-retains-entry' => $newEntries[$oldPath] = $oldEntries[$oldPath],
            'modified-mode' => $newEntries[$oldPath][0]['mode'] = '100755',
            'modified-symlink' => $newEntries[$oldPath][0]['mode'] = '120000',
            default => null,
        };
        $newStatus = match ($scenario) {
            'copy-replacement' => 'C100',
            'rename-replacement' => 'R100',
            default => 'A',
        };
        $changes = [
            ['status' => match ($scenario) {
                'inherited-rename' => 'R80',
                'inherited-type' => 'T',
                default => $modified ? 'M' : 'D',
            }, 'path' => $oldPath, 'previous' => 'inherited-rename' === $scenario ? '.changelog/older.md' : null],
        ];
        if ('no-replacement' !== $scenario) {
            $changes[] = ['status' => $newStatus, 'path' => $newPath, 'previous' => match ($scenario) {
                'copy-replacement' => $oldPath,
                'rename-replacement' => 'draft.md',
                'replacement-has-previous' => 'unexpected.md',
                default => null,
            }];
        }
        $hashes = [
            '/repo/' . $newPath => hash('sha256', 'replacement-hash' === $scenario ? 'dirty' : 'Initial delivery.'),
            '/repo/' . $oldPath => hash('sha256', 'modified-hash' === $scenario ? 'dirty' : 'Revised pending note.'),
        ];
        $validator = $this->createMock(ChangesetValidatorInterface::class);
        $validator->expects(self::once())->method('validate')->with('/repo/.changelog', false, false)
            ->willReturn(new ValidationReport([$replacement],
                'invalid-inventory' === $scenario ? ['bad.md' => ['Invalid inventory.']] : [], false, $hashes));
        $validator->expects(self::once())->method('validatePaths')->with('/repo/.changelog',
            'no-replacement' === $scenario ? [] : ['/repo/' . $newPath], false, false)
            ->willReturn(new ValidationReport(
                in_array($scenario, ['no-replacement', 'unselected-replacement'], true) ? [] : [$replacement],
                'invalid-replacement' === $scenario ? ['summary.md' => ['Invalid summary.']] : [],
                false,
            ));
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::once())->method('changesSince')->with('/repo', 'base')->willReturn($changes);
        $git->method('readFileAt')->willReturnCallback(
            static function (string $directory, string $reference, string $path) use (
                $before,
                $after,
                $scenario,
                $oldPath,
                $newPath
            ): ?string {
                if (('replacement-git-failure' === $scenario && $path === $newPath)
                    || ('old-git-failure' === $scenario && $path === $oldPath)) {
                    throw new RuntimeException('Synthetic unavailable evidence.');
                }

                return ('base' === $reference ? $before : $after)[$path];
            },
        );
        $git->method('filesAt')->willReturnCallback(
            static fn(string $directory, string $reference, string $path): array => ('base' === $reference ? $oldEntries : $newEntries)[$path],
        );
        $kind = match ($scenario) {
            'ordinary' => 'ordinary', 'waiver' => 'waiver', 'unbound-kind' => 'unknown', default => 'maintenance',
        };
        $report = new CheckService($validator, $git)->check(
            new ReleaseOptions('/repo'), 'base', 'no-authority' !== $scenario, 'waiver' === $scenario, $kind,
        );

        self::assertSame($expected, $report->isValid(), $scenario);
        if (!$expected) {
            self::assertNotEmpty($report->errors);
        }
    }
}
