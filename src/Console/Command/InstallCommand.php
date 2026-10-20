<?php

declare(strict_types=1);

namespace FastForward\Changelog\Console\Command;

use FastForward\Changelog\Installation\ProjectInstallerInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** Guides explicit project setup while leaving existing workflows and skill ownership intact. */
#[AsCommand(
    name: 'install',
    description: 'Configure the GitHub workflow and optionally set up Composer-managed agent skills.',
    help: 'Run from the consumer project, including via CPX. The assistant explains publication, previews a new caller and asks before writing. Existing integrations are preserved. --yes permits noninteractive application with automatic publication and the project skill by default; --no-skill skips the skill. --publish=true|false selects publication explicitly. --set=name=value accepts a repeatable reusable-workflow input. Defaults are read from the selected published full tag; --ref is required from a development runtime. --dry-run writes nothing and --json emits only structured output without prompts. No Composer hooks, commits, pushes, workflow runs, tags or releases are created.',
)]
final readonly class InstallCommand
{
    /**
     * Injects project inspection and installation without reading the host or contacting GitHub.
     *
     * @param ProjectInstallerInterface $installer Owns verified rendering, create-only writes and skill ownership checks.
     */
    public function __construct(
        private ProjectInstallerInterface $installer,
    ) {}

    /**
     * Collects explained choices, previews the plan and applies only after explicit confirmation.
     *
     * @param  SymfonyStyle   $io         Human prompts and output, including separate error output.
     * @param  InputInterface $input      Used only to distinguish interactive execution from automation; JSON never prompts.
     * @param  string         $cwd        Existing consumer project directory, independent of the CPX runtime directory.
     * @param  ?string        $ref        Published full stable tag; null uses the executing package's stable version.
     * @param  ?string        $baseBranch Release branch override; null accepts the detected local default.
     * @param  ?string        $publish    Explicit true or false; null uses the explained automatic-publication choice.
     * @param  bool           $noSkill    Skip the optional project skill without affecting existing installations.
     * @param  array<string>  $settings   Repeatable name=value overrides for the selected reusable contract.
     * @param  bool           $yes        Confirm displayed workflow/metadata/Composer changes without a final prompt; existing policy remains authoritative.
     * @param  bool           $dryRun     Inspect and render without local writes, locks or skill installation.
     * @param  bool           $json       Emit only structured output with no interactive prompts.
     * @return int            Success for a completed/existing/allowed preview, invalid for missing confirmation or input, otherwise failure.
     */
    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,

        #[Option(description: 'Existing consumer project directory.', shortcut: 'C')]
        string $cwd = '.',

        #[Option(description: 'Published full stable product tag, for example v0.2.0.', shortcut: 'r')]
        ?string $ref = null,

        #[Option(description: 'Release branch; defaults to the locally detected origin/HEAD or main.')]
        ?string $baseBranch = null,

        #[Option(description: 'Publish the approved version PR after merge: true or false.')]
        ?string $publish = null,

        #[Option(name: 'no-skill', description: 'Do not install the optional project skill.')]
        bool $noSkill = false,

        #[Option(name: 'set', description: 'Reusable workflow override as name=value; repeat for multiple inputs.')]
        array $settings = [],

        #[Option(description: 'Confirm the displayed workflow, metadata changes and Composer commands.', shortcut: 'y')]
        bool $yes = false,

        #[Option(description: 'Preview without writing files or installing the skill.', shortcut: 'd')]
        bool $dryRun = false,

        #[Option(description: 'Emit structured output without prompts.', shortcut: 'j')]
        bool $json = false,
    ): int {
        try {
            if (null !== $publish && ! in_array($publish, ['true', 'false'], true)) {
                throw new InvalidArgumentException('--publish must be true or false.');
            }
            $choices = $this->overrides($settings);
            $inspection = $this->installer->inspect($cwd);
            $interactive = $input->isInteractive() && ! $json && ! $yes;
            $skill = ! $noSkill;
            if ('absent' === $inspection['state']) {
                $choices = $this->choices(
                    $io,
                    $choices,
                    $inspection['base_branch'],
                    $baseBranch,
                    $publish,
                    $interactive,
                );
            }
            if ($interactive && ! $noSkill && 'conflict' !== $inspection['state']) {
                $io->note(
                    'The optional skill uses llm/skills as a Composer dev dependency and a GitHub source in skills.json. Setup uses a verified independent manager without activating consumer plugins or adding plugin approval. Future Composer auto-sync requires explicit approval of a trusted installation. Foreign or native copies require an explicit migration.',
                );
                $skill = $io->confirm('Set up the project skill with llm/skills?', true);
            }
            $plan = $this->installer->run($cwd, $ref, $choices, $skill, true);
            if (! $json) {
                $this->display($io, $plan);
            }
            $exit = in_array($plan['state'], ['blocked', 'partial'], true) ? Command::FAILURE : Command::SUCCESS;
            if (! $dryRun && Command::SUCCESS === $exit) {
                if (! $yes && (! $interactive || ! $io->confirm(
                    'Apply the displayed workflow, metadata changes and Composer commands?',
                    false,
                ))) {
                    if ($json) {
                        $io->writeln(
                            json_encode(
                                ['state' => 'confirmation-required', 'plan' => $plan],
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                            ),
                            OutputInterface::OUTPUT_RAW,
                        );
                    } else {
                        $io->writeln(
                            'Confirmation required. Re-run with --yes in noninteractive mode; no files were written.',
                        );
                    }

                    return Command::INVALID;
                }
                $plan = $this->installer->run(
                    $cwd,
                    $ref,
                    $choices,
                    $skill,
                    false,
                    hash('sha256', json_encode($plan['workflow'], JSON_THROW_ON_ERROR)),
                    $skill ? ($plan['skill']['fingerprint'] ?? null) : null,
                );
                $exit = in_array($plan['state'], ['blocked', 'partial'], true) ? Command::FAILURE : Command::SUCCESS;
                if (! $json) {
                    $this->display($io, $plan);
                }
            }
            if ($json) {
                $io->writeln(
                    json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    OutputInterface::OUTPUT_RAW,
                );
            }

            return $exit;
        } catch (Throwable $error) {
            $message = $error->getMessage();
            $io->getErrorStyle()->writeln(
                $json ? json_encode(['error' => $message], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)
                    : OutputFormatter::escape($message),
                $json ? OutputInterface::OUTPUT_RAW : OutputInterface::OUTPUT_NORMAL,
            );

            return $error instanceof InvalidArgumentException ? Command::INVALID : Command::FAILURE;
        }
    }

    /**
     * Parses explicit overrides without executing values or silently discarding duplicate choices.
     *
     * @param  array<string>            $settings Repeatable name=value values; true and false become booleans, other values remain strings.
     * @return array<string,mixed>      Typed inputs for validation against the verified release contract.
     * @throws InvalidArgumentException When an override is malformed, duplicated or contains unsupported syntax.
     */
    private function overrides(array $settings): array
    {
        $choices = [];
        foreach ($settings as $setting) {
            $parts = explode('=', $setting, 2);
            if (2 !== count($parts) || 1 !== preg_match('/\A[a-z][a-z0-9-]*\z/', $parts[0]) || array_key_exists(
                $parts[0],
                $choices,
            )) {
                throw new InvalidArgumentException('Use each --set input once as name=value.');
            }
            $value = $parts[1];
            $choices[$parts[0]] = match ($value) {
                'true' => true,
                'false' => false,
                default => $value,
            };
        }

        return $choices;
    }

    /**
     * Explains the main lifecycle decisions and optionally collects a small advanced configuration.
     *
     * @param  SymfonyStyle             $io          Human question and explanation interface.
     * @param  array<string,mixed>      $choices     Explicit reusable inputs, preserved unless the corresponding guided choice is supplied.
     * @param  string                   $detected    Locally detected default release branch.
     * @param  ?string                  $baseBranch  Explicit branch option, which takes precedence over detection.
     * @param  ?string                  $publish     Explicit boolean option, validated before planning.
     * @param  bool                     $interactive Whether human questions are allowed for this execution.
     * @return array<string,mixed>      Final choices; the service omits only defaults verified at the selected product revision.
     * @throws InvalidArgumentException When publication is not true or false.
     */
    private function choices(
        SymfonyStyle $io,
        array $choices,
        string $detected,
        ?string $baseBranch,
        ?string $publish,
        bool $interactive,
    ): array {
        $base = $baseBranch ?? ($choices['base-branch'] ?? $detected);
        $automatic = null === $publish ? ($choices['publish'] ?? true) : 'true' === $publish;
        if ($interactive) {
            $io->note(
                'Contributions prepare a version PR. You review and merge it; the workflow never merges it for you. Automatic publication then creates the approved tag and GitHub Release. Disabled publication still records history and permits later version PRs when supported by the selected release.',
            );
            if (null === $baseBranch && ! array_key_exists('base-branch', $choices)) {
                $base = $io->ask('Release branch (detected locally)', $base);
            }
            if (null === $publish && ! array_key_exists('publish', $choices)) {
                $automatic = $io->confirm('Publish the approved version PR automatically after you merge it?', true);
            }
            if ($io->confirm('Configure advanced workflow options?', false)) {
                foreach ([
                    'managed-branch' => [
                        'Version PR branch (use a different branch per release line)',
                        'changelog/version',
                    ],
                    'runs-on' => [
                        'Linux runner labels as JSON; Docker, Bash and Git are required',
                        '["ubuntu-latest"]',
                    ],
                    'comment-mode' => ['PR comments: always, on-failure or never', 'always'],
                    'version-pr-title' => [
                        'Version PR title; placeholders: {repository}, {repository_name}, {current_version}, {next_version}',
                        'chore(release): {repository} v{current_version} → v{next_version}',
                    ],
                ] as $name => [$question, $example]) {
                    $answer = $io->ask(
                        $question . ' (blank keeps the selected release default; example: ' . $example . ')',
                    );
                    if (null !== $answer && '' !== $answer) {
                        $choices[$name] = $answer;
                    }
                }
            }
        }

        return [...$choices, 'base-branch' => $base, 'publish' => $automatic];
    }

    /**
     * Shows exact proposed YAML, metadata paths and structured Composer commands before confirmation.
     *
     * @param  SymfonyStyle        $io   Human output interface with escaping for consumer-controlled values.
     * @param  array<string,mixed> $plan Structured preview or result with independent component states.
     * @return void                No files or remote state are changed by presentation.
     */
    private function display(SymfonyStyle $io, array $plan): void
    {
        $io->writeln(OutputFormatter::escape('Project setup: ' . $plan['state']));
        $workflow = $plan['workflow'];
        $io->writeln(OutputFormatter::escape('Workflow: ' . $workflow['state'] . '; path: ' . $workflow['path']));
        if (is_string($workflow['content'] ?? null)) {
            $io->writeln(OutputFormatter::escape($workflow['content']));
        }
        $skill = $plan['skill'] ?? null;
        if (is_array($skill)) {
            $io->writeln(OutputFormatter::escape(
                'Skill: ' . $skill['state'] . '; manager: ' . $skill['manager']
                . '; source: ' . $skill['source'] . '; target: ' . $skill['target'],
            ));
            foreach ($skill['changes'] ?? [] as $path) {
                $io->writeln(OutputFormatter::escape('Metadata: ' . $path));
            }
            foreach ($skill['commands'] ?? [] as $command) {
                $io->writeln(
                    OutputFormatter::escape('Command arguments: ' . json_encode(
                        $command,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                    )),
                );
            }
            foreach ($skill['diagnostics'] ?? [] as $diagnostic) {
                $io->writeln(OutputFormatter::escape($diagnostic));
            }
        }
        foreach ($plan['diagnostics'] as $diagnostic) {
            $io->writeln(OutputFormatter::escape($diagnostic));
        }
    }
}
