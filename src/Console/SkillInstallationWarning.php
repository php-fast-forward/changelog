<?php

declare(strict_types=1);

namespace FastForward\Changelog\Console;

use FastForward\Changelog\Skill\SkillInstallerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Gives one best-effort local project-skill hint without asserting remote latest state or updating files. */
final readonly class SkillInstallationWarning
{
    /**
     * Captures a lazy inspection boundary and explicitly composed terminal/CI eligibility.
     *
     * @param SkillInstallerInterface $installer Read-only local comparison with this runtime's actual payload.
     * @param bool                    $enabled   Whether both terminal streams are attached and the process is outside CI.
     */
    public function __construct(
        private SkillInstallerInterface $installer,
        private bool $enabled,
    ) {}

    /**
     * Reports only project scope; optional absence and auxiliary failures never block the selected command.
     *
     * @param  string          $cwd   Explicit project directory selected by the command or configured resolver.
     * @param  OutputInterface $error Human stderr selected by the application, never stdout.
     * @return void            At most one controlled hint is written; no network, installation or auto-update occurs.
     */
    public function warn(string $cwd, OutputInterface $error): void
    {
        if (! $this->enabled) {
            return;
        }
        try {
            $result = $this->installer->run($cwd, mode: 'check');
            $message = match ($result['state']) {
                'outdated' => 'The managed changelog skill is older than this CLI\'s bundled payload. Review skill:install --check before an explicit skill:install --update.',
                default => null,
            };
            if (null !== $message) {
                $error->writeln($message, OutputInterface::OUTPUT_RAW);
            }
        } catch (Throwable) {
            // Optional local inspection must not change a changelog command's result or leak payload bytes.
        }
    }
}
