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

namespace FastForward\Changelog\Publication;

use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Publication\Factory\PublicationResultFactoryInterface;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Validator\PublicationEvidenceValidatorInterface;
use Throwable;

/** Reconciles create-only GitHub publication after independent commit/domain evidence proof. */
final readonly class PublicationService implements PublicationServiceInterface
{
    /** Injects proof, authenticated HTTP, typed output and failures without performing any I/O. */
    public function __construct(
        private PublicationEvidenceValidatorInterface $evidence,
        private GitHubClientInterface $github,
        private PublicationResultFactoryInterface $results,
        private ReleaseExceptionFactoryInterface $exceptions,
    ) {}

    /** Creates only missing remote objects and verifies every uncertain response by a fresh read. */
    public function publish(ReleaseOptions $options, string $approvedSha, bool $dryRun = false): PublicationResult
    {
        $evidence = $this->evidence->validate($options, $approvedSha);
        if (null === $evidence->version) {
            return $this->results->create('maintenance', null, null, $evidence->sha, null, []);
        }
        $root = '/repos/' . $evidence->repository;
        $tag = $evidence->tag;
        $sha = $this->tagSha($root, $tag);
        $release = $this->github->request('GET', $root . '/releases/tags/' . rawurlencode($tag));
        if (null !== $sha && $sha !== $evidence->sha) {
            throw $this->exceptions->failure('The release tag already points to another commit; it will never be moved.');
        }
        if (null !== $release) {
            $this->releaseUrl($release, $tag, $evidence->notes);
            if (null === $sha) {
                throw $this->exceptions->failure('An existing release has no verified matching tag; publication refused.');
            }
        }
        $actions = [];
        if (null === $sha) {
            $actions[] = 'create_tag';
        }
        if (null === $release) {
            $actions[] = 'create_release';
        }
        if ($dryRun) {
            return $this->results->create(
                'dry-run',
                $evidence->version,
                $tag,
                $evidence->sha,
                null === $release ? null : $this->releaseUrl($release, $tag, $evidence->notes),
                $actions,
            );
        }
        if (null === $sha) {
            $error = $this->create($root . '/git/refs', ['ref' => 'refs/tags/' . $tag, 'sha' => $evidence->sha]);
            $sha = $this->tagSha($root, $tag);
            if ($sha !== $evidence->sha) {
                throw $this->exceptions->failure('Tag creation outcome is missing or conflicts with the approved commit; retry after checking remote state.', $error);
            }
        }
        if (null === $release) {
            if ($this->tagSha($root, $tag) !== $evidence->sha) {
                throw $this->exceptions->failure('The verified tag changed before release creation; publication refused.');
            }
            $error = $this->create($root . '/releases', ['tag_name' => $tag, 'target_commitish' => $evidence->sha,
                'name' => $tag, 'body' => $evidence->notes, 'draft' => false, 'prerelease' => false, 'generate_release_notes' => false]);
            $release = $this->github->request('GET', $root . '/releases/tags/' . rawurlencode($tag));
            if (null === $release) {
                throw $this->exceptions->failure('The matching tag is retained but release creation is unverified; retry the same approved SHA.', $error);
            }
        }
        $url = $this->releaseUrl($release, $tag, $evidence->notes);
        if ($this->tagSha($root, $tag) !== $evidence->sha) {
            throw $this->exceptions->failure('The release tag changed during publication; no tag rewrite or release update was attempted.');
        }
        return $this->results->create([] === $actions ? 'unchanged' : 'published', $evidence->version, $tag, $evidence->sha, $url, $actions);
    }

    /** Treats a failed create response as uncertain; callers MUST verify the corresponding persisted object. */
    private function create(string $path, array $body): ?Throwable
    {
        try {
            $this->github->request('POST', $path, $body);
            return null;
        } catch (Throwable $error) {
            // A lost response or concurrent creation is reconciled through authoritative GET evidence.
            return $error;
        }
    }

    /** Dereferences exact lightweight/annotated tag objects through bounded trusted relative endpoints. */
    private function tagSha(string $root, string $tag): ?string
    {
        $reference = $this->github->request('GET', $root . '/git/ref/tags/' . rawurlencode($tag));
        if (null === $reference) {
            return null;
        }
        if (($reference['ref'] ?? null) !== 'refs/tags/' . $tag || ! is_array($reference['object'] ?? null)) {
            throw $this->exceptions->failure('GitHub returned an invalid exact tag reference.');
        }
        $object = $reference['object'];
        $seen = [];
        for ($depth = 0; ; ++$depth) {
            $sha = $object['sha'] ?? null;
            $type = $object['type'] ?? null;
            if (! is_string($sha) || 1 !== preg_match('~^(?:[a-f0-9]{40}|[a-f0-9]{64})$~D', $sha)) {
                throw $this->exceptions->failure('GitHub returned an invalid tag object identity.');
            }
            if ('commit' === $type) {
                return $sha;
            }
            if ('tag' !== $type || isset($seen[$sha]) || $depth >= 8) {
                throw $this->exceptions->failure('GitHub tag objects must terminate in a commit without cycles or excessive nesting.');
            }
            $seen[$sha] = true;
            $annotated = $this->github->request('GET', $root . '/git/tags/' . $sha);
            if (null === $annotated || ($annotated['sha'] ?? null) !== $sha || ! is_array($annotated['object'] ?? null)) {
                throw $this->exceptions->failure('GitHub returned an invalid annotated tag object.');
            }
            $object = $annotated['object'];
        }
    }

    /** Requires exact central notes, stable published state and a usable HTTPS release URL. */
    private function releaseUrl(array $release, string $tag, string $notes): string
    {
        $url = $release['html_url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        if (($release['tag_name'] ?? null) !== $tag || ($release['body'] ?? null) !== $notes
            || false !== ($release['draft'] ?? null) || false !== ($release['prerelease'] ?? null)
            || ! is_array($parts) || 'https' !== ($parts['scheme'] ?? null) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || 1 === preg_match('~[\x00-\x20\x7f]~', $url)) {
            throw $this->exceptions->failure('The existing release differs from the exact approved tag, notes or published state.');
        }
        return $url;
    }
}
