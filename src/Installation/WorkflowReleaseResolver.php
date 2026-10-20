<?php

declare(strict_types=1);

namespace FastForward\Changelog\Installation;

use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Version\PackageVersionResolverInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/** Binds installation defaults and caller bytes to a published stable product commit. */
final readonly class WorkflowReleaseResolver implements WorkflowReleaseResolverInterface
{
    private const string REPOSITORY = '/repos/php-fast-forward/changelog';

    /**
     * Injects a public read-only API boundary and deterministic runtime version metadata.
     *
     * @param GitHubClientInterface           $github   API client configured for the canonical public GitHub origin.
     * @param PackageVersionResolverInterface $versions Version of the executing package, independent from the consumer project.
     */
    public function __construct(
        private GitHubClientInterface $github,
        private PackageVersionResolverInterface $versions,
    ) {}

    /**
     * Verifies release state and tag identity before reading both YAML files at the immutable commit.
     *
     * @param  ?string                                                                             $reference Full stable product tag or null to use the executing stable package version.
     * @return array{ref:string,sha:string,caller:string,inputs:array<string,array<string,mixed>>} Verified installation contract.
     * @throws InvalidArgumentException                                                            When development, channel, prerelease or malformed references are supplied.
     * @throws RuntimeException                                                                    When release state, immutable objects or supported YAML structures cannot be proved.
     */
    public function resolve(?string $reference = null): array
    {
        $version = $reference ?? $this->versions->resolve();
        if (1 !== preg_match('/\Av?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/', $version)) {
            throw new InvalidArgumentException(
                'Select a published full stable tag with --ref=vX.Y.Z; development versions and moving channels are not installation evidence.',
            );
        }
        $tag = 'v' . ltrim($version, 'v');
        $release = $this->github->request('GET', self::REPOSITORY . '/releases/tags/' . $tag);
        if (null === $release || ($release['tag_name'] ?? null) !== $tag
            || ($release['draft'] ?? null) !== false || ($release['prerelease'] ?? null) !== false) {
            throw new RuntimeException('The selected product tag has no verified published stable GitHub Release.');
        }
        $ref = $this->github->request('GET', self::REPOSITORY . '/git/ref/tags/' . $tag);
        if (($ref['ref'] ?? null) !== 'refs/tags/' . $tag) {
            throw new RuntimeException('The selected product tag identity could not be verified.');
        }
        $sha = $this->commit($ref['object'] ?? null);
        $caller = $this->file('resources/workflows/changelog.yml', $sha);
        $workflow = Yaml::parse($this->file('.github/workflows/changelog.yml', $sha));
        $inputs = $workflow['on']['workflow_call']['inputs'] ?? null;
        $template = Yaml::parse($caller);
        $uses = $template['jobs']['changelog']['uses'] ?? null;
        if (! is_array($inputs) || ! is_string($uses)
            || ! str_starts_with($uses, 'php-fast-forward/changelog/.github/workflows/changelog.yml@')
            || ! is_array($template['on'] ?? null)) {
            throw new RuntimeException(
                'The selected release does not contain a supported lifecycle caller and input contract.',
            );
        }
        foreach ($inputs as $name => $input) {
            if (! is_string($name) || ! is_array($input)
                || ! in_array($input['type'] ?? null, ['string', 'boolean', 'number'], true)) {
                throw new RuntimeException('The selected release has an unsupported workflow input contract.');
            }
        }

        return ['ref' => $tag, 'sha' => $sha, 'caller' => $caller, 'inputs' => $inputs];
    }

    /**
     * Peels only canonical tag objects, rejecting cycles, excessive depth and non-commit targets.
     *
     * @param  mixed            $object API object descriptor whose type and complete SHA must be verified.
     * @return string           Complete released commit SHA, never a moving ref or server-provided URL.
     * @throws RuntimeException When object identities, graph bounds or target types are invalid.
     */
    private function commit(mixed $object): string
    {
        $seen = [];
        for ($depth = 0; $depth < 8; ++$depth) {
            $sha = is_array($object) ? ($object['sha'] ?? null) : null;
            if (! is_string($sha) || 1 !== preg_match('/\A[a-f0-9]{40}\z/', $sha) || isset($seen[$sha])) {
                throw new RuntimeException('The product tag has invalid or cyclic immutable object evidence.');
            }
            $seen[$sha] = true;
            if ('commit' === ($object['type'] ?? null)) {
                return $sha;
            }
            if ('tag' !== ($object['type'] ?? null)) {
                throw new RuntimeException('The product tag must identify a commit.');
            }
            $tag = $this->github->request('GET', self::REPOSITORY . '/git/tags/' . $sha);
            if (($tag['sha'] ?? null) !== $sha) {
                throw new RuntimeException('An annotated product tag returned a different object identity.');
            }
            $object = $tag['object'] ?? null;
        }

        throw new RuntimeException('The product tag exceeds the supported annotation depth.');
    }

    /**
     * Decodes one bounded regular Git blob from a constant path at the verified commit.
     *
     * @param  string           $path Product-owned constant repository path, never a URL supplied by API content.
     * @param  string           $sha  Complete released commit identity used in every content lookup.
     * @return string           Exact UTF-8 YAML bytes whose Git blob identity matches the response.
     * @throws RuntimeException When the file is absent, unsafe, malformed, oversized or does not match its blob SHA.
     */
    private function file(string $path, string $sha): string
    {
        $file = $this->github->request('GET', self::REPOSITORY . '/contents/' . $path . '?ref=' . $sha);
        $encoded = $file['content'] ?? null;
        if (($file['type'] ?? null) !== 'file' || ($file['path'] ?? null) !== $path
            || ($file['encoding'] ?? null) !== 'base64' || ! is_string($encoded) || strlen($encoded) > 150_000) {
            throw new RuntimeException('The selected release is missing a bounded regular workflow resource.');
        }
        $bytes = base64_decode(str_replace(["\r", "\n"], '', $encoded), true);
        if (false === $bytes || strlen($bytes) > 100_000
            || ($file['sha'] ?? null) !== sha1('blob ' . strlen($bytes) . "\0" . $bytes)
            || 1 !== preg_match('//u', $bytes)) {
            throw new RuntimeException('The workflow resource bytes do not match their immutable Git blob.');
        }

        return $bytes;
    }
}
