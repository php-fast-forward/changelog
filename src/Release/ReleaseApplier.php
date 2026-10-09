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

namespace FastForward\Changelog\Release;

use FastForward\Changelog\Changeset\Store\ChangesetStoreInterface;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Validator\ReleaseInputEvidenceValidatorInterface;
use Symfony\Component\Lock\LockFactory;
use Throwable;

/** Applies exact approved bytes under the same directory lock used by fragment creation. */
final readonly class ReleaseApplier implements ReleaseApplierInterface
{
    /** Injects all I/O, locking, evidence and diagnostic boundaries without consulting host state. */
    public function __construct(
        private GitRepositoryInterface $git,
        private ChangesetStoreInterface $fragments,
        private LockFactory $locks,
        private ManagedFileStoreInterface $files,
        private ReceiptCodecInterface $codec,
        private ReleaseExceptionFactoryInterface $exceptions,
        private ReleaseInputEvidenceValidatorInterface $inputs,
        private ReleaseJournalPathResolverInterface $journals,
    ) {}

    /** Writes history and consumes fragments; its Git-internal recovery journal is removed on success. */
    public function apply(ReleasePlan $plan): bool
    {
        if ('none' === $plan->mode()) {
            return false;
        }
        $directory = $this->directory($plan);
        $lock = $this->locks->createLock($this->fragments->lockResource($directory));
        if (! $lock->acquire(true)) {
            throw $this->exceptions->failure(
                'Cannot acquire release lock for ' . $directory . '. No managed files were changed.',
            );
        }
        try {
            $state = $this->preflight($plan);
            if ($state['complete']) {
                $this->files->remove($plan->receiptPath);

                return false;
            }
            $this->inputs->validate($plan);
            if ($state['receipt'] !== $plan->receiptContents) {
                $this->files->write($plan->receiptPath, $plan->receiptContents);
            }
            if ($state['central'] !== $plan->changelogContents) {
                $this->files->write($plan->changelogPath, $plan->changelogContents);
            }
            // Re-read every remaining hash and the full inventory after both durable writes.
            $state = $this->preflight($plan);
            if ([] !== $state['remaining']) {
                $this->fragments->remove($state['remaining']);
            }
            $this->files->remove($plan->receiptPath);

            return true;
        } catch (Throwable $error) {
            throw $this->exceptions->failure('Cannot apply release ' . $plan->id . ': ' . $error->getMessage()
                . ' Retain the changelog and remaining fragments; retry this exact approved plan or its Git-internal recovery journal.', $error);
        } finally {
            $lock->release();
        }
    }

    /** Performs the same evidence checks without acquiring a lock or writing any file. */
    public function isApplied(ReleasePlan $plan): bool
    {
        return 'none' === $plan->mode() || $this->preflight($plan)['complete'];
    }

    /** Resolves the caller's configured fragment directory without reading cwd. */
    private function directory(ReleasePlan $plan): string
    {
        return rtrim(
            str_replace('\\', '/', $plan->options->workingDirectory),
            '/',
        ) . '/' . $plan->options->fragmentDirectory;
    }

    /** Checks approved schema, options, exact paths and every immutable plan field before I/O. */
    private function evidence(ReleasePlan $plan): array
    {
        $data = $this->codec->decode($plan->receiptContents)->data;
        $options = $plan->options;
        $root = rtrim(str_replace('\\', '/', $options->workingDirectory), '/');
        $consumed = [];
        foreach ($data['consumed'] as $relative => $hash) {
            $consumed[$root . '/' . $relative] = $hash;
        }
        $planned = $plan->consumed;
        ksort($planned, SORT_STRING);
        if ($data['id'] !== $plan->id || $data['base_sha'] !== $plan->baseSha
            || $data['current_version'] !== $plan->currentVersion || $data['next_version'] !== $plan->nextVersion
            || $data['impact'] !== $plan->impact || $data['historical_versions'] !== $plan->historicalVersions
            || $data['notes'] !== $plan->notes || $consumed !== $planned
            || $data['changelog_contents'] !== $plan->changelogContents
            || $data['after_changelog_sha256'] !== hash('sha256', $plan->changelogContents)
            || (! $plan->resuming && $data['before_changelog_sha256'] !== (null === $plan->originalChangelog ? null : hash(
                'sha256',
                $plan->originalChangelog,
            )))
            || $plan->changelogPath !== $root . '/' . $options->changelogFile
            || $plan->receiptPath !== $this->journals->resolve($options)) {
            throw $this->exceptions->invalid(
                'Release plan does not match its approved receipt evidence or managed paths.',
            );
        }
        foreach (['fragment_directory' => 'fragmentDirectory', 'changelog_file' => 'changelogFile', 'locale' => 'locale',
            'template' => 'template', 'tag_prefix' => 'tagPrefix', 'repository' => 'repository'] as $key => $property) {
            if ($data[$key] !== $options->$property) {
                throw $this->exceptions->invalid(
                    'Release plan setting ' . $key . ' differs from its approved receipt.',
                );
            }
        }

        return $data;
    }

    /**
     * Reads full inventory and all hashes before allowing any write or removal.
     * Missing consumed files are allowed only after the approved history is durable.
     * @return array{central:?string,receipt:?string,remaining:list<string>,complete:bool}
     */
    private function preflight(ReleasePlan $plan): array
    {
        $data = $this->evidence($plan);
        if (null !== $plan->baseSha) {
            $validBase = $plan->resuming
                ? $this->git->isAncestor($plan->options->workingDirectory, $plan->baseSha, 'HEAD')
                : $this->git->resolveRef($plan->options->workingDirectory, 'HEAD') === $plan->baseSha;
            if (! $validBase) {
                throw $this->exceptions->failure(
                    'Repository HEAD no longer matches or descends from the approved release base.',
                );
            }
        }
        $central = $this->files->read($plan->changelogPath);
        $receipt = $this->files->read($plan->receiptPath);
        $afterCentral = $central === $plan->changelogContents;
        $afterReceipt = $receipt === $plan->receiptContents;
        $beforeCentral = $central === $plan->originalChangelog
            && $data['before_changelog_sha256'] === (null === $central ? null : hash('sha256', $central));
        if (! $afterCentral && ! $beforeCentral) {
            throw $this->exceptions->failure('Central changelog changed since approval: ' . $plan->changelogPath);
        }
        if (null !== $receipt && ! $afterReceipt && $receipt !== $plan->originalReceipt) {
            throw $this->exceptions->failure('Release receipt changed since approval: ' . $plan->receiptPath);
        }
        if (null === $plan->nextVersion && [] === $plan->consumed) {
            return ['central' => $central, 'receipt' => $receipt, 'remaining' => [],
                'complete' => $afterCentral];
        }
        $paths = $this->fragments->paths($this->directory($plan));
        if (null === $paths) {
            throw $this->exceptions->failure('Unsafe fragment directory: ' . $this->directory($plan));
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        $approved = array_keys($plan->consumed);
        sort($approved, SORT_STRING);
        if ([] !== array_diff($paths, $approved)) {
            throw $this->exceptions->failure('New or unapproved fragments appeared after release planning.');
        }
        if (! $afterCentral && $paths !== $approved) {
            throw $this->exceptions->failure('Approved fragments are missing before the changelog is durable.');
        }
        foreach ($paths as $path) {
            $contents = $this->fragments->read($path);
            if (null === $contents || ! hash_equals($plan->consumed[$path], hash('sha256', $contents))) {
                throw $this->exceptions->failure('Approved fragment is unsafe or changed: ' . $path);
            }
        }

        return ['central' => $central, 'receipt' => $receipt, 'remaining' => $paths,
            'complete' => $afterCentral && [] === $paths];
    }
}
