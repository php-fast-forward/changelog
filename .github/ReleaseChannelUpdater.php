<?php

declare(strict_types=1);

namespace FastForward\Changelog\Repository;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Maintains repository-only major branches from published release evidence; never creates a release or tag. */
final readonly class ReleaseChannelUpdater
{
    private const string ZERO = '0000000000000000000000000000000000000000';

    /**
     * Injects the already authenticated GitHub transport without reading host credentials.
     *
     * @param HttpClientInterface $http Transport for GitHub REST reads and the conditional GraphQL mutation.
     */
    public function __construct(
        private HttpClientInterface $http,
    ) {}

    /**
     * Advances only the selected major branch after proving published source and its old commit.
     *
     * @param string $repository Exact owner/name of the repository that owns both the release and channel.
     * @param string $tag        Complete stable release tag whose major selects the branch.
     * @param string $sha        Expected full commit SHA of the published tag, including annotated-tag peeling.
     *
     * @return array{state: string, channel: string, tag: string, sha: string} Confirmed channel state and released destination.
     *
     * @throws RuntimeException                                                    When publication, ownership, monotonicity or conditional-write evidence is incomplete or conflicting.
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface When the GitHub transport cannot complete a request.
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface  When GitHub returns undecodable JSON evidence.
     */
    public function update(string $repository, string $tag, string $sha): array
    {
        $selected = self::version($tag);
        if (null === $selected || ! self::sha($sha) || 1 !== preg_match(
            '~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D',
            $repository,
        )) {
            throw new RuntimeException('Select an exact repository, complete stable release tag and full commit SHA.');
        }

        $channel = 'v' . $selected['major'];
        $evidence = $this->releaseEvidence($repository, $tag, $sha);
        $releases = $this->catalog($repository, $selected['major']);
        if (! self::contains($releases, $evidence)) {
            throw new RuntimeException('Selected release is absent from the complete catalog.');
        }

        if (null !== $this->reference($repository, 'tags/' . $channel)) {
            throw new RuntimeException('A conflicting channel tag takes precedence over the branch.');
        }

        $current = $this->reference($repository, 'heads/' . $channel);
        if (null !== $current && 'commit' !== $current['type']) {
            throw new RuntimeException('The channel branch must point directly to a commit.');
        }

        $settled = $this->settledState($repository, $tag, $sha, $channel, $releases[0], $current);
        if (null !== $settled) {
            return $settled;
        }

        $previous = $this->previousRelease($repository, $sha, $selected, $releases, $current);
        $this->revalidate($repository, $tag, $sha, $channel, $selected['major'], $evidence, $previous, $current);
        $this->advance($repository, $channel, $sha, $current);

        return [
            'state' => null === $current ? 'created' : 'advanced',
            'channel' => $channel,
            'tag' => $tag,
            'sha' => $sha,
        ];
    }

    /**
     * Recognizes idempotent and stale selectors without writing a reference.
     *
     * @param string                                $repository Verified target owner/name.
     * @param string                                $tag        Complete stable release selected for this operation.
     * @param string                                $sha        Approved commit of the selected release.
     * @param string                                $channel    Major branch selected by the full tag.
     * @param array<string, mixed>                  $latest     Newest published release of the selected major.
     * @param array{sha: string, type: string}|null $current    Exact current branch, absent for first creation.
     *
     * @return array{state: string, channel: string, tag: string, sha: string}|null Confirmed read-only state, or null when an update is needed.
     *
     * @throws RuntimeException When a stale selector cannot confirm an already-current newest channel.
     */
    private function settledState(
        string $repository,
        string $tag,
        string $sha,
        string $channel,
        array $latest,
        ?array $current,
    ): ?array {
        if ($latest['tag_name'] !== $tag) {
            if (null !== $current && $current['sha'] === $this->commit($repository, $latest['tag_name'])) {
                return [
                    'state' => 'superseded',
                    'channel' => $channel,
                    'tag' => $latest['tag_name'],
                    'sha' => $current['sha'],
                ];
            }

            throw new RuntimeException('Select the newest published stable release of this major for recovery.');
        }

        if (null !== $current && $current['sha'] === $sha) {
            return [
                'state' => 'current',
                'channel' => $channel,
                'tag' => $tag,
                'sha' => $sha,
            ];
        }

        return null;
    }

    /**
     * Binds an existing channel to an older publication and proves a forward-only transition.
     *
     * @param string                                $repository Verified target owner/name.
     * @param string                                $sha        Approved destination commit.
     * @param array{parts: list<string>}            $selected   Parsed destination version for precedence comparison.
     * @param non-empty-list<array<string, mixed>>  $releases   Complete published catalog for this major.
     * @param array{sha: string, type: string}|null $current    Existing branch whose ancestry must be preserved.
     *
     * @return array<string, mixed>|null Previous published release, or null for first creation.
     *
     * @throws RuntimeException When the existing branch is unrelated, newer or cannot fast-forward.
     */
    private function previousRelease(
        string $repository,
        string $sha,
        array $selected,
        array $releases,
        ?array $current,
    ): ?array {
        if (null === $current) {
            return null;
        }

        $previous = array_find(
            $releases,
            fn(array $release): bool => $this->commit($repository, $release['tag_name']) === $current['sha'],
        );

        if (null === $previous || self::compare(self::version($previous['tag_name']), $selected) >= 0) {
            throw new RuntimeException('The existing branch is not an older published stable release of this major.');
        }

        $comparison = $this->api('GET', '/repos/' . $repository . '/compare/' . $current['sha'] . '...' . $sha);
        if ('ahead' !== ($comparison['status'] ?? null) || $current['sha'] !== ($comparison['merge_base_commit']['sha'] ?? null)) {
            throw new RuntimeException('Channel updates must fast-forward from the verified released commit.');
        }

        return $previous;
    }

    /**
     * Rechecks publication and tag identities immediately before the conditional write.
     *
     * @param string                                $repository Verified target owner/name.
     * @param string                                $tag        Complete stable release selected for the operation.
     * @param string                                $sha        Approved commit that the selected tag must still resolve to.
     * @param string                                $channel    Major branch whose homonymous tag must remain absent.
     * @param string                                $major      Canonical numeric major used to reload the complete catalog.
     * @param array<string, mixed>                  $evidence   Original selected publication identity.
     * @param array<string, mixed>|null             $previous   Older publication backing the existing channel.
     * @param array{sha: string, type: string}|null $current    Original branch evidence retained for the write lease.
     *
     * @return void Publication evidence remains coherent; no reference has been written.
     *
     * @throws RuntimeException When selected, previous or conflicting-tag evidence changed.
     */
    private function revalidate(
        string $repository,
        string $tag,
        string $sha,
        string $channel,
        string $major,
        array $evidence,
        ?array $previous,
        ?array $current,
    ): void {
        $finalEvidence = $this->releaseEvidence($repository, $tag, $sha);
        $finalCatalog = $this->catalog($repository, $major);
        $releaseChanged = $finalEvidence['id'] !== $evidence['id'] || $finalCatalog[0]['tag_name'] !== $tag
            || $finalCatalog[0]['id'] !== $evidence['id'];
        if ($releaseChanged || null !== $this->reference($repository, 'tags/' . $channel)) {
            throw new RuntimeException('Release or conflicting-tag evidence changed before the channel update.');
        }

        if (null !== $previous && (! self::contains($finalCatalog, $previous)
            || $this->commit($repository, $previous['tag_name']) !== $current['sha'])) {
            throw new RuntimeException('The previous channel release evidence changed before the update.');
        }
    }

    /**
     * Applies one server-side compare-and-swap and confirms the resulting exact branch.
     *
     * @param string                                $repository Verified target owner/name and expected API repository identity.
     * @param string                                $channel    Major branch to create or advance, never a tag.
     * @param string                                $sha        Approved released commit for the new branch target.
     * @param array{sha: string, type: string}|null $current    Original branch evidence, or null for an absence lease.
     *
     * @return void The conditional update was accepted and its released destination confirmed.
     *
     * @throws RuntimeException When repository identity, write lease or final confirmation cannot be proven.
     */
    private function advance(string $repository, string $channel, string $sha, ?array $current): void
    {
        $metadata = $this->api('GET', '/repos/' . $repository);
        $repositoryId = $metadata['node_id'] ?? null;
        if (! is_string($repositoryId) || '' === $repositoryId || $repository !== ($metadata['full_name'] ?? null)) {
            throw new RuntimeException('The repository identity does not match the channel target.');
        }

        $mutation = $this->api('POST', '/graphql', [
            'query' => 'mutation($input: UpdateRefsInput!) { updateRefs(input: $input) { clientMutationId } }',
            'variables' => ['input' => [
                'repositoryId' => $repositoryId,
                'refUpdates' => [[
                    'name' => 'refs/heads/' . $channel,
                    'beforeOid' => $current['sha'] ?? self::ZERO,
                    'afterOid' => $sha,
                    'force' => false,
                ]],
            ]],
        ]);
        if (! empty($mutation['errors']) || ! isset($mutation['data']['updateRefs'])) {
            $types = [];
            $errors = is_array($mutation['errors'] ?? null) ? $mutation['errors'] : [];
            foreach ($errors as $error) {
                $type = is_array($error) ? ($error['type'] ?? null) : null;
                if (in_array($type, ['FORBIDDEN', 'UNAUTHORIZED', 'NOT_FOUND', 'UNPROCESSABLE', 'CONFLICT'], true)) {
                    $types[] = $type;
                }
            }
            $reason = [] === $types ? 'unknown response' : implode(', ', array_unique($types));

            throw new RuntimeException(
                'The conditional channel update was rejected (' . $reason . '); verify token permissions and the ref before retrying.',
            );
        }

        $updated = $this->reference($repository, 'heads/' . $channel);
        if (null === $updated || 'commit' !== $updated['type'] || $sha !== $updated['sha']) {
            throw new RuntimeException('The channel update could not be confirmed; rerun the same verified selector.');
        }
    }

    /**
     * Reads one exact reference without treating authorization or transport failures as absence.
     *
     * @param string $repository Verified target owner/name.
     * @param string $ref        Repository-relative tags/ or heads/ reference selected by this updater.
     *
     * @return array{sha: string, type: string}|null Exact object, or null only for HTTP 404.
     *
     * @throws RuntimeException When the server returns a malformed or different reference.
     */
    private function reference(string $repository, string $ref): ?array
    {
        $data = $this->api('GET', '/repos/' . $repository . '/git/ref/' . $ref, allowMissing: true);
        if (null === $data) {
            return null;
        }
        if ('refs/' . $ref !== ($data['ref'] ?? null) || ! self::sha($data['object']['sha'] ?? null)
            || ! is_string($data['object']['type'] ?? null)) {
            throw new RuntimeException('Malformed exact Git reference evidence.');
        }

        return $data['object'];
    }

    /**
     * Peels at most sixteen release-tag objects to a full commit without guessing from release text.
     *
     * @param string $repository Verified target owner/name.
     * @param string $tag        Complete published release tag to resolve.
     *
     * @return string Full commit SHA proven by the Git object chain.
     *
     * @throws RuntimeException When the tag is absent, malformed, non-commit or exceeds the bound.
     */
    private function commit(string $repository, string $tag): string
    {
        $object = $this->reference($repository, 'tags/' . $tag);
        for ($depth = 0; null !== $object && $depth < 16; ++$depth) {
            if ('commit' === $object['type']) {
                return $object['sha'];
            }
            if ('tag' !== $object['type']) {
                break;
            }
            $data = $this->api('GET', '/repos/' . $repository . '/git/tags/' . $object['sha']);
            if (($data['sha'] ?? null) !== $object['sha']) {
                throw new RuntimeException('Annotated tag object identity does not match the requested OID.');
            }
            $object = $data['object'] ?? null;
            if (! is_array($object) || ! self::sha($object['sha'] ?? null) || ! is_string($object['type'] ?? null)) {
                break;
            }
        }

        throw new RuntimeException('Release tag does not resolve to a bounded commit.');
    }

    /**
     * Binds an existing stable published release to its exact current tag commit.
     *
     * @param string $repository Verified target owner/name.
     * @param string $tag        Complete release selector supplied by publication or manual recovery.
     * @param string $sha        Expected approved release commit.
     *
     * @return array<string, mixed> Published release identity for subsequent catalog revalidation.
     *
     * @throws RuntimeException When release state, tag or commit disagrees with the selector.
     */
    private function releaseEvidence(string $repository, string $tag, string $sha): array
    {
        $release = $this->api('GET', '/repos/' . $repository . '/releases/tags/' . rawurlencode($tag));
        if (! self::published($release) || $tag !== $release['tag_name'] || $sha !== $this->commit($repository, $tag)) {
            throw new RuntimeException('Published stable release, selected tag and expected SHA do not match.');
        }

        return $release;
    }

    /**
     * Loads the complete bounded release catalog and orders one major by SemVer precedence.
     *
     * @param string $repository Verified target owner/name.
     * @param string $major      Canonical numeric major selected by the input tag.
     *
     * @return non-empty-list<array<string, mixed>> Published stable releases, newest first.
     *
     * @throws RuntimeException When the catalog is incomplete, malformed, empty or has an ambiguous latest version.
     */
    private function catalog(string $repository, string $major): array
    {
        $releases = [];
        for ($page = 1; $page <= 10; ++$page) {
            $data = $this->api('GET', '/repos/' . $repository . '/releases?per_page=100&page=' . $page);
            if (! array_is_list($data) || count($data) > 100) {
                throw new RuntimeException('Malformed release catalog.');
            }
            foreach ($data as $release) {
                if (! is_array($release) || ! is_bool($release['draft'] ?? null)
                    || ! is_bool($release['prerelease'] ?? null) || ! is_string($release['tag_name'] ?? null)) {
                    throw new RuntimeException('Partial release catalog metadata cannot establish the latest version.');
                }
                if ($release['draft'] || $release['prerelease']) {
                    continue;
                }
                $parsed = self::version($release['tag_name']);
                if (null === $parsed) {
                    continue;
                }
                if (! self::published($release)) {
                    throw new RuntimeException(
                        'Partial stable publication evidence cannot be omitted from the catalog.',
                    );
                }
                if ($parsed['major'] === $major) {
                    $releases[] = $release;
                }
            }
            if (count($data) < 100) {
                if ([] === $releases || count(array_unique(array_column($releases, 'tag_name'))) !== count($releases)) {
                    throw new RuntimeException('Empty or duplicate published release catalog.');
                }
                usort(
                    $releases,
                    static fn(array $left, array $right): int => self::compare(self::version(
                        $right['tag_name'],
                    ), self::version(
                        $left['tag_name'],
                    )),
                );
                if (count($releases) > 1 && 0 === self::compare(
                    self::version($releases[0]['tag_name']),
                    self::version($releases[1]['tag_name']),
                )) {
                    throw new RuntimeException('Build-only release ties cannot choose a unique channel destination.');
                }

                return $releases;
            }
        }

        throw new RuntimeException('Release catalog exceeds the bounded channel verification limit.');
    }

    /**
     * Performs one repository API operation without exposing authentication in diagnostics.
     *
     * @param string                    $method       HTTP method selected by the updater.
     * @param string                    $path         Absolute GitHub API path without a user-supplied host.
     * @param array<string, mixed>|null $body         Structured GraphQL mutation, absent for reads.
     * @param bool                      $allowMissing Whether HTTP 404 is an expected absent exact ref.
     *
     * @return array<array-key, mixed>|null Decoded API evidence, or an explicitly allowed missing ref.
     *
     * @throws RuntimeException When HTTP status or decoded response cannot establish the requested evidence.
     */
    private function api(string $method, string $path, ?array $body = null, bool $allowMissing = false): ?array
    {
        $response = $this->http->request(
            $method,
            'https://api.github.com' . $path,
            null === $body ? [] : ['json' => $body],
        );
        $status = $response->getStatusCode();
        if ($allowMissing && 404 === $status) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('GitHub release-channel request failed with HTTP ' . $status . '.');
        }

        return $response->toArray(false);
    }

    /**
     * Recognizes concrete, public stable publication evidence.
     *
     * @param array<array-key, mixed> $release Decoded release metadata from the target repository.
     *
     * @return bool Whether identity, publication time and a stable full tag are all present.
     */
    private static function published(array $release): bool
    {
        return false === ($release['draft'] ?? null) && false === ($release['prerelease'] ?? null)
            && is_int($release['id'] ?? null) && $release['id'] > 0
            && self::publicationTime($release['published_at'] ?? null)
            && null !== self::version($release['tag_name'] ?? null);
    }

    /**
     * Requires the canonical UTC timestamp returned by GitHub instead of interpreting relative dates.
     *
     * @param mixed $value Publication timestamp from release metadata.
     *
     * @return bool Whether parsing and formatting preserve an exact valid UTC calendar instant.
     */
    private static function publicationTime(mixed $value): bool
    {
        if (! is_string($value) || 1 !== preg_match(
            '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D',
            $value,
        )) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));

        return false !== $date && $date->format('Y-m-d\TH:i:s\Z') === $value;
    }

    /**
     * Parses a full stable SemVer tag without assigning precedence to build metadata.
     *
     * @param mixed $tag Candidate tag from explicit input or decoded API evidence.
     *
     * @return array{tag: string, major: string, parts: list<string>}|null Canonical numeric components, or null for unsupported evidence.
     */
    private static function version(mixed $tag): ?array
    {
        if (! is_string($tag) || strlen($tag) > 128 || 1 !== preg_match(
            '/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D',
            $tag,
            $matches,
        )) {
            return null;
        }

        return ['tag' => $tag, 'major' => $matches[1], 'parts' => array_slice($matches, 1, 3)];
    }

    /**
     * Compares numeric components as canonical decimal strings without integer overflow.
     *
     * @param array{parts: list<string>} $left  First parsed stable version.
     * @param array{parts: list<string>} $right Second parsed stable version.
     *
     * @return int Negative, zero or positive according to SemVer precedence.
     */
    private static function compare(array $left, array $right): int
    {
        for ($index = 0; $index < 3; ++$index) {
            $order = strlen($left['parts'][$index]) <=> strlen($right['parts'][$index]);
            $order = 0 === $order ? strcmp($left['parts'][$index], $right['parts'][$index]) : $order;
            if (0 !== $order) {
                return $order;
            }
        }

        return 0;
    }

    /**
     * Checks whether catalog evidence still includes the exact selected publication identity.
     *
     * @param list<array<string, mixed>> $releases Complete filtered catalog snapshot.
     * @param array<string, mixed>       $selected Verified release identity to retain.
     *
     * @return bool Whether both the release ID and tag remain present together.
     */
    private static function contains(array $releases, array $selected): bool
    {
        return array_any(
            $releases,
            static fn(array $release): bool => $release['id'] === $selected['id'] && $release['tag_name'] === $selected['tag_name'],
        );
    }

    /**
     * Accepts only a complete lowercase Git commit or object identifier.
     *
     * @param mixed $value Candidate identifier from explicit input or an API object.
     *
     * @return bool Whether the value is an exact forty-character hexadecimal identifier.
     */
    private static function sha(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/^[a-f0-9]{40}$/D', $value);
    }
}
