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

use FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface;
use FastForward\Changelog\Console\Input\ReleaseInput;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Validation\CheckServiceInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** Validates local contributions and accepts authorization only from verified pull-request policy. */
#[AsCommand(
    name: 'check',
    description: 'Validate fragments and optional pull-request contribution evidence.',
)]
final readonly class CheckCommand
{
    /** Captures validation, trusted policy and checkout identity without reading user environment. */
    public function __construct(
        private ReleaseOptionsFactoryInterface $options,
        private CheckServiceInterface $checks,
        private PullRequestPolicyInterface $policy,
        private GitRepositoryInterface $git,
    ) {}

    /** Verifies policy belongs to this checkout before exposing its authorization to schema validation. */
    public function __invoke(
        #[MapInput]
        ReleaseInput $settings,
        SymfonyStyle $io,

        #[Option(description: 'Git baseline for the contribution delta.')]
        ?string $since = null,

        #[Option(description: 'Pull-request number whose trusted authorization is inspected.')]
        ?string $pullRequest = null,
    ): int {
        try {
            $options = $this->options->create($settings->values());
            $central = false;
            $waiver = false;
            if (null !== $pullRequest) {
                $number = filter_var($pullRequest, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (false === $number) {
                    throw new InvalidArgumentException('--pull-request must be a positive integer.');
                }
                if (null === $since) {
                    throw new InvalidArgumentException('--pull-request requires an explicit --since baseline.');
                }
                $authorization = $this->policy->inspect($options, $number);
                foreach ($authorization->diagnostics as $diagnostic) {
                    $io->note($diagnostic);
                }
                if (null === $authorization->headSha) {
                    throw new RuntimeException('Pull-request policy could not establish an inspected head identity.');
                }
                if ($authorization->headSha !== $this->git->resolveRef($options->workingDirectory)) {
                    throw new RuntimeException(
                        'Check out the inspected pull-request head before applying its authorization.',
                    );
                }
                $central = $authorization->centralChangeAuthorized;
                $waiver = $authorization->waiverAuthorized;
            }
            $report = $this->checks->check($options, $since, $central, $waiver);
            if (! $report->isValid()) {
                $io->getErrorStyle()->error(
                    json_encode($report->errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                );

                return Command::FAILURE;
            }
            $io->success(sprintf('Validated %d pending fragments.', count($report->changesets)));

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $io->getErrorStyle()->error($exception->getMessage());

            return $exception instanceof InvalidArgumentException ? Command::INVALID : Command::FAILURE;
        }
    }
}
