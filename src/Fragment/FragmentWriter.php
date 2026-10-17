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
    /**
     * Injects all schema, persistence, entropy, Git and failure-construction boundaries.
     *
     * @param ChangesetParserInterface          $parser      Validates the complete fragment schema while preserving all independent diagnostics.
     * @param ChangesetRendererInterface        $renderer    Produces canonical fragment frontmatter while preserving validated Markdown body bytes.
     * @param ChangesetStoreInterface           $store       Performs guarded fragment creation without replacing a sibling identifier.
     * @param IdentifierGeneratorInterface      $identifiers Supplies canonical collision-resistant filenames without entropy reads during composition.
     * @param GitRepositoryInterface            $git         Observes repository identities, committed bytes and scoped operations through an injected boundary.
     * @param FragmentExceptionFactoryInterface $exceptions  Constructs controlled domain diagnostics without exposing underlying host details.
     */
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
     *
     * @param ReleaseOptions $options       Validated consumer paths, presentation settings and source-selection policy.
     * @param string         $message       Nonempty author-written Markdown description whose meaningful spaces are preserved.
     * @param string         $category      Canonical lowercase Keep a Changelog category identifier.
     * @param string|null    $type          Explicit semantic impact override, or null to materialize the category default.
     * @param string|null    $name          Optional complete lowercase-dash .md filename; null permits up to five generated-name attempts.
     * @param int|null       $issue         Optional positive issue number associated with the fragment.
     * @param int|null       $pullRequest   Optional positive pull-request number associated with the fragment.
     * @param string|null    $author        Optional GitHub login to credit, normalized without a leading @.
     * @param bool           $commit        Whether to commit only the newly created fragment after a successful write.
     * @param string         $commitMessage Commit text used only when scoped fragment committing is explicitly enabled.
     *
     * @return string Absolute path of the new fragment after creation and any requested commit succeed.
     *
     * @throws \InvalidArgumentException When description, metadata, filename or an enabled commit message violates the fragment schema.
     * @throws \RuntimeException         When creation, collision retries or the optional scoped commit fails; a successfully created fragment remains available.
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
        $directory = rtrim(str_replace('\\', '/', $options->workingDirectory), '/') . '/' . trim(
            str_replace('\\', '/', $options->fragmentDirectory),
            '/',
        );
        $attempts = null === $name ? 5 : 1;

        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            $id = $name ?? $this->identifiers->generate();

            $path = $directory . '/' . $id;
            $result = $this->writeFragment($path, $id, $input);

            if (WriteResult::Existing === $result && null === $name) {
                continue;
            }

            if (WriteResult::Created !== $result) {
                throw $this->exceptions->failure(sprintf('Cannot create fragment "%s": %s.', $path, $result->value));
            }

            if ($commit) {
                $this->commitFragment($options, $id, $path, $commitMessage);
            }

            return $path;
        }

        throw $this->exceptions->failure(
            sprintf(
                'Cannot create a fragment in "%s": five generated identifiers already exist; no fragment was overwritten.',
                $directory,
            ),
        );
    }

    /**
     * Validates one identifier and fragment before attempting its guarded creation.
     *
     * @param  string                    $path  Absolute destination for the current identifier attempt.
     * @param  string                    $id    Explicit or generated complete fragment filename.
     * @param  string                    $input Candidate frontmatter and Markdown description.
     * @return WriteResult               Store outcome; the caller decides whether a collision permits retry.
     * @throws \InvalidArgumentException When the filename or complete fragment schema is invalid.
     * @throws \RuntimeException         When persistence throws, retaining the original failure as the cause.
     */
    private function writeFragment(string $path, string $id, string $input): WriteResult
    {
        if (1 !== preg_match(ChangesetParserInterface::FILENAME_PATTERN, $id)) {
            throw $this->exceptions->invalid(
                sprintf('Invalid fragment name "%s": use lowercase dash segments and .md.', $id),
            );
        }

        $parsed = $this->parser->parse($path, $input);

        if (! $parsed->isValid()) {
            throw $this->exceptions->invalid(
                sprintf('Invalid fragment "%s": %s', $path, implode(' ', $parsed->errors)),
            );
        }

        $contents = $this->renderer->render($parsed->changeset);

        try {
            return $this->store->write($path, $contents);
        } catch (Throwable $failure) {
            throw $this->exceptions->failure(
                sprintf('Cannot create fragment "%s": %s', $path, $failure->getMessage()),
                $failure,
            );
        }
    }

    /**
     * Commits only the successfully created fragment, preserving it when Git fails.
     *
     * @param  ReleaseOptions    $options Consumer working directory and relative fragment directory.
     * @param  string            $id      Complete filename of the newly created fragment.
     * @param  string            $path    Absolute created path retained in the recovery diagnostic.
     * @param  string            $message Already validated commit message.
     * @return void              Completes after the scoped commit succeeds.
     * @throws \RuntimeException When Git fails; the created fragment remains available for recovery.
     */
    private function commitFragment(ReleaseOptions $options, string $id, string $path, string $message): void
    {
        $relative = trim(str_replace('\\', '/', $options->fragmentDirectory), '/') . '/' . $id;

        try {
            $this->git->commitFragment($options->workingDirectory, $relative, $message);
        } catch (Throwable $failure) {
            throw $this->exceptions->failure(
                sprintf(
                    'Created fragment "%s", but its commit failed: %s. The fragment is preserved for recovery.',
                    $path,
                    $failure->getMessage(),
                ),
                $failure,
            );
        }
    }
}
