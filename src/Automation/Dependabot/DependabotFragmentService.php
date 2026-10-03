<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license https://opensource.org/licenses/MIT MIT License
 * @see https://github.com/php-fast-forward/changelog
 * @see https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Automation\Dependabot;

use FastForward\Changelog\Automation\Dependabot\Factory\DependabotFragmentResultFactoryInterface;
use FastForward\Changelog\Automation\Dependabot\Factory\DependabotInputFactoryInterface;
use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Factory\ChangesetFactoryInterface;
use FastForward\Changelog\Changeset\Renderer\ChangesetRendererInterface;
use FastForward\Changelog\Changeset\VersionImpact;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use Throwable;

/** Uses a trusted metadata snapshot without executing or checking out the untrusted PR head. */
final readonly class DependabotFragmentService implements DependabotFragmentServiceInterface
{
    /** Injects network, canonical changeset rendering and result boundaries; no host state is read. */
    public function __construct(private GitHubClientInterface $github, private ChangesetFactoryInterface $changesets, private ChangesetRendererInterface $renderer, private DependabotFragmentResultFactoryInterface $results, private DependabotInputFactoryInterface $inputs) {}

    /** Creates a missing fragment or reports exact-byte idempotency; any divergent file or stale head fails closed. */
    public function synchronize(ReleaseOptions $options, DependabotInput $input): DependabotFragmentResult
    {
        $id = 'dependabot-' . $input->pullRequest . '.md';
        $path = $options->fragmentDirectory . '/' . $id;
        $writing = false;
        try {
            $input = $this->inputs->create($input->pullRequest, $input->expectedHeadSha, $input->packageNames, $input->dependencyType, $input->ecosystem, $input->securityAlertNumbers, $input->includeDev, $input->includeActions);
            if (! GitHubEvidence::repository($options->repository)
                || 1 !== preg_match('~\A[.A-Za-z0-9_][.A-Za-z0-9_-]*(?:/[.A-Za-z0-9_][.A-Za-z0-9_-]*)*\z~', $options->fragmentDirectory)
                || [] !== array_intersect(explode('/', $options->fragmentDirectory), ['.', '..'])
                || ! GitHubEvidence::sha($input->expectedHeadSha) || $input->pullRequest < 1 || [] === $input->packageNames
            ) {
                return $this->results->create('refused', $path, null, ['Dependabot writes require an explicit repository, validated metadata and a canonical repository-relative fragment directory.']);
            }
            if ((! $input->includeDev && 'direct:development' === $input->dependencyType) || (! $input->includeActions && 'github-actions' === $input->ecosystem)) {
                return $this->results->create('filtered', $path);
            }
            if (! $input->includeDev && 'indirect' === $input->dependencyType) {
                return $this->results->create('refused', $path, null, ['Indirect dependency scope cannot be safely excluded as development metadata.']);
            }
            $repository = $options->repository;
            $endpoint = '/repos/' . $repository . '/pulls/' . $input->pullRequest;
            $pr = $this->github->request('GET', $endpoint);
            if (! $this->trustedPullRequest($pr, $repository, $input)) {
                return $this->results->create('refused', $path, null, ['An open same-repository Dependabot Bot PR at the supplied head is required.']);
            }
            $account = $this->github->request('GET', '/users/dependabot%5Bbot%5D');
            if (! GitHubEvidence::identity($account, 'dependabot[bot]', 'Bot', $pr['user']['id'])) {
                return $this->results->create('refused', $path, null, ['Dependabot account identity could not be verified.']);
            }
            $category = Category::Changed;
            if ([] !== $input->securityAlertNumbers) {
                if (! $this->securityProof($repository, $input)) {
                    return $this->results->create('refused', $path, null, ['Security classification requires current open GitHub alerts matching the trusted package metadata.']);
                }
                $category = Category::Security;
            }
            $packages = implode(', ', array_map(static fn(string $name): string => '`' . $name . '`', $input->packageNames));
            $description = ('pt-BR' === $options->locale ? 'Atualizar dependências: ' : 'Update dependencies: ') . $packages . '.';
            $contents = $this->renderer->render($this->changesets->create($id, $category, null, $input->pullRequest, 'dependabot[bot]', $description, VersionImpact::Patch));
            $readPath = GitHubEvidence::contentsPath($repository, $path, $input->expectedHeadSha);
            $existing = $this->github->request('GET', $readPath);
            if (null !== $existing) {
                if (GitHubEvidence::content($existing) === $contents) {
                    return $this->results->create('unchanged', $path);
                }
                return $this->results->create('refused', $path, null, ['An existing fragment differs from the deterministic output; manual or earlier content was preserved.']);
            }
            $fresh = $this->github->request('GET', $endpoint);
            if (! $this->trustedPullRequest($fresh, $repository, $input) || $fresh['head']['ref'] !== $pr['head']['ref'] || $fresh['user']['id'] !== $pr['user']['id']) {
                return $this->results->create('conflict', $path, null, ['The PR head changed before creation; no fragment was written.']);
            }
            $writing = true;
            $response = $this->github->request('PUT', explode('?ref=', $readPath)[0], [
                'message' => 'chore(changelog): add Dependabot fragment for #' . $input->pullRequest,
                'content' => base64_encode($contents), 'branch' => $pr['head']['ref'],
            ]);
            $commit = $response['commit']['sha'] ?? null;
            if (! is_string($commit) || ! GitHubEvidence::sha($commit)) {
                return $this->results->create('conflict', $path, null, ['GitHub did not confirm a created commit; inspect the branch before retrying.']);
            }
            if (($response['commit']['parents'][0]['sha'] ?? null) !== $input->expectedHeadSha || count($response['commit']['parents'] ?? []) !== 1) {
                return $this->results->create('conflict', $path, $commit, ['A fragment commit was created on a changed branch head; review that commit before retrying.']);
            }
            return $this->results->create('created', $path, $commit);
        } catch (Throwable) {
            return $this->results->create($writing ? 'conflict' : 'refused', $path, null, ['Dependabot fragment operation could not be verified; inspect the branch before retrying. Response details were withheld.']);
        }
    }

    /** Checks immutable creator metadata and the exact caller-provided head snapshot, rejecting fork writes. */
    private function trustedPullRequest(?array $pr, string $repository, DependabotInput $input): bool
    {
        return GitHubEvidence::pullRequest($pr, $repository, $input->pullRequest)
            && GitHubEvidence::identity($pr['user'] ?? null, 'dependabot[bot]', 'Bot')
            && $pr['head']['sha'] === $input->expectedHeadSha
            && ($pr['head']['ref'] ?? null) !== ($pr['base']['ref'] ?? null);
    }

    /** Verifies each trusted PR-associated alert is still open and matches an updated package/ecosystem. */
    private function securityProof(string $repository, DependabotInput $input): bool
    {
        $ecosystem = 'github-actions' === $input->ecosystem ? 'actions' : $input->ecosystem;
        foreach ($input->securityAlertNumbers as $number) {
            $alert = $this->github->request('GET', '/repos/' . $repository . '/dependabot/alerts/' . $number);
            if (null === $alert || ($alert['number'] ?? null) !== $number || ($alert['state'] ?? null) !== 'open'
                || ($alert['dependency']['package']['ecosystem'] ?? null) !== $ecosystem
                || ! in_array($alert['dependency']['package']['name'] ?? null, $input->packageNames, true)
                || ! is_string($alert['security_advisory']['ghsa_id'] ?? null)
                || 1 !== preg_match('/\AGHSA-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}\z/', $alert['security_advisory']['ghsa_id'])
            ) {
                return false;
            }
        }
        return true;
    }
}
