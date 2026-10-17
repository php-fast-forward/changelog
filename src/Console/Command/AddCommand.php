<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 * @see       https://github.com/php-fast-forward/changelog
 */

namespace FastForward\Changelog\Console\Command;

use FastForward\Changelog\Console\Input\ReleaseInput;
use FastForward\Changelog\Console\Normalizer\LineAnswerNormalizer;
use FastForward\Changelog\Fragment\FragmentWriterInterface;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Ask;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Constraints\NotBlank;
use Throwable;

/** Creates one independently validated contribution, optionally committing only that fragment. */
#[AsCommand(
    name: 'add',
    description: 'Create one changelog fragment with optional release metadata.',
)]
final readonly class AddCommand
{
    /**
     * Captures fragment creation and shared settings without observing external state.
     *
     * @param ReleaseOptionsFactoryInterface $options Creates validated consumer settings from mapped CLI values.
     * @param FragmentWriterInterface        $writer  Creates one independent fragment through injected validation, filesystem and Git boundaries.
     */
    public function __construct(
        private ReleaseOptionsFactoryInterface $options,
        private FragmentWriterInterface $writer,
    ) {}

    /**
     * Declares the required description's native prompt and delegates validated input to the writer.
     *
     * @param SymfonyStyle $io            Symfony terminal style used for human-facing results and diagnostics.
     * @param ReleaseInput $settings      Mapped shared consumer paths and release settings from Symfony input.
     * @param string       $message       Nonempty author-written Markdown description whose meaningful spaces are preserved.
     * @param string       $category      Canonical lowercase Keep a Changelog category identifier.
     * @param string|null  $type          Explicit semantic impact override, or null to materialize the category default.
     * @param string|null  $name          Optional complete lowercase-dash .md filename; null requests a generated identity.
     * @param string|null  $issue         Optional positive issue number associated with the fragment.
     * @param string|null  $pullRequest   Optional positive pull-request number associated with the fragment.
     * @param string|null  $author        Optional GitHub login to credit, normalized without a leading @.
     * @param bool         $commit        Whether to commit only the newly created fragment after a successful write.
     * @param string       $commitMessage Commit text used only when scoped fragment committing is explicitly enabled.
     *
     * @return int Symfony exit status reflecting the command's result or controlled input/operation failure.
     */
    public function __invoke(
        SymfonyStyle $io,

        #[MapInput]
        ReleaseInput $settings,

        #[Argument(description: 'The Markdown change description.')]
        #[Ask(
            question: 'Change description',
            trimmable: false,
            normalizer: new LineAnswerNormalizer(),
            constraints: [new NotBlank(
                message: 'The change description must contain meaningful text.',
                normalizer: 'trim',
            )],
        )]
        string $message,

        #[Option(description: 'added, changed, deprecated, removed, fixed or security.', shortcut: 'c')]
        string $category = 'changed',

        #[Option(description: 'Explicit semantic impact: major, minor or patch.', shortcut: 't')]
        ?string $type = null,

        #[Option(description: 'Explicit unique fragment filename.', shortcut: 'f')]
        ?string $name = null,

        #[Option(description: 'Related positive issue number.', shortcut: 'i')]
        ?string $issue = null,

        #[Option(description: 'Related positive pull-request number.', shortcut: 'p')]
        ?string $pullRequest = null,

        #[Option(description: 'Optional GitHub author login.', shortcut: 'a')]
        ?string $author = null,

        #[Option(description: 'Commit only the created fragment.')]
        bool $commit = false,

        #[Option(description: 'Message for the optional fragment-only commit.', shortcut: 'm')]
        string $commitMessage = 'chore: record changelog fragment',
    ): int {
        try {
            $path = $this->writer->add(
                $this->options->create($settings->values()),
                $message,
                $category,
                $type,
                $name,
                $this->number($issue),
                $this->number($pullRequest),
                $author,
                $commit,
                $commitMessage,
            );
            $io->success('Created fragment: ' . $path);

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $io->getErrorStyle()->error($exception->getMessage());

            return $exception instanceof InvalidArgumentException ? Command::INVALID : Command::FAILURE;
        }
    }

    /**
     * Rejects malformed or overflowing references before the writer can mutate a fragment.
     *
     * @param string|null $value Optional raw issue or PR number, accepted only as a positive decimal within PHP's integer range.
     *
     * @return int|null Positive parsed issue or PR number, or null when no reference was supplied.
     */
    private function number(?string $value): ?int
    {
        if (null === $value) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $number) {
            throw new InvalidArgumentException('Issue and pull-request references must be positive integers.');
        }

        return $number;
    }
}
