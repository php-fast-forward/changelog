<?php

declare(strict_types=1);

namespace FastForward\Changelog\Console\Command;

use FastForward\Changelog\Skill\SkillInstallerInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** Installs this runtime's complete skill through explicit, ownership-preserving local modes. */
#[AsCommand(
    name: 'skill:install',
    description: 'Install, check or explicitly update this runtime\'s bundled skill without network access.',
    help: 'Installs the bundled skill into the project by default. Use skill:install --check for a read-only comparison, or skill:install --update to replace an intact native copy explicitly. --check and --update are mutually exclusive. --global selects the configured home only when requested. --target is a relative discovery root; fast-forward-changelog is appended. --dry-run never writes. No network or automatic update is used. Unknown owners, local edits and known downgrades are preserved; recovery leftovers require inspection.',
)]
final readonly class SkillInstallCommand
{
    /**
     * Injects the native installation boundary without observing consumer or package paths.
     *
     * @param SkillInstallerInterface $installer Handles payload, ownership, integrity and guarded directory transactions.
     */
    public function __construct(
        private SkillInstallerInterface $installer,
    ) {}

    /**
     * Selects one mode and renders its explicit state; unknown/local edits are never overwritten.
     *
     * @param  SymfonyStyle $io     Human output and separate stderr diagnostics supplied by Symfony.
     * @param  string       $cwd    Existing project directory, independent from CPX/package installation paths.
     * @param  string       $target Relative discovery root; the fixed fast-forward-changelog name is appended.
     * @param  bool         $global Explicit opt-in to the configured home scope instead of the project.
     * @param  bool         $check  Compare only; absence/drift returns failure without mutation.
     * @param  bool         $update Replace only a native installation whose recorded tree remains intact.
     * @param  bool         $dryRun Preview the permitted action without locks or writes.
     * @param  bool         $json   Emit only a stable JSON result instead of human formatting.
     * @return int          Success for current/completed/permitted preview, failure for drift/refusal, or invalid for argument errors.
     */
    public function __invoke(
        SymfonyStyle $io,

        #[Option(description: 'Existing project directory.', shortcut: 'C')]
        string $cwd = '.',

        #[Option(description: 'Relative discovery root; the fixed skill name is appended.', shortcut: 't')]
        string $target = '.agents/skills',

        #[Option(description: 'Explicitly select the configured home instead of the project.', shortcut: 'g')]
        bool $global = false,

        #[Option(description: 'Inspect local integrity/equality only; writes nothing.', shortcut: 'c')]
        bool $check = false,

        #[Option(description: 'Update only an intact native copy; never merge local edits.', shortcut: 'u')]
        bool $update = false,

        #[Option(description: 'Preview a permitted install/update without writes.', shortcut: 'd')]
        bool $dryRun = false,

        #[Option(description: 'Emit the local result as JSON.', shortcut: 'j')]
        bool $json = false,
    ): int {
        try {
            if ($check && $update) {
                throw new InvalidArgumentException('--check and --update are mutually exclusive.');
            }
            $result = $this->installer->run(
                $cwd,
                $target,
                $global,
                $check ? 'check' : ($update ? 'update' : 'install'),
                $dryRun,
            );
            $success = in_array($result['state'], ['current', 'installed', 'updated'], true)
                || ($dryRun && ! $check && null !== $result['action']);
            if ($json) {
                $io->writeln(
                    json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    OutputInterface::OUTPUT_RAW,
                );
            } else {
                $io->writeln('Skill state: ' . $result['state'] . '; target: ' . $result['target']);
                if (! $success) {
                    $io->getErrorStyle()->note(
                        'No files were replaced. Inspect ownership, local edits or retained recovery directories before retrying.',
                    );
                }
            }

            return $success ? Command::SUCCESS : Command::FAILURE;
        } catch (Throwable $error) {
            if ($json) {
                $io->getErrorStyle()->writeln(
                    json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                    OutputInterface::OUTPUT_RAW,
                );
            } else {
                $io->getErrorStyle()->error($error->getMessage());
            }

            return $error instanceof InvalidArgumentException ? Command::INVALID : Command::FAILURE;
        }
    }
}
