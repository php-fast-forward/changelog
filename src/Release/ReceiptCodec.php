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
use InvalidArgumentException;
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

    /**
     * Rejects unknown, duplicate or malformed JSON evidence before checking its content identity.
     *
     * @param string $contents Complete untrusted recovery JSON, including its deterministic identity.
     *
     * @return ReleaseReceipt Schema-validated immutable recovery evidence with verified paths, hashes and identity.
     *
     * @throws \InvalidArgumentException When JSON, schema fields, repeated keys, content hashes or the deterministic identity are invalid.
     */
    public function decode(string $contents): ReleaseReceipt
    {
        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                'Invalid release receipt JSON: ' . $error->getMessage(),
                previous: $error,
            );
        }
        if (! is_array($data) || array_is_list($data) || ! array_key_exists('id', $data)) {
            throw new InvalidArgumentException('Release receipt must be an object with an id.');
        }
        preg_match_all('~"(?:\\\\.|[^"\\\\])*"\s*:~', $contents, $keys);
        $seen = [];
        foreach ($keys[0] as $key) {
            $name = json_decode(rtrim(substr($key, 0, strrpos($key, ':'))), flags: JSON_THROW_ON_ERROR);
            if (isset($seen[$name])) {
                throw new InvalidArgumentException('Duplicate release receipt key: ' . $name);
            }
            $seen[$name] = true;
        }
        $id = $data['id'];
        unset($data['id']);
        $evidence = $this->validate($data);
        if (! is_string($id) || 1 !== preg_match(self::HASH, $id) || ! hash_equals($this->identity($evidence), $id)) {
            throw new InvalidArgumentException('Release receipt id does not match its evidence.');
        }

        return new ReleaseReceipt(['id' => $id, ...$evidence]);
    }

    /**
     * Creates stable pretty JSON while sharing exactly one evidence identity algorithm.
     *
     * @param array<string,mixed> $evidence Complete schema-one transaction fields, excluding the identity; encode validates them before serialization.
     *
     * @return string Canonical recovery JSON with ordered fields and its deterministic SHA-256 plan identity.
     *
     * @throws \InvalidArgumentException When evidence violates the canonical recovery schema.
     * @throws \JsonException            When schema-valid strings cannot be encoded as JSON.
     */
    public function encode(array $evidence): string
    {
        $evidence = $this->validate($evidence);

        return json_encode([
            'id' => $this->identity($evidence), ...$evidence],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . "\n";
    }

    /**
     * Computes identity from the validated known-order evidence, excluding its own ID.
     *
     * @param array<string,mixed> $evidence Complete schema-one transaction fields, excluding the identity; encode validates them before serialization.
     *
     * @return string Lowercase SHA-256 of validated evidence in stable key order, excluding the identity field itself.
     */
    private function identity(array $evidence): string
    {
        return hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Validates the complete receipt before normalizing its canonical key order.
     *
     * @param array<string,mixed> $evidence Untrusted decoded receipt fields without their identity.
     *
     * @return array<string,mixed> Validated evidence in stable schema order.
     */
    private function validate(array $evidence): array
    {
        $this->assertSchema($evidence);
        $this->assertVersions($evidence);
        $this->assertSettings($evidence);
        $this->assertContentHashes($evidence);
        $this->assertHistoricalVersions($evidence);
        $this->assertConsumedFragments($evidence);

        ksort($evidence['consumed'], SORT_STRING);

        return array_replace(array_fill_keys(self::FIELDS, null), $evidence);
    }

    /**
     * Requires the exact schema and an optional complete base commit identity.
     *
     * @param array<string,mixed> $evidence Receipt fields after the preceding validation phases.
     *
     * @return void Leaves valid fields unchanged or raises the configured validation exception.
     */
    private function assertSchema(array $evidence): void
    {
        if ([] !== array_diff(array_keys($evidence), [...self::FIELDS, 'resolve_pull_requests']) || [] !== array_diff(
            self::FIELDS,
            array_keys($evidence),
        )) {
            throw new InvalidArgumentException('Release receipt fields must match schema one exactly.');
        }
        if (1 !== $evidence['schema']) {
            throw new InvalidArgumentException('Unsupported release receipt schema.');
        }
        if (array_key_exists('resolve_pull_requests', $evidence) && true !== $evidence['resolve_pull_requests']) {
            throw new InvalidArgumentException('Release receipt PR discovery must be true when present.');
        }
        if (null !== $evidence['base_sha'] && (! is_string($evidence['base_sha']) || 1 !== preg_match(
            '~^(?:[a-f0-9]{40}|[a-f0-9]{64})$~D',
            $evidence['base_sha'],
        ))) {
            throw new InvalidArgumentException('Release receipt base_sha must be a complete Git commit hash.');
        }
    }

    /**
     * Checks stable versions and requires impact to agree with a versioned release.
     *
     * @param array<string,mixed> $evidence Receipt fields after the preceding validation phases.
     *
     * @return void Leaves valid fields unchanged or raises the configured validation exception.
     */
    private function assertVersions(array $evidence): void
    {
        foreach (['current_version', 'next_version'] as $field) {
            if ('next_version' === $field && null === $evidence[$field]) {
                continue;
            }
            if (! is_string($evidence[$field]) || 1 !== preg_match(
                'next_version' === $field ? self::NEXT_VERSION : self::VERSION,
                $evidence[$field],
            )) {
                throw new InvalidArgumentException('Release receipt ' . $field . ' must be a stable semantic version.');
            }
        }
        if (! in_array($evidence['impact'], [null, 'patch', 'minor', 'major'], true)
            || ((null === $evidence['next_version']) !== (null === $evidence['impact']))) {
            throw new InvalidArgumentException('Release receipt impact must agree with its release version.');
        }
    }

    /**
     * Validates canonical project paths and the stored presentation settings.
     *
     * @param array<string,mixed> $evidence Receipt fields after the preceding validation phases.
     *
     * @return void Leaves valid fields unchanged or raises the configured validation exception.
     */
    private function assertSettings(array $evidence): void
    {
        foreach (['changelog_file', 'fragment_directory'] as $field) {
            if (! is_string($evidence[$field]) || ! $this->relativePath($evidence[$field])) {
                throw new InvalidArgumentException(
                    'Release receipt ' . $field . ' must be a canonical relative project path.',
                );
            }
        }
        if ($evidence['changelog_file'] === $evidence['fragment_directory']
            || str_starts_with($evidence['changelog_file'], $evidence['fragment_directory'] . '/')) {
            throw new InvalidArgumentException('Release receipt changelog cannot overlap its fragment directory.');
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
            throw new InvalidArgumentException('Release receipt contains invalid rendering or repository settings.');
        }
    }

    /**
     * Checks stored digest spellings and binds exact target and notes bytes.
     *
     * @param array<string,mixed> $evidence Receipt fields after the preceding validation phases.
     *
     * @return void Leaves valid fields unchanged or raises the configured validation exception.
     */
    private function assertContentHashes(array $evidence): void
    {
        foreach (['before_changelog_sha256', 'after_changelog_sha256', 'notes_sha256'] as $field) {
            if ('before_changelog_sha256' === $field && null === $evidence[$field]) {
                continue;
            }
            if (! is_string($evidence[$field]) || 1 !== preg_match(self::HASH, $evidence[$field])) {
                throw new InvalidArgumentException('Release receipt ' . $field . ' must be a lowercase SHA-256 hash.');
            }
        }
        if (! is_string($evidence['changelog_contents']) || ! hash_equals(
            $evidence['after_changelog_sha256'],
            hash('sha256', $evidence['changelog_contents']),
        )) {
            throw new InvalidArgumentException(
                'Release receipt target changelog does not match after_changelog_sha256.',
            );
        }
        if (! is_string($evidence['notes']) || ! hash_equals(
            $evidence['notes_sha256'],
            hash('sha256', $evidence['notes']),
        )) {
            throw new InvalidArgumentException('Release receipt exact notes do not match notes_sha256.');
        }
    }

    /**
     * Requires a unique ordered list of stable imported release versions.
     *
     * @param array<string,mixed> $evidence Receipt fields after the preceding validation phases.
     *
     * @return void Leaves valid fields unchanged or raises the configured validation exception.
     */
    private function assertHistoricalVersions(array $evidence): void
    {
        if (! is_array($evidence['historical_versions']) || ! array_is_list($evidence['historical_versions'])) {
            throw new InvalidArgumentException('Release receipt historical_versions must be a list.');
        }
        $versions = [];
        foreach ($evidence['historical_versions'] as $version) {
            if (! is_string($version) || 1 !== preg_match(self::VERSION, $version) || isset($versions[$version])) {
                throw new InvalidArgumentException(
                    'Release receipt historical versions must be unique stable versions.',
                );
            }
            $versions[$version] = true;
        }
    }

    /**
     * Requires direct canonical fragment paths with their accepted content hashes.
     *
     * @param array<string,mixed> $evidence Receipt fields after the preceding validation phases.
     *
     * @return void Leaves valid fields unchanged or raises the configured validation exception.
     */
    private function assertConsumedFragments(array $evidence): void
    {
        if (! is_array($evidence['consumed']) || ([] !== $evidence['consumed'] && array_is_list(
            $evidence['consumed'],
        ))) {
            throw new InvalidArgumentException('Release receipt consumed must be a fragment path/hash map.');
        }
        if (null === $evidence['next_version'] && [] !== $evidence['consumed']) {
            throw new InvalidArgumentException('Maintenance receipts cannot consume pending fragments.');
        }
        $prefix = $evidence['fragment_directory'] . '/';
        foreach ($evidence['consumed'] as $path => $hash) {
            if (! is_string($path) || ! str_starts_with($path, $prefix)
                || 1 !== preg_match(ChangesetParserInterface::FILENAME_PATTERN, substr($path, strlen($prefix)))
                || ! is_string($hash) || 1 !== preg_match(self::HASH, $hash)) {
                throw new InvalidArgumentException(
                    'Release receipt consumed paths must be direct canonical fragments with SHA-256 hashes.',
                );
            }
        }
    }

    /**
     * Rejects absolute, alternate-separator and dot/traversal spellings without host path access.
     *
     * @param string $path Caller-selected managed path, validated before filesystem effects.
     *
     * @return bool Whether the spelling is canonical project-relative syntax without absolute, option-like, alternate-separator or traversal segments.
     */
    private function relativePath(string $path): bool
    {
        return '' !== $path && ! str_contains($path, "\0") && ! str_contains($path, '\\')
            && ! str_starts_with($path, '/') && ! str_starts_with($path, '-')
            && 1 !== preg_match('~^[A-Za-z]:~', $path)
            && [] === array_intersect(explode('/', $path), ['', '.', '..']);
    }
}
