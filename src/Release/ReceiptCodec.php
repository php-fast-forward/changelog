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

use FastForward\Changelog\Changeset\Parser\ChangesetParserInterface;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\Factory\ReleaseReceiptFactoryInterface;
use JsonException;

/** Validates schema-one receipts and shares their canonical identity with plan construction. */
final readonly class ReceiptCodec implements ReceiptCodecInterface
{
    private const array FIELDS = [
        'schema', 'base_sha', 'current_version', 'next_version', 'impact', 'consumed',
        'historical_versions', 'changelog_file', 'fragment_directory', 'locale', 'template',
        'tag_prefix', 'repository', 'before_changelog_sha256', 'changelog_contents', 'after_changelog_sha256',
        'notes', 'notes_sha256',
    ];
    private const string HASH = '~^[a-f0-9]{64}$~D';
    private const string VERSION = '~^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$~D';
    private const string NEXT_VERSION = '~^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$~D';

    /** Injects typed receipt and diagnostic construction without reading a repository. */
    public function __construct(
        private ReleaseReceiptFactoryInterface $receipts,
        private ReleaseExceptionFactoryInterface $exceptions,
    ) {}

    /** Rejects unknown, duplicate or malformed JSON evidence before checking its content identity. */
    public function decode(string $contents): ReleaseReceipt
    {
        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw $this->exceptions->invalid('Invalid release receipt JSON: ' . $error->getMessage(), $error);
        }
        if (! is_array($data) || array_is_list($data) || ! array_key_exists('id', $data)) {
            throw $this->exceptions->invalid('Release receipt must be an object with an id.');
        }
        preg_match_all('~"(?:\\\\.|[^"\\\\])*"\s*:~', $contents, $keys);
        $seen = [];
        foreach ($keys[0] as $key) {
            $name = json_decode(rtrim(substr($key, 0, strrpos($key, ':'))), flags: JSON_THROW_ON_ERROR);
            if (isset($seen[$name])) {
                throw $this->exceptions->invalid('Duplicate release receipt key: ' . $name);
            }
            $seen[$name] = true;
        }
        $id = $data['id'];
        unset($data['id']);
        $evidence = $this->validate($data);
        if (! is_string($id) || 1 !== preg_match(self::HASH, $id) || ! hash_equals($this->identity($evidence), $id)) {
            throw $this->exceptions->invalid('Release receipt id does not match its evidence.');
        }

        return $this->receipts->create(['id' => $id, ...$evidence]);
    }

    /** Creates stable pretty JSON while sharing exactly one evidence identity algorithm. */
    public function encode(array $evidence): string
    {
        $evidence = $this->validate($evidence);

        return json_encode([
            'id' => $this->identity($evidence), ...$evidence],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . "\n";
    }

    /** Computes identity from the validated known-order evidence, excluding its own ID. */
    private function identity(array $evidence): string
    {
        return hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** Checks every typed field and keeps only direct canonical fragment names eligible for consumption. */
    private function validate(array $evidence): array
    {
        if ([] !== array_diff(array_keys($evidence), self::FIELDS) || [] !== array_diff(
            self::FIELDS,
            array_keys($evidence),
        )) {
            throw $this->exceptions->invalid('Release receipt fields must match schema one exactly.');
        }
        if (1 !== $evidence['schema']) {
            throw $this->exceptions->invalid('Unsupported release receipt schema.');
        }
        if (null !== $evidence['base_sha'] && (! is_string($evidence['base_sha']) || 1 !== preg_match(
            '~^(?:[a-f0-9]{40}|[a-f0-9]{64})$~D',
            $evidence['base_sha'],
        ))) {
            throw $this->exceptions->invalid('Release receipt base_sha must be a complete Git commit hash.');
        }
        foreach (['current_version', 'next_version'] as $field) {
            if ('next_version' === $field && null === $evidence[$field]) {
                continue;
            }
            if (! is_string($evidence[$field]) || 1 !== preg_match(
                'next_version' === $field ? self::NEXT_VERSION : self::VERSION,
                $evidence[$field],
            )) {
                throw $this->exceptions->invalid('Release receipt ' . $field . ' must be a stable semantic version.');
            }
        }
        if (! in_array($evidence['impact'], [null, 'patch', 'minor', 'major'], true)
            || ((null === $evidence['next_version']) !== (null === $evidence['impact']))) {
            throw $this->exceptions->invalid('Release receipt impact must agree with its release version.');
        }
        foreach (['changelog_file', 'fragment_directory'] as $field) {
            if (! is_string($evidence[$field]) || ! $this->relativePath($evidence[$field])) {
                throw $this->exceptions->invalid(
                    'Release receipt ' . $field . ' must be a canonical relative project path.',
                );
            }
        }
        if ($evidence['changelog_file'] === $evidence['fragment_directory']
            || str_starts_with($evidence['changelog_file'], $evidence['fragment_directory'] . '/')) {
            throw $this->exceptions->invalid('Release receipt changelog cannot overlap its fragment directory.');
        }
        if (! in_array($evidence['locale'], ['en', 'pt-BR'], true)
            || ! is_string($evidence['template']) || '' === trim($evidence['template']) || str_contains(
                $evidence['template'],
                "\0",
            )
            || ! is_string($evidence['tag_prefix']) || ('' !== $evidence['tag_prefix'] && 1 !== preg_match(
                '~^[A-Za-z0-9][A-Za-z0-9._/-]*$~D',
                $evidence['tag_prefix'],
            ))
            || (null !== $evidence['repository'] && (! is_string($evidence['repository']) || 1 !== preg_match(
                '~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D',
                $evidence['repository'],
            )))) {
            throw $this->exceptions->invalid('Release receipt contains invalid rendering or repository settings.');
        }
        foreach (['before_changelog_sha256', 'after_changelog_sha256', 'notes_sha256'] as $field) {
            if ('before_changelog_sha256' === $field && null === $evidence[$field]) {
                continue;
            }
            if (! is_string($evidence[$field]) || 1 !== preg_match(self::HASH, $evidence[$field])) {
                throw $this->exceptions->invalid('Release receipt ' . $field . ' must be a lowercase SHA-256 hash.');
            }
        }
        if (! is_string($evidence['changelog_contents']) || ! hash_equals(
            $evidence['after_changelog_sha256'],
            hash('sha256', $evidence['changelog_contents']),
        )) {
            throw $this->exceptions->invalid('Release receipt target changelog does not match after_changelog_sha256.');
        }
        if (! is_string($evidence['notes']) || ! hash_equals(
            $evidence['notes_sha256'],
            hash('sha256', $evidence['notes']),
        )) {
            throw $this->exceptions->invalid('Release receipt exact notes do not match notes_sha256.');
        }
        if (! is_array($evidence['historical_versions']) || ! array_is_list($evidence['historical_versions'])) {
            throw $this->exceptions->invalid('Release receipt historical_versions must be a list.');
        }
        $versions = [];
        foreach ($evidence['historical_versions'] as $version) {
            if (! is_string($version) || 1 !== preg_match(self::VERSION, $version) || isset($versions[$version])) {
                throw $this->exceptions->invalid('Release receipt historical versions must be unique stable versions.');
            }
            $versions[$version] = true;
        }
        if (! is_array($evidence['consumed']) || ([] !== $evidence['consumed'] && array_is_list(
            $evidence['consumed'],
        ))) {
            throw $this->exceptions->invalid('Release receipt consumed must be a fragment path/hash map.');
        }
        if (null === $evidence['next_version'] && [] !== $evidence['consumed']) {
            throw $this->exceptions->invalid('Maintenance receipts cannot consume pending fragments.');
        }
        $prefix = $evidence['fragment_directory'] . '/';
        foreach ($evidence['consumed'] as $path => $hash) {
            if (! is_string($path) || ! str_starts_with($path, $prefix)
                || 1 !== preg_match(ChangesetParserInterface::FILENAME_PATTERN, substr($path, strlen($prefix)))
                || ! is_string($hash) || 1 !== preg_match(self::HASH, $hash)) {
                throw $this->exceptions->invalid(
                    'Release receipt consumed paths must be direct canonical fragments with SHA-256 hashes.',
                );
            }
        }
        ksort($evidence['consumed'], SORT_STRING);

        return array_replace(array_fill_keys(self::FIELDS, null), $evidence);
    }

    /** Rejects absolute, alternate-separator and dot/traversal spellings without host path access. */
    private function relativePath(string $path): bool
    {
        return '' !== $path && ! str_contains($path, "\0") && ! str_contains($path, '\\')
            && ! str_starts_with($path, '/') && ! str_starts_with($path, '-')
            && 1 !== preg_match('~^[A-Za-z]:~', $path)
            && [] === array_intersect(explode('/', $path), ['', '.', '..']);
    }
}
