<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see       https://github.com/php-fast-forward/changelog
 * @see       https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Fragment;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Parser\ChangesetParserInterface;
use FastForward\Changelog\Changeset\Renderer\ChangesetRendererInterface;
use FastForward\Changelog\Changeset\Store\ChangesetStoreInterface;
use FastForward\Changelog\Changeset\Store\WriteResult;
use FastForward\Changelog\Fragment\Factory\FragmentExceptionFactoryInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use Throwable;

/** Creates a validated fragment and delegates strictly scoped optional Git commits. */
final readonly class FragmentWriter implements FragmentWriterInterface
{
    /** Injects all schema, persistence, entropy, Git and failure-construction boundaries. */
    public function __construct(
        private ChangesetParserInterface $parser,
        private ChangesetRendererInterface $renderer,
        private ChangesetStoreInterface $store,
        private IdentifierGeneratorInterface $identifiers,
        private GitRepositoryInterface $git,
        private FragmentExceptionFactoryInterface $exceptions,
    ) {}

    /**
     * Validates before mutation, retries only generated-name collisions and preserves failures.
     *
     * Git is invoked only after successful creation and only when commit is true.
     * It receives one repository-relative path; it never receives unrelated files.
     */
    public function add(
        ReleaseOptions $options,
        string $message,
        string $category = 'changed',
        ?string $type = null,
        ?string $name = null,
        ?int $issue = null,
        ?int $pullRequest = null,
        ?string $author = null,
        bool $commit = false,
        string $commitMessage = 'chore: record changelog fragment',
    ): string {
        if ($commit && '' === trim($commitMessage)) {
            throw $this->exceptions->invalid('The fragment commit message must not be empty.');
        }

        $effectiveType = $type ?? Category::tryFrom($category)?->inferredImpact()->value ?? 'minor';
        $metadata = [sprintf('category: %s', $category), sprintf('type: %s', $effectiveType)];

        if (null !== $issue) {
            $metadata[] = sprintf('issue: %d', $issue);
        }

        if (null !== $pullRequest) {
            $metadata[] = sprintf('pull_request: %d', $pullRequest);
        }

        if (null !== $author) {
            $metadata[] = sprintf('author: "%s"', $author);
        }

        $input = implode("\n", ['---', ...$metadata, '---', '', $message]);
        $directory = rtrim(str_replace('\\', '/', $options->workingDirectory), '/') . '/' . trim(str_replace('\\', '/', $options->fragmentDirectory), '/');
        $attempts = null === $name ? 5 : 1;

        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            $id = $name ?? $this->identifiers->generate();

            if (1 !== preg_match(ChangesetParserInterface::FILENAME_PATTERN, $id)) {
                throw $this->exceptions->invalid(sprintf('Invalid fragment name "%s": use lowercase dash segments and .md.', $id));
            }

            $path = $directory . '/' . $id;
            $parsed = $this->parser->parse($path, $input);

            if (! $parsed->isValid()) {
                throw $this->exceptions->invalid(sprintf('Invalid fragment "%s": %s', $path, implode(' ', $parsed->errors)));
            }

            $contents = $this->renderer->render($parsed->changeset);

            try {
                $result = $this->store->write($path, $contents);
            } catch (Throwable $failure) {
                throw $this->exceptions->failure(sprintf('Cannot create fragment "%s": %s', $path, $failure->getMessage()), $failure);
            }

            if (WriteResult::Existing === $result && null === $name) {
                continue;
            }

            if (WriteResult::Created !== $result) {
                throw $this->exceptions->failure(sprintf('Cannot create fragment "%s": %s.', $path, $result->value));
            }

            if ($commit) {
                $relative = trim(str_replace('\\', '/', $options->fragmentDirectory), '/') . '/' . $id;

                try {
                    $this->git->commitFragment($options->workingDirectory, $relative, $commitMessage);
                } catch (Throwable $failure) {
                    throw $this->exceptions->failure(sprintf('Created fragment "%s", but its commit failed: %s. The fragment is preserved for recovery.', $path, $failure->getMessage()), $failure);
                }
            }

            return $path;
        }

        throw $this->exceptions->failure(sprintf('Cannot create a fragment in "%s": five generated identifiers already exist; no fragment was overwritten.', $directory));
    }
}
