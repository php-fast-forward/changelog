<?php

declare(strict_types=1);

namespace FastForward\Changelog\Installation;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/** Owns conservative project adoption while keeping release discovery and local I/O replaceable. */
final readonly class ProjectInstaller implements ProjectInstallerInterface
{
    private const string DESTINATION = '.github/workflows/changelog.yml';
    private const string WORKFLOW = 'php-fast-forward/changelog/.github/workflows/changelog.yml@';

    /**
     * Captures replaceable boundaries without observing a project or discovering a release.
     *
     * @param InstallationFilesystemInterface  $filesystem Read-only inventories and create-only caller publication.
     * @param WorkflowReleaseResolverInterface $releases   Published workflow identity and exact input contract discovery.
     * @param ProjectSkillInstallerInterface   $skills     Standard Composer manager setup, guarded preview and explicit application.
     */
    public function __construct(
        private InstallationFilesystemInterface $filesystem,
        private WorkflowReleaseResolverInterface $releases,
        private ProjectSkillInstallerInterface $skills,
    ) {}

    /**
     * Classifies local YAML semantically, preserving unrelated CI and rejecting competing release integrations.
     *
     * @param  string                                                                                                                                       $cwd Explicit consumer directory, never inferred from the CPX package cache.
     * @return array{root:string,base_branch:string,workflows:list<array{path:string,kind:string,reference:?string}>,state:string,diagnostics:list<string>} Local assessment.
     * @throws RuntimeException                                                                                                                             When a safe Git worktree or exact workflow bytes cannot be established.
     */
    public function inspect(string $cwd = '.'): array
    {
        $snapshot = $this->filesystem->inspect($cwd);

        return $this->assessment($snapshot);
    }

    /**
     * Plans both outputs first, preserves existing callers byte-for-byte, and reports partial success honestly.
     *
     * @param  string                                                                                                                                $cwd              Explicit existing Git consumer directory.
     * @param  ?string                                                                                                                               $reference        Optional selected published workflow tag.
     * @param  array<string,mixed>                                                                                                                   $settings         Literal reusable input overrides selected by the caller.
     * @param  bool                                                                                                                                  $skill            Whether to configure the Composer skill manager after verifying ownership boundaries.
     * @param  bool                                                                                                                                  $dryRun           Whether to suppress all filesystem mutation and lock acquisition.
     * @param  ?string                                                                                                                               $expectedWorkflow SHA-256 of JSON_THROW_ON_ERROR encoded preview workflow; mismatch blocks every write.
     * @param  ?string                                                                                                                               $expectedSkill    Reviewed skill metadata/command fingerprint; mismatch blocks every write.
     * @return array{state:string,workflow:array{state:string,path:?string,content:?string,reference:?string},skill:?array,diagnostics:list<string>} Reviewed plan or result.
     * @throws InvalidArgumentException                                                                                                              When settings cannot satisfy the selected published input contract.
     * @throws RuntimeException                                                                                                                      When required repository or release evidence cannot be verified.
     */
    public function run(
        string $cwd,
        ?string $reference,
        array $settings,
        bool $skill = true,
        bool $dryRun = false,
        ?string $expectedWorkflow = null,
        ?string $expectedSkill = null,
    ): array {
        $snapshot = $this->filesystem->inspect($cwd);
        $inspection = $this->assessment($snapshot);
        $existing = array_find($inspection['workflows'], static fn(array $item): bool => 'changelog' === $item['kind']);
        $result = ['state' => 'existing', 'workflow' => ['state' => $inspection['state'],
            'path' => $existing['path'] ?? self::DESTINATION, 'content' => null,
            'reference' => $existing['reference'] ?? null], 'skill' => null, 'diagnostics' => $inspection['diagnostics']];
        if ('conflict' === $inspection['state']) {
            $result['state'] = 'blocked';

            return $result;
        }

        if ($skill) {
            $preview = $this->skills->run($snapshot['root'], dryRun: true);
            $result['skill'] = $preview;
            if (! in_array($preview['state'], ['planned', 'current'], true)
                || (null !== $expectedSkill && ! hash_equals($expectedSkill, $preview['fingerprint']))) {
                $result['state'] = 'blocked';
                $result['diagnostics'][] = 'Skill setup is blocked or changed after confirmation; no project files were changed.';
                $result['diagnostics'] = [...$result['diagnostics'], ...($preview['diagnostics'] ?? [])];

                return $result;
            }
        }

        if ('absent' === $inspection['state']) {
            $release = $this->releases->resolve($reference);
            $settings['base-branch'] ??= $snapshot['base_branch'];
            $content = $this->caller($release, $settings);
            $result['workflow'] = ['state' => 'planned', 'path' => self::DESTINATION,
                'content' => $content, 'reference' => $release['ref']];
            $result['state'] = 'planned';
        }

        if (null !== $expectedWorkflow && ! hash_equals(
            $expectedWorkflow,
            hash('sha256', json_encode($result['workflow'], JSON_THROW_ON_ERROR)),
        )) {
            $result['state'] = 'blocked';
            $result['diagnostics'][] = 'The workflow plan changed after confirmation; review the new plan before installing.';

            return $result;
        }

        if ($dryRun) {
            return $result;
        }

        if ('planned' === $result['workflow']['state']) {
            $this->filesystem->create(
                $snapshot['root'],
                self::DESTINATION,
                $result['workflow']['content'],
                $snapshot['files'],
            );
            $result['workflow']['state'] = 'installed';
            $result['state'] = 'installed';
        }

        if ($skill && 'planned' === $result['skill']['state']) {
            try {
                $result['skill'] = $this->skills->run(
                    $snapshot['root'],
                    expectedFingerprint: $result['skill']['fingerprint'],
                );
                if (! in_array($result['skill']['state'], ['installed', 'current'], true)) {
                    $result['state'] = 'installed' === $result['workflow']['state'] ? 'partial' : 'blocked';
                    $result['diagnostics'][] = 'Skill setup did not complete; inspect its diagnostics before retrying.';
                }
            } catch (Throwable $failure) {
                $result['state'] = 'installed' === $result['workflow']['state'] ? 'partial' : 'blocked';
                $result['diagnostics'][] = 'The workflow was preserved, but skill installation did not complete: ' . $failure->getMessage();
            }
        }

        return $result;
    }

    /**
     * Derives installation ownership from exact local workflow bytes; malformed files always require review.
     *
     * @param  array{root:string,base_branch:string,files:array<string,string>,diagnostics:list<string>}                                                    $snapshot Local inventory.
     * @return array{root:string,base_branch:string,workflows:list<array{path:string,kind:string,reference:?string}>,state:string,diagnostics:list<string>} Classified evidence.
     */
    private function assessment(array $snapshot): array
    {
        $workflows = $diagnostics = [];
        $matches = 0;
        $lifecycleCallers = 0;
        $productReferences = [];
        $conflict = false;
        foreach ($snapshot['files'] as $path => $bytes) {
            try {
                $document = Yaml::parse($bytes, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
                if (! is_array($document) || ! is_array($document['jobs'] ?? null)
                    || [] === $document['jobs'] || array_is_list($document['jobs'])
                    || array_any($document['jobs'], static fn(mixed $job): bool => ! is_array($job))) {
                    throw new RuntimeException('A workflow must declare a jobs mapping.');
                }
                [$references, $foreign, $callers] = $this->integrations($document);
                $kind = [] !== $references ? 'changelog' : ($foreign ? 'foreign-release' : 'unrelated');
                $matches += [] !== $references ? 1 : 0;
                $lifecycleCallers += $callers;
                $productReferences = [...$productReferences, ...$references];
                $ambiguous = $foreign || count(
                    $references,
                ) > 1 || (self::DESTINATION === $path && 'unrelated' === $kind);
                $conflict = $conflict || $ambiguous;
                if ($ambiguous) {
                    $diagnostics[] = $path . ' contains competing or ambiguous release automation; review migration manually.';
                }
                $workflows[] = ['path' => $path, 'kind' => $kind, 'reference' => $references[0] ?? null];
            } catch (ParseException|RuntimeException $failure) {
                $conflict = true;
                $diagnostics[] = $path . ' cannot be safely interpreted: ' . $failure->getMessage();
                $workflows[] = ['path' => $path, 'kind' => 'invalid', 'reference' => null];
            }
        }
        if ($lifecycleCallers > 1) {
            $conflict = true;
            $diagnostics[] = 'Multiple full Fast Forward Changelog lifecycle callers already exist; no integration was selected or replaced.';
        }
        if (count(array_unique($productReferences)) > 1) {
            $conflict = true;
            $diagnostics[] = 'Fast Forward Changelog components use different references; review their selected versions manually.';
        }
        if (! $conflict && $matches > 1) {
            foreach ($workflows as $workflow) {
                if ('changelog' === $workflow['kind']) {
                    $diagnostics[] = $workflow['path'] . ' already contains Fast Forward Changelog integration at '
                        . $workflow['reference'] . '; preserved without changes.';
                }
            }
        }

        return ['root' => $snapshot['root'], 'base_branch' => $snapshot['base_branch'], 'workflows' => $workflows,
            'state' => $conflict ? 'conflict' : ($matches > 0 ? 'existing' : 'absent'),
            'diagnostics' => [...$snapshot['diagnostics'], ...$diagnostics]];
    }

    /**
     * Walks semantic uses/run fields, recognizing this repository's public workflows and Docker Actions.
     *
     * @param  array<mixed>                 $node Parsed YAML mapping or sequence.
     * @return array{list<string>,bool,int} Distinct product references, competing release evidence and full lifecycle caller count.
     */
    private function integrations(array $node): array
    {
        $references = [];
        $foreign = false;
        $callers = 0;
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                [$nested, $competing, $nestedCallers] = $this->integrations($value);
                $references = [...$references, ...$nested];
                $foreign = $foreign || $competing;
                $callers += $nestedCallers;
            } elseif ('uses' === $key && is_string($value)) {
                if (1 === preg_match(
                    '~\Aphp-fast-forward/changelog/\.github/(?:workflows/[^@]+|actions/[^@]+)@([^\s]+)\z~i',
                    $value,
                    $match,
                )) {
                    $references[] = $match[1];
                    $callers += str_starts_with(strtolower($value), self::WORKFLOW) ? 1 : 0;
                } elseif (1 === preg_match(
                    '~\A(?:changesets/action|softprops/action-gh-release|release-drafter/release-drafter|googleapis/release-please-action|release-it/[^@]+)@~i',
                    $value,
                )) {
                    $foreign = true;
                } elseif (str_starts_with(strtolower($value), 'php-fast-forward/changelog/')) {
                    $foreign = true;
                }
            } elseif ('run' === $key && is_string($value) && 1 === preg_match(
                '~(?:\bchangeset(?:s)?\b|\bgh\s+release\s+create\b|\brelease-please\b)~i',
                $value,
            )) {
                $foreign = true;
            }
        }

        return [array_values(array_unique($references)), $foreign, $callers];
    }

    /**
     * Renders the selected release's caller with its verified commit pin and literal input overrides.
     *
     * @param  array{ref:string,sha:string,caller:string,inputs:array<string,array<string,mixed>>} $release  Verified release contract.
     * @param  array<string,mixed>                                                                 $settings Explicit selected reusable input overrides.
     * @return string                                                                              Complete YAML with the release tag as a comment, unquoted on and non-default overrides.
     * @throws InvalidArgumentException                                                            When an input or branch cannot be represented as a safe literal.
     * @throws RuntimeException                                                                    When the selected caller does not contain one expected reusable job.
     */
    private function caller(array $release, array $settings): string
    {
        $overrides = $this->settings($release['inputs'], $settings);
        $branch = $settings['base-branch'];
        if (! is_string($branch) || '' === $branch || 'HEAD' === $branch || str_starts_with($branch, '-')
            || str_ends_with($branch, '.')
            || str_contains($branch, '..') || str_contains($branch, '@{')
            || 1 === preg_match('~[\x00-\x20\x7f\~^:?*\[\\\\]|\A/|/\z|//|(?:\A|/)\.|\.lock(?:/|\z)~', $branch)) {
            throw new InvalidArgumentException('base-branch must be one literal Git branch name.');
        }

        $document = Yaml::parse($release['caller'], Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        $jobs = is_array($document) && is_array($document['jobs'] ?? null) ? $document['jobs'] : [];
        $keys = array_keys(array_filter($jobs, static fn(mixed $job): bool => is_array($job)
            && is_string($job['uses'] ?? null) && str_starts_with($job['uses'], self::WORKFLOW)));
        if (1 !== count($keys) || ! is_array($document['on'] ?? null)) {
            throw new RuntimeException('The selected release does not provide one supported consumer caller.');
        }

        $key = $keys[0];
        $document['jobs'][$key]['uses'] = self::WORKFLOW . $release['sha'];
        unset($document['jobs'][$key]['with']);
        if ([] !== $overrides) {
            $document['jobs'][$key]['with'] = $overrides;
        }
        foreach (['pull_request', 'pull_request_target', 'push'] as $event) {
            if (isset($document['on'][$event])) {
                $document['on'][$event]['branches'] = [strtr($branch, ['+' => '\\+', '!' => '\\!'])];
            }
        }

        $yaml = Yaml::dump($document, 8, 2);
        $yaml = preg_replace('/^\x27on\x27:/m', 'on:', $yaml);
        $yaml = preg_replace(
            '/^(    uses: ' . preg_quote($document['jobs'][$key]['uses'], '/') . ')$/m',
            '$1 # ' . $release['ref'],
            $yaml,
        );

        return preg_replace('/\n(?=(?:on|permissions|jobs):)/', "\n\n", $yaml);
    }

    /**
     * Rejects unknown names, type mismatches and expression injection, emitting only actual contract overrides.
     *
     * @param  array<string,array<string,mixed>> $inputs   Selected published workflow input declarations.
     * @param  array<string,mixed>               $settings Explicit literal choices, including the selected base branch.
     * @return array<string,mixed>               Valid non-default overrides in input order supplied by the caller.
     * @throws InvalidArgumentException          When a setting is unknown, wrongly typed or expression-bearing.
     */
    private function settings(array $inputs, array $settings): array
    {
        $overrides = [];
        foreach ($settings as $name => $value) {
            $input = $inputs[$name] ?? null;
            $valid = is_array($input) && match ($input['type'] ?? 'string') {
                'boolean' => is_bool($value),
                'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
                'string' => is_string($value) && ! str_contains($value, '${{') && ! str_contains($value, "\0"),
                default => false,
            };
            if (! $valid) {
                throw new InvalidArgumentException('Unknown workflow input or invalid literal value: ' . $name);
            }
            if (! array_key_exists('default', $input) || $input['default'] !== $value) {
                $overrides[$name] = $value;
            }
        }
        foreach ($inputs as $name => $input) {
            if (($input['required'] ?? false) && ! array_key_exists($name, $settings) && ! array_key_exists(
                'default',
                $input,
            )) {
                throw new InvalidArgumentException('A required workflow input was not provided: ' . $name);
            }
        }

        return $overrides;
    }
}
