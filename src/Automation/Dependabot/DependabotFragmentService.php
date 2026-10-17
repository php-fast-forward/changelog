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

use FastForward\Changelog\Automation\Dependabot\Factory\DependabotInputFactoryInterface;
use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\Renderer\ChangesetRendererInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Version\VersionImpact;
use RuntimeException;
use Throwable;

/** Uses a trusted metadata snapshot without executing or checking out the untrusted PR head. */
final readonly class DependabotFragmentService implements DependabotFragmentServiceInterface
{
    /**
     * Injects network, canonical changeset rendering and result boundaries; no host state is read.
     *
     * @param GitHubClientInterface           $github   Authenticated, replaceable GitHub API boundary for scoped reads and writes.
     * @param ChangesetRendererInterface      $renderer Canonical fragment serializer used for new output and exact
     *                                                  legacy-byte comparisons.
     * @param DependabotInputFactoryInterface $inputs   Constructor for validated deterministic dependency metadata with
     *                                                  deduplicated sorted packages and alerts.
     */
    public function __construct(
        private GitHubClientInterface $github,
        private ChangesetRendererInterface $renderer,
        private DependabotInputFactoryInterface $inputs,
    ) {}

    /**
     * Creates a missing fragment or reports exact-byte idempotency; any divergent file or stale head fails closed.
     *
     * @param  ReleaseOptions           $options Trusted consumer paths, rendering settings and repository selection for this
     *                                           operation.
     * @param  DependabotInput          $input   Validated operation settings and the immutable remote snapshot selected by the
     *                                           caller.
     * @return DependabotFragmentResult Created/unchanged/filtered/refused/conflict outcome without overwriting existing
     *                                  manual bytes.
     */
    public function synchronize(ReleaseOptions $options, DependabotInput $input): DependabotFragmentResult
    {
        $id = 'dependabot-' . $input->pullRequest . '.md';
        $path = $options->fragmentDirectory . '/' . $id;
        $writing = false;

        try {
            $input = $this->inputs->create(
                $input->pullRequest,
                $input->expectedHeadSha,
                $input->packageNames,
                $input->dependencyType,
                $input->ecosystem,
                $input->securityAlertNumbers,
                $input->includeDev,
                $input->includeActions,
            );
            $rejection = $this->metadataRejection($options, $input, $path);

            if (null !== $rejection) {
                return $rejection;
            }
            $repository = $options->repository;
            $endpoint = '/repos/' . $repository . '/pulls/' . $input->pullRequest;
            $pr = $this->github->request('GET', $endpoint);

            if (! $this->trustedPullRequest($pr, $repository, $input)) {
                return new DependabotFragmentResult(
                    status: 'refused',
                    path: $path,
                    commitSha: null,
                    diagnostics: ['An open same-repository Dependabot Bot PR at the supplied head is required.'],
                );
            }
            $account = $this->github->request('GET', '/users/dependabot%5Bbot%5D');

            if (! GitHubEvidence::identity($account, 'dependabot[bot]', 'Bot', $pr['user']['id'])) {
                return new DependabotFragmentResult(
                    status: 'refused',
                    path: $path,
                    commitSha: null,
                    diagnostics: ['Dependabot account identity could not be verified.'],
                );
            }
            $category = Category::Changed;

            if ([] !== $input->securityAlertNumbers) {
                if (! $this->securityProof($repository, $input)) {
                    return new DependabotFragmentResult('refused', $path, null, [
                        'Security classification requires current open GitHub alerts matching the trusted package metadata.',
                    ]);
                }
                $category = Category::Security;
            }
            $packages = implode(
                ', ',
                array_map(static fn(string $name): string => '`' . $name . '`', $input->packageNames),
            );
            $legacyDescription = ('pt-BR' === $options->locale ? 'Atualizar dependências: ' : 'Update dependencies: ') . $packages . '.';
            $title = $this->literalTitle($pr['title'] ?? null);
            $description = null === $title ? $legacyDescription : $title . "\n\n" . $legacyDescription;
            $contents = $this->renderFragment($id, $category, $input, $description);
            $readPath = GitHubEvidence::contentsPath($repository, $path, $input->expectedHeadSha);
            $existing = $this->github->request('GET', $readPath);

            if (null !== $existing) {
                $existingContents = GitHubEvidence::content($existing);

                if ($existingContents === $contents
                    || ($description !== $legacyDescription
                        && $existingContents === $this->renderFragment(
                            $id,
                            $category,
                            $input,
                            $legacyDescription,
                        ))
                ) {
                    return new DependabotFragmentResult('unchanged', $path, null, []);
                }

                return new DependabotFragmentResult('refused', $path, null, [
                    'An existing fragment differs from the deterministic output; manual or earlier content was preserved.',
                ]);
            }
            $fresh = $this->github->request('GET', $endpoint);

            if (! $this->trustedPullRequest(
                $fresh,
                $repository,
                $input,
            ) || $fresh['head']['ref'] !== $pr['head']['ref'] || $fresh['user']['id'] !== $pr['user']['id']
                || $this->literalTitle($fresh['title'] ?? null) !== $title
            ) {
                return new DependabotFragmentResult(
                    status: 'conflict',
                    path: $path,
                    commitSha: null,
                    diagnostics: ['The PR snapshot or metadata changed before creation; no fragment was written.'],
                );
            }
            $writing = true;

            return $this->createFragment($input, $path, $readPath, $contents, $pr['head']['ref']);
        } catch (Throwable) {
            return new DependabotFragmentResult($writing ? 'conflict' : 'refused', $path, null, [
                'Dependabot fragment operation could not be verified; inspect the branch before retrying. Response details were withheld.',
            ]);
        }
    }

    /**
     * Applies trusted metadata and inclusion policy before any remote identity or content request.
     *
     * @param  ReleaseOptions            $options Trusted consumer paths, rendering settings and repository selection for this
     *                                            operation.
     * @param  DependabotInput           $input   Validated operation settings and the immutable remote snapshot selected by the
     *                                            caller.
     * @param  string                    $path    Canonical repository-relative managed path selected for this operation.
     * @return ?DependabotFragmentResult Controlled refusal or filtering result, or null when metadata policy permits
     *                                   remote inspection.
     */
    private function metadataRejection(
        ReleaseOptions $options,
        DependabotInput $input,
        string $path,
    ): ?DependabotFragmentResult {
        if (! GitHubEvidence::repository($options->repository)
            || 1 !== preg_match(
                '~\A[.A-Za-z0-9_][.A-Za-z0-9_-]*(?:/[.A-Za-z0-9_][.A-Za-z0-9_-]*)*\z~',
                $options->fragmentDirectory,
            )
            || [] !== array_intersect(explode('/', $options->fragmentDirectory), ['.', '..'])
            || ! GitHubEvidence::sha(
                $input->expectedHeadSha,
            ) || $input->pullRequest < 1 || [] === $input->packageNames
        ) {
            return new DependabotFragmentResult('refused', $path, null, [
                'Dependabot writes require an explicit repository, validated metadata and a canonical repository-relative fragment directory.',
            ]);
        }

        if ((! $input->includeDev && 'direct:development' === $input->dependencyType)
            || (! $input->includeActions && 'github-actions' === $input->ecosystem)) {
            return new DependabotFragmentResult('filtered', $path, null, []);
        }

        if (! $input->includeDev && 'indirect' === $input->dependencyType) {
            return new DependabotFragmentResult(
                status: 'refused',
                path: $path,
                commitSha: null,
                diagnostics: ['Indirect dependency scope cannot be safely excluded as development metadata.'],
            );
        }

        return null;
    }

    /**
     * Creates one missing file and verifies that its sole parent is the approved Dependabot head.
     *
     * @param  DependabotInput          $input    Validated operation settings and the immutable remote snapshot selected by the
     *                                            caller.
     * @param  string                   $path     Canonical repository-relative managed path selected for this operation.
     * @param  string                   $readPath Encoded GitHub Contents endpoint pinned to the approved immutable PR head.
     * @param  string                   $contents Exact validated fragment bytes to create without modifying an existing file.
     * @param  string                   $branch   Validated branch name used for the exact remote reference or release line.
     * @return DependabotFragmentResult Confirmed created commit, or conflict outcome requiring inspection rather than
     *                                  an automatic retry.
     * @throws RuntimeException         When the API request cannot be completed; synchronization retains a controlled refusal
     *                                  or conflict.
     */
    private function createFragment(
        DependabotInput $input,
        string $path,
        string $readPath,
        string $contents,
        string $branch,
    ): DependabotFragmentResult {
        $response = $this->github->request('PUT', explode('?ref=', $readPath)[0], [
            'message' => 'chore(changelog): add Dependabot fragment for #' . $input->pullRequest,
            'content' => base64_encode($contents), 'branch' => $branch,
        ]);
        $commit = $response['commit']['sha'] ?? null;

        if (! is_string($commit) || ! GitHubEvidence::sha($commit)) {
            return new DependabotFragmentResult(
                status: 'conflict',
                path: $path,
                commitSha: null,
                diagnostics: ['GitHub did not confirm a created commit; inspect the branch before retrying.'],
            );
        }

        if (($response['commit']['parents'][0]['sha'] ?? null) !== $input->expectedHeadSha || count(
            $response['commit']['parents'] ?? [],
        ) !== 1) {
            return new DependabotFragmentResult(
                status: 'conflict',
                path: $path,
                commitSha: $commit,
                diagnostics: [
                    'A fragment commit was created on a changed branch head; review that commit before retrying.',
                ],
            );
        }

        return new DependabotFragmentResult('created', $path, $commit, []);
    }

    /**
     * Renders the same validated identity and patch metadata for current output and exact legacy-byte comparison.
     *
     * @param  string          $id          Deterministic direct fragment filename, including its Markdown extension.
     * @param  Category        $category    Change category, including security only after current matching alert proof.
     * @param  DependabotInput $input       Validated operation settings and the immutable remote snapshot selected by the
     *                                      caller.
     * @param  string          $description User-facing fragment prose already selected from verified metadata and escaped
     *                                      presentation.
     * @return string          Canonical fragment Markdown with verified PR association and patch impact.
     */
    private function renderFragment(
        string $id,
        Category $category,
        DependabotInput $input,
        string $description,
    ): string {
        return $this->renderer->render(new Changeset(
            id: $id,
            category: $category,
            issue: null,
            pullRequest: $input->pullRequest,
            author: 'dependabot[bot]',
            description: $description,
            type: VersionImpact::Patch,
        ));
    }

    /**
     * Keeps a bounded UTF-8 title as one literal paragraph without changing ordinary versions, paths or punctuation.
     *
     * HTML, Markdown delimiters, block markers, mentions and autolinks cannot introduce active presentation structure.
     *
     * @param  mixed   $title Single-line PR title or supported version-placeholder template; never publication authority.
     * @return ?string Bounded inert UTF-8 paragraph, or null when no acceptable title can be represented.
     */
    private function literalTitle(mixed $title): ?string
    {
        if (! is_string($title) || strlen($title) > 1024 || 1 !== preg_match('//u', $title)) {
            return null;
        }

        $title = trim(preg_replace('/[\x00-\x20\x7f\p{Z}]+/u', ' ', $title));

        if ('' === $title) {
            return null;
        }

        $title = htmlspecialchars($title, ENT_NOQUOTES, 'UTF-8');
        $title = strtr($title, [
            '\\' => '\\\\', '`' => '\\`', '*' => '\\*', '_' => '\\_',
            '[' => '\\[', ']' => '\\]', '~' => '\\~', '#' => '\\#', '$' => '\\$',
        ]);
        $title = preg_replace('~\b([a-z][a-z0-9+.-]*):(?=//)~i', '$1&#58;', $title);
        $title = preg_replace('/\b(www)\./i', '$1&#46;', $title);
        $title = preg_replace(
            '/\A(?:([0-9]{1,9})([.)])(?= )|([-+])(?= )|(-)(?=(?: *-){2,}\z))/',
            '$1\\\\$2$3$4',
            $title,
        );

        return str_replace('@', '&#64;', $title);
    }

    /**
     * Checks immutable creator metadata and the exact caller-provided head snapshot, rejecting fork writes.
     *
     * @param  ?array          $pr         GitHub pull-request snapshot whose repository, branch and creator evidence is checked.
     * @param  string          $repository Explicit GitHub owner/name used to construct repository-scoped API endpoints.
     * @param  DependabotInput $input      Validated operation settings and the immutable remote snapshot selected by the
     *                                     caller.
     * @return bool            Whether the live open same-repository PR belongs to Dependabot at the exact supplied head.
     */
    private function trustedPullRequest(?array $pr, string $repository, DependabotInput $input): bool
    {
        return GitHubEvidence::pullRequest($pr, $repository, $input->pullRequest)
            && GitHubEvidence::identity($pr['user'] ?? null, 'dependabot[bot]', 'Bot')
            && $pr['head']['sha'] === $input->expectedHeadSha
            && ($pr['head']['ref'] ?? null) !== ($pr['base']['ref'] ?? null);
    }

    /**
     * Verifies each trusted PR-associated alert is still open and matches an updated package/ecosystem.
     *
     * @param  string           $repository Explicit GitHub owner/name used to construct repository-scoped API endpoints.
     * @param  DependabotInput  $input      Validated operation settings and the immutable remote snapshot selected by the
     *                                      caller.
     * @return bool             Whether every supplied alert remains open and matches the verified package/ecosystem metadata.
     * @throws RuntimeException When the API request cannot be completed; synchronization retains a controlled refusal
     *                          or conflict.
     */
    private function securityProof(string $repository, DependabotInput $input): bool
    {
        $ecosystem = 'github-actions' === $input->ecosystem ? 'actions' : $input->ecosystem;

        foreach ($input->securityAlertNumbers as $number) {
            $alert = $this->github->request('GET', '/repos/' . $repository . '/dependabot/alerts/' . $number);

            if (null === $alert || ($alert['number'] ?? null) !== $number || ($alert['state'] ?? null) !== 'open'
                || ($alert['dependency']['package']['ecosystem'] ?? null) !== $ecosystem
                || ! in_array($alert['dependency']['package']['name'] ?? null, $input->packageNames, true)
                || ! is_string($alert['security_advisory']['ghsa_id'] ?? null)
                || 1 !== preg_match(
                    '/\AGHSA-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}\z/',
                    $alert['security_advisory']['ghsa_id'],
                )
            ) {
                return false;
            }
        }

        return true;
    }
}
